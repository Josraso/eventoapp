<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

Auth::adminCheck('superadmin', 'admin');
$base = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : getSetting('app_base_url'), '/');
$esSuperAdmin = Auth::adminRole() === 'superadmin';

// Un usuario solo es visible/gestionable por un admin normal si tiene al menos
// un pedido en un evento de ese admin (un admin no debe ver clientes que solo
// compraron en eventos de otro organizador).
function usuarioEsPropio(int $uid): bool
{
    if (Auth::adminRole() === 'superadmin') return true;
    $st = db()->prepare('SELECT COUNT(*) FROM inscripciones i JOIN eventos e ON e.id=i.evento_id WHERE i.user_id=? AND e.admin_id=?');
    $st->execute([$uid, Auth::adminId()]);
    return (int)$st->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $uid = (int)($_POST['user_id'] ?? 0);
    if (!usuarioEsPropio($uid)) {
        flash('error','No tienes permiso sobre este usuario.');
        header('Location: ' . $base . '/admin/usuarios/index.php'); exit;
    }
    switch ($_POST['action'] ?? '') {
        case 'toggle_activo':
            db()->prepare('UPDATE users SET active = NOT active WHERE id=?')->execute([$uid]);
            flash('ok','Estado actualizado.');
            break;
        case 'eliminar':
            $stChk = db()->prepare("SELECT COUNT(*) FROM inscripciones WHERE user_id=? AND estado_pago='pagado'");
            $stChk->execute([$uid]);
            if ((int)$stChk->fetchColumn() > 0) {
                flash('error','No se puede eliminar un usuario con pedidos pagados.');
            } else {
                db()->prepare('DELETE FROM inscripciones WHERE user_id=?')->execute([$uid]);
                db()->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
                flash('ok','Usuario eliminado.');
            }
            break;
    }
    header('Location: ' . $base . '/admin/usuarios/index.php'); exit;
}

$pageTitle = 'Usuarios';
require_once __DIR__ . '/../_header.php';

$q      = trim($_GET['q'] ?? '');
$pagina = max(1,(int)($_GET['p'] ?? 1));
$perPage = 30;

$cond   = [];
$params = [];
if ($q) { $cond[] = '(u.name LIKE ? OR u.email LIKE ?)'; $params[] = '%'.$q.'%'; $params[] = '%'.$q.'%'; }
if (!$esSuperAdmin) {
    $cond[] = 'EXISTS (SELECT 1 FROM inscripciones i2 JOIN eventos e2 ON e2.id=i2.evento_id WHERE i2.user_id=u.id AND e2.admin_id=?)';
    $params[] = Auth::adminId();
}
$where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';

$stTotal = db()->prepare("SELECT COUNT(*) FROM users u $where");
$stTotal->execute($params);
$total = (int)$stTotal->fetchColumn();
$totalPags = max(1,ceil($total/$perPage));
$offset = ($pagina-1)*$perPage;

// Para un admin normal, las inscripciones contadas son solo las de sus propios
// eventos: no debe ver el número de pedidos que un cliente hizo en otros eventos.
$condInscr = "i.estado_pago='pagado'" . (!$esSuperAdmin ? ' AND i.evento_id IN (SELECT id FROM eventos WHERE admin_id=' . (int)Auth::adminId() . ')' : '');

$st = db()->prepare("SELECT u.*,
    (SELECT COUNT(*) FROM inscripciones i WHERE i.user_id=u.id AND $condInscr) as num_inscripciones
    FROM users u $where ORDER BY u.created_at DESC LIMIT ? OFFSET ?");
$st->execute(array_merge($params,[$perPage,$offset]));
$users = $st->fetchAll();
?>

<div class="filters" style="justify-content:space-between;">
  <form method="GET" style="display:flex;gap:10px;">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar nombre o email…">
    <button type="submit" class="btn btn-sm">Buscar</button>
    <?php if ($q): ?><a href="<?= h($base) ?>/admin/usuarios/index.php" class="btn btn-sm btn-outline">✕</a><?php endif; ?>
  </form>
  <div style="font-size:13px;color:#888;"><?= $total ?> usuario<?= $total!==1?'s':'' ?></div>
</div>

<div class="card" style="padding:0;">
  <div class="table-wrap">
    <table class="admin">
      <thead><tr><th>Nombre</th><th>Email</th><th>Teléfono</th><th>Verificado</th><th>Inscripciones</th><th>Registro</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php if (empty($users)): ?>
          <tr><td colspan="8" style="text-align:center;color:#aaa;padding:30px;">Sin usuarios.</td></tr>
        <?php else: ?>
          <?php foreach ($users as $u): ?>
          <tr>
            <td style="font-weight:600;"><?= h($u['name']) ?></td>
            <td><?= h($u['email']) ?></td>
            <td><?= h($u['phone'] ?: '—') ?></td>
            <td><?= $u['email_verified'] ? '<span class="badge badge-green">✓</span>' : '<span class="badge badge-gray">No</span>' ?></td>
            <td><strong><?= (int)$u['num_inscripciones'] ?></strong></td>
            <td style="font-size:12px;color:#aaa;"><?= date('d/m/Y',strtotime($u['created_at'])) ?></td>
            <td><?= $u['active'] ? '<span class="badge badge-green">Activo</span>' : '<span class="badge badge-red">Inactivo</span>' ?></td>
            <td>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
                <input type="hidden" name="action" value="toggle_activo">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline"><?= $u['active']?'Desactivar':'Activar' ?></button>
              </form>
              <a href="<?= h($base) ?>/admin/inscripciones/index.php?q=<?= urlencode($u['email']) ?>" class="btn btn-sm btn-outline">Inscripciones</a>
              <a href="<?= h($base) ?>/admin/usuarios/editar.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline">✎ Editar</a>
              <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este usuario y sus pedidos no pagados? Esta acción no se puede deshacer.')">
                <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
                <input type="hidden" name="action" value="eliminar">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">🗑</button>
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
  <?php for ($p=max(1,$pagina-3);$p<=min($totalPags,$pagina+3);$p++): ?>
    <a href="?<?= http_build_query(array_merge($q?['q'=>$q]:[],['p'=>$p])) ?>"
       class="btn btn-sm <?= $p===$pagina?'':'btn-outline' ?>"><?= $p ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../_footer.php'; ?>
