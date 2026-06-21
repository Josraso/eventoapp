<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

Auth::adminCheck('superadmin', 'admin');
$base = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : getSetting('app_base_url'), '/');

// ── MARCAR/DESMARCAR ENTRADA MANUALMENTE ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $entId  = (int)($_POST['entrada_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (Auth::adminRole() !== 'superadmin') {
        $stOwn = db()->prepare('SELECT COUNT(*) FROM entradas e JOIN eventos ev ON ev.id=e.evento_id WHERE e.id=? AND ev.admin_id=?');
        $stOwn->execute([$entId, Auth::adminId()]);
        if ((int)$stOwn->fetchColumn() === 0) {
            flash('error', 'No tienes permiso sobre esta entrada.');
            header('Location: ' . $base . '/admin/inscritos/index.php'); exit;
        }
    }

    if ($action === 'marcar') {
        db()->prepare('UPDATE entradas SET usado=1, usado_at=NOW(), usado_por=? WHERE id=?')
           ->execute([Auth::adminId(), $entId]);
        flash('ok', 'Entrada marcada como entrada.');
    } elseif ($action === 'desmarcar') {
        db()->prepare('UPDATE entradas SET usado=0, usado_at=NULL, usado_por=NULL WHERE id=?')
           ->execute([$entId]);
        flash('ok', 'Entrada desmarcada.');
    }

    header('Location: ' . $base . '/admin/inscritos/index.php?' . http_build_query(array_filter([
        'evento_id' => $_POST['evento_id'] ?? '',
        'estado'    => $_POST['estado_actual'] ?? '',
        'q'         => $_POST['q_actual'] ?? '',
    ])));
    exit;
}

$pageTitle = 'Inscritos';
require_once __DIR__ . '/../_header.php';

// ── FILTROS ────────────────────────────────────────────────────────────────────
$eventoFiltro = (int)($_GET['evento_id'] ?? 0);
$estadoFiltro = $_GET['estado'] ?? ''; // entrado | no_entrado
$q            = trim($_GET['q'] ?? '');
$pagina       = max(1, (int)($_GET['p'] ?? 1));
$perPage      = 40;

$esSuperAdmin = Auth::adminRole() === 'superadmin';

$where  = ["i.estado_pago='pagado'"];
$params = [];

if (!$esSuperAdmin) { $where[] = 'ev.admin_id=?'; $params[] = Auth::adminId(); }
if ($eventoFiltro) { $where[] = 'e.evento_id=?'; $params[] = $eventoFiltro; }
if ($estadoFiltro === 'entrado')    $where[] = 'e.usado=1';
if ($estadoFiltro === 'no_entrado') $where[] = 'e.usado=0';
if ($q) {
    $qlike = '%' . $q . '%';
    $where[] = '(e.nombre_asistente LIKE ? OR i.numero_pedido LIKE ? OR u.name LIKE ?)';
    array_push($params, $qlike, $qlike, $qlike);
}
$whereStr = implode(' AND ', $where);

$stTotal = db()->prepare("
    SELECT COUNT(*) FROM entradas e
    JOIN inscripciones i ON i.id = e.inscripcion_id
    JOIN eventos ev ON ev.id = e.evento_id
    JOIN users u ON u.id = i.user_id
    WHERE $whereStr
");
$stTotal->execute($params);
$total = (int)$stTotal->fetchColumn();
$totalPags = max(1, ceil($total / $perPage));
$offset = ($pagina - 1) * $perPage;

$paramsPag = array_merge($params, [$perPage, $offset]);
$stEnt = db()->prepare("
    SELECT e.*, i.numero_pedido, ev.nombre as evento_nombre, u.name as comprador_nombre
    FROM entradas e
    JOIN inscripciones i ON i.id = e.inscripcion_id
    JOIN eventos ev ON ev.id = e.evento_id
    JOIN users u ON u.id = i.user_id
    WHERE $whereStr
    ORDER BY e.usado ASC, e.id DESC
    LIMIT ? OFFSET ?
");
$stEnt->execute($paramsPag);
$entradas = $stEnt->fetchAll();

$eventos = $esSuperAdmin
    ? db()->query("SELECT id, nombre FROM eventos ORDER BY nombre ASC")->fetchAll()
    : (function() {
        $st = db()->prepare("SELECT id, nombre FROM eventos WHERE admin_id=? ORDER BY nombre ASC");
        $st->execute([Auth::adminId()]);
        return $st->fetchAll();
    })();

$stResumen = db()->prepare("
    SELECT
        SUM(CASE WHEN e.usado=1 THEN 1 ELSE 0 END) as entrados,
        SUM(CASE WHEN e.usado=0 THEN 1 ELSE 0 END) as pendientes,
        COUNT(*) as total
    FROM entradas e
    JOIN inscripciones i ON i.id = e.inscripcion_id
    JOIN eventos ev ON ev.id = e.evento_id
    JOIN users u ON u.id = i.user_id
    WHERE $whereStr
");
$stResumen->execute($params);
$resumen = $stResumen->fetch();

$exportUrl = 'exportar.php?' . http_build_query(array_filter(['evento_id'=>$eventoFiltro,'estado'=>$estadoFiltro,'q'=>$q]));
?>

<div class="filters">
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <select name="evento_id" onchange="this.form.submit()">
      <option value="">Todos los eventos</option>
      <?php foreach ($eventos as $ev): ?>
        <option value="<?= $ev['id'] ?>" <?= $ev['id']==$eventoFiltro?'selected':'' ?>><?= h($ev['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="estado" onchange="this.form.submit()">
      <option value="">Todos</option>
      <option value="entrado" <?= $estadoFiltro==='entrado'?'selected':'' ?>>Entraron</option>
      <option value="no_entrado" <?= $estadoFiltro==='no_entrado'?'selected':'' ?>>No han entrado</option>
    </select>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar asistente, pedido o comprador…" style="min-width:240px;">
    <button type="submit" class="btn btn-sm">Buscar</button>
    <?php if ($eventoFiltro || $estadoFiltro || $q): ?>
      <a href="<?= h($base) ?>/admin/inscritos/index.php" class="btn btn-sm btn-outline">✕ Limpiar</a>
    <?php endif; ?>
  </form>
  <a href="<?= h($exportUrl) ?>" class="btn btn-sm btn-success">⬇ Excel</a>
</div>

<div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
  <div style="background:#e6f9ee;border-radius:10px;padding:12px 16px;min-width:120px;">
    <div style="font-size:20px;font-weight:800;color:#1a7a3a;"><?= (int)$resumen['entrados'] ?></div>
    <div style="font-size:11px;color:#1a7a3a;font-weight:700;">Han entrado</div>
  </div>
  <div style="background:#fffbeb;border-radius:10px;padding:12px 16px;min-width:120px;">
    <div style="font-size:20px;font-weight:800;color:#8a5a00;"><?= (int)$resumen['pendientes'] ?></div>
    <div style="font-size:11px;color:#8a5a00;font-weight:700;">No han entrado</div>
  </div>
  <div style="background:#f0f4ff;border-radius:10px;padding:12px 16px;min-width:120px;">
    <div style="font-size:20px;font-weight:800;color:#1a3a7a;"><?= (int)$resumen['total'] ?></div>
    <div style="font-size:11px;color:#1a3a7a;font-weight:700;">Total inscritos</div>
  </div>
</div>

<div class="card" style="padding:0;">
  <div class="table-wrap">
    <table class="admin">
      <thead>
        <tr>
          <th>Asistente</th>
          <th>Comprado por</th>
          <th>Evento</th>
          <th>Pedido</th>
          <th>Código</th>
          <th>Estado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($entradas)): ?>
          <tr><td colspan="7" style="text-align:center;color:#aaa;padding:40px;">Sin resultados.</td></tr>
        <?php else: ?>
          <?php foreach ($entradas as $ent): ?>
          <tr>
            <td><strong><?= h($ent['nombre_asistente']) ?></strong> <?= $ent['es_titular'] ? '<span class="badge badge-blue">titular</span>' : '' ?></td>
            <td style="font-size:13px;color:#888;"><?= h($ent['comprador_nombre']) ?></td>
            <td style="font-size:13px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($ent['evento_nombre']) ?></td>
            <td><code style="font-size:12px;"><?= h($ent['numero_pedido']) ?></code></td>
            <td><code style="font-size:12px;"><?= h($ent['codigo_corto'] ?? '—') ?></code></td>
            <td>
              <?php if ($ent['usado']): ?>
                <span class="badge badge-green">✓ Entró</span>
                <div style="font-size:11px;color:#aaa;margin-top:2px;"><?= date('d/m H:i', strtotime($ent['usado_at'])) ?></div>
              <?php else: ?>
                <span class="badge badge-gray">No ha entrado</span>
              <?php endif; ?>
            </td>
            <td>
              <form method="POST">
                <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
                <input type="hidden" name="entrada_id" value="<?= $ent['id'] ?>">
                <input type="hidden" name="evento_id" value="<?= $eventoFiltro ?>">
                <input type="hidden" name="estado_actual" value="<?= h($estadoFiltro) ?>">
                <input type="hidden" name="q_actual" value="<?= h($q) ?>">
                <?php if ($ent['usado']): ?>
                  <input type="hidden" name="action" value="desmarcar">
                  <button type="submit" class="btn btn-sm btn-outline">Desmarcar</button>
                <?php else: ?>
                  <input type="hidden" name="action" value="marcar">
                  <button type="submit" class="btn btn-sm btn-success">✓ Marcar entrada</button>
                <?php endif; ?>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($totalPags > 1): ?>
<div style="display:flex;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap;">
  <?php for ($p = max(1, $pagina-3); $p <= min($totalPags, $pagina+3); $p++): ?>
    <a href="?<?= http_build_query(array_merge(array_filter(['evento_id'=>$eventoFiltro,'estado'=>$estadoFiltro,'q'=>$q]), ['p'=>$p])) ?>"
       class="btn btn-sm <?= $p===$pagina?'':'btn-outline' ?>" style="min-width:34px;"><?= $p ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../_footer.php'; ?>
