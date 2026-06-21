<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

Auth::adminCheck('superadmin', 'admin');
$base = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : getSetting('app_base_url'), '/');

// ── CONFIRMAR PAGO MANUAL ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $insId = (int)($_POST['inscripcion_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'confirmar_pago') {
        $stIns = db()->prepare('SELECT i.*, e.nombre as evento_nombre FROM inscripciones i JOIN eventos e ON e.id=i.evento_id WHERE i.id=?');
        $stIns->execute([$insId]);
        $ins = $stIns->fetch();
        if ($ins && $ins['estado_pago'] === 'pendiente') {
            require_once __DIR__ . '/../../lib/TicketManager.php';
            require_once __DIR__ . '/../../lib/Mailer.php';
            db()->prepare("UPDATE inscripciones SET estado_pago='pagado', confirmado_por=?, confirmado_at=NOW() WHERE id=?")
               ->execute([Auth::adminId(), $insId]);
            $paths = TicketManager::generarPDFsInscripcion($insId);
            $stUser = db()->prepare('SELECT * FROM users WHERE id=?');
            $stUser->execute([$ins['user_id']]);
            $user = $stUser->fetch();
            $stEv = db()->prepare('SELECT * FROM eventos WHERE id=?');
            $stEv->execute([$ins['evento_id']]);
            $evento = $stEv->fetch();
            $atts = array_map(fn($p) => ['path' => $p], $paths);
            $m = new Mailer();
            $res = $m->send($user['email'], $user['name'], 'Tus entradas — ' . $evento['nombre'],
                Mailer::tplEntradas($ins, $evento, $user), $atts);
            if ($res['ok']) db()->prepare('UPDATE inscripciones SET email_entradas_enviado=1 WHERE id=?')->execute([$insId]);
            Auth::logAction('confirmar_pago', 'Inscripción #' . $insId . ' pedido:' . $ins['numero_pedido']);
            flash('ok', 'Pago confirmado y entradas enviadas al cliente.');
        }
    } elseif ($action === 'cancelar') {
        db()->prepare("UPDATE inscripciones SET estado_pago='cancelado', notas_admin=? WHERE id=?")
           ->execute([$_POST['nota'] ?? '', $insId]);
        flash('ok', 'Inscripción cancelada.');
    } elseif ($action === 'guardar_nota') {
        db()->prepare('UPDATE inscripciones SET notas_admin=? WHERE id=?')
           ->execute([$_POST['nota'] ?? '', $insId]);
        flash('ok', 'Nota guardada.');
    }
    // Volver al listado conservando filtros
    header('Location: ' . $base . '/admin/inscripciones/index.php?' . http_build_query(array_filter([
        'evento_id' => $_POST['evento_id'] ?? '',
        'estado'    => $_POST['estado_actual'] ?? '',
        'q'         => $_POST['q_actual'] ?? '',
    ])));
    exit;
}

$pageTitle = 'Pedidos';
require_once __DIR__ . '/../_header.php';

// ── FILTROS ────────────────────────────────────────────────────────────────────
$eventoFiltro = (int)($_GET['evento_id'] ?? 0);
$estadoFiltro = $_GET['estado'] ?? '';
$q            = trim($_GET['q'] ?? '');
$pagina       = max(1, (int)($_GET['p'] ?? 1));
$perPage      = 30;

$where  = ['1=1'];
$params = [];

if ($eventoFiltro) { $where[] = 'i.evento_id=?'; $params[] = $eventoFiltro; }
if ($estadoFiltro) { $where[] = 'i.estado_pago=?'; $params[] = $estadoFiltro; }
if ($q) {
    $qlike = '%' . $q . '%';
    $where[] = '(i.numero_pedido LIKE ? OR u.name LIKE ? OR u.email LIKE ?
                 OR EXISTS(SELECT 1 FROM entradas en WHERE en.inscripcion_id=i.id AND en.nombre_asistente LIKE ?))';
    array_push($params, $qlike, $qlike, $qlike, $qlike);
}
$whereStr = implode(' AND ', $where);

// Total
$stTotal = db()->prepare("SELECT COUNT(*) FROM inscripciones i JOIN users u ON u.id=i.user_id WHERE $whereStr");
$stTotal->execute($params);
$total = (int)$stTotal->fetchColumn();
$totalPags = max(1, ceil($total / $perPage));
$offset = ($pagina - 1) * $perPage;

// Datos
$paramsPag = array_merge($params, [$perPage, $offset]);
$stIns = db()->prepare("
    SELECT i.*, e.nombre as evento_nombre, u.name as user_name, u.email as user_email,
        (SELECT GROUP_CONCAT(en.nombre_asistente ORDER BY en.es_titular DESC SEPARATOR ', ') FROM entradas en WHERE en.inscripcion_id=i.id) as asistentes
    FROM inscripciones i
    JOIN eventos e ON e.id=i.evento_id
    JOIN users u ON u.id=i.user_id
    WHERE $whereStr
    ORDER BY i.created_at DESC
    LIMIT ? OFFSET ?
");
$stIns->execute($paramsPag);
$inscripciones = $stIns->fetchAll();

// Select de eventos para filtro
$eventos = db()->query("SELECT id, nombre FROM eventos ORDER BY nombre ASC")->fetchAll();

// Totales resumen
$stResumen = db()->prepare("
    SELECT estado_pago, COUNT(*) as cnt, SUM(precio_total) as suma
    FROM inscripciones i
    JOIN users u ON u.id=i.user_id
    WHERE $whereStr
    GROUP BY estado_pago
");
$stResumen->execute($params);
$resumen = [];
foreach ($stResumen->fetchAll() as $r) $resumen[$r['estado_pago']] = $r;

// URL base para exportar
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
      <option value="">Todos los estados</option>
      <option value="pagado" <?= $estadoFiltro==='pagado'?'selected':'' ?>>Pagado</option>
      <option value="pendiente" <?= $estadoFiltro==='pendiente'?'selected':'' ?>>Pendiente</option>
      <option value="cancelado" <?= $estadoFiltro==='cancelado'?'selected':'' ?>>Cancelado</option>
    </select>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar nombre, email, pedido, asistente…" style="min-width:240px;">
    <button type="submit" class="btn btn-sm">Buscar</button>
    <?php if ($eventoFiltro || $estadoFiltro || $q): ?>
      <a href="<?= h($base) ?>/admin/inscripciones/index.php" class="btn btn-sm btn-outline">✕ Limpiar</a>
    <?php endif; ?>
  </form>
  <a href="<?= h($exportUrl) ?>" class="btn btn-sm btn-success">⬇ Excel</a>
</div>

<!-- Resumen rápido -->
<div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
  <?php
  $rs = [
      'pagado'    => ['label'=>'Pagadas','color'=>'#1a7a3a','bg'=>'#e6f9ee'],
      'pendiente' => ['label'=>'Pendientes','color'=>'#8a5a00','bg'=>'#fffbeb'],
      'cancelado' => ['label'=>'Canceladas','color'=>'#c0392b','bg'=>'#fff1f0'],
  ];
  foreach ($rs as $est=>$r):
    $cnt  = (int)($resumen[$est]['cnt'] ?? 0);
    $suma = (float)($resumen[$est]['suma'] ?? 0);
  ?>
  <div style="background:<?= $r['bg'] ?>;border-radius:10px;padding:12px 16px;min-width:120px;">
    <div style="font-size:20px;font-weight:800;color:<?= $r['color'] ?>;"><?= $cnt ?></div>
    <div style="font-size:11px;color:<?= $r['color'] ?>;font-weight:700;"><?= $r['label'] ?></div>
    <?php if ($suma > 0): ?><div style="font-size:12px;color:<?= $r['color'] ?>;"><?= number_format($suma,2,',','.') ?> €</div><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <div style="background:#f0f4ff;border-radius:10px;padding:12px 16px;min-width:120px;">
    <div style="font-size:20px;font-weight:800;color:#1a3a7a;"><?= $total ?></div>
    <div style="font-size:11px;color:#1a3a7a;font-weight:700;">Total resultados</div>
  </div>
</div>

<div class="card" style="padding:0;">
  <div class="table-wrap">
    <table class="admin">
      <thead>
        <tr>
          <th>Pedido</th>
          <th>Evento</th>
          <th>Cliente</th>
          <th>Asistentes</th>
          <th>Pago</th>
          <th>Total</th>
          <th>Estado</th>
          <th>Fecha</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($inscripciones)): ?>
          <tr><td colspan="9" style="text-align:center;color:#aaa;padding:40px;">Sin resultados.</td></tr>
        <?php else: ?>
          <?php foreach ($inscripciones as $ins):
            $bc = match($ins['estado_pago']) { 'pagado'=>'badge-green','pendiente'=>'badge-orange',default=>'badge-red' };
          ?>
          <tr>
            <td><code style="font-size:12px;"><?= h($ins['numero_pedido']) ?></code></td>
            <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;"><?= h($ins['evento_nombre']) ?></td>
            <td>
              <div style="font-size:13px;font-weight:600;"><?= h($ins['user_name']) ?></div>
              <div style="font-size:11px;color:#aaa;"><?= h($ins['user_email']) ?></div>
            </td>
            <td style="font-size:12px;color:#666;max-width:150px;overflow:hidden;text-overflow:ellipsis;">
              <?= h($ins['asistentes'] ?? '') ?>
            </td>
            <td><span class="badge badge-gray"><?= ucfirst($ins['metodo_pago']) ?></span></td>
            <td style="font-weight:700;"><?= number_format((float)$ins['precio_total'],2,',','.') ?> €</td>
            <td><span class="badge <?= $bc ?>"><?= ucfirst($ins['estado_pago']) ?></span></td>
            <td style="font-size:12px;color:#aaa;"><?= date('d/m/Y H:i', strtotime($ins['created_at'])) ?></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <a href="<?= h($base) ?>/admin/inscripciones/detalle.php?id=<?= $ins['id'] ?>" class="btn btn-sm btn-outline">Ver</a>
                <?php if ($ins['estado_pago'] === 'pendiente'): ?>
                  <form method="POST" onsubmit="return confirm('¿Confirmar el pago de esta inscripción?')">
                    <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
                    <input type="hidden" name="action" value="confirmar_pago">
                    <input type="hidden" name="inscripcion_id" value="<?= $ins['id'] ?>">
                    <input type="hidden" name="evento_id" value="<?= $eventoFiltro ?>">
                    <input type="hidden" name="estado_actual" value="<?= h($estadoFiltro) ?>">
                    <input type="hidden" name="q_actual" value="<?= h($q) ?>">
                    <button type="submit" class="btn btn-sm btn-success">✓ Confirmar</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Paginación -->
<?php if ($totalPags > 1): ?>
<div style="display:flex;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap;">
  <?php for ($p = max(1, $pagina-3); $p <= min($totalPags, $pagina+3); $p++): ?>
    <a href="?<?= http_build_query(array_merge(array_filter(['evento_id'=>$eventoFiltro,'estado'=>$estadoFiltro,'q'=>$q]), ['p'=>$p])) ?>"
       class="btn btn-sm <?= $p===$pagina?'':'btn-outline' ?>" style="min-width:34px;"><?= $p ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../_footer.php'; ?>
