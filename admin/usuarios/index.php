<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

Auth::adminCheck('superadmin', 'admin');
$base = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : getSetting('app_base_url'), '/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $uid = (int)($_POST['user_id'] ?? 0);
    switch ($_POST['action'] ?? '') {
        case 'toggle_activo':
            db()->prepare('UPDATE users SET active = NOT active WHERE id=?')->execute([$uid]);
            flash('ok','Estado actualizado.');
            break;
    }
    header('Location: ' . $base . '/admin/usuarios/index.php'); exit;
}

$pageTitle = 'Usuarios';
require_once __DIR__ . '/../_header.php';

$q      = trim($_GET['q'] ?? '');
$pagina = max(1,(int)($_GET['p'] ?? 1));
$perPage = 30;

$where  = $q ? 'WHERE name LIKE ? OR email LIKE ?' : '';
$params = $q ? ['%'.$q.'%','%'.$q.'%'] : [];

$stTotal = db()->prepare("SELECT COUNT(*) FROM users $where");
$stTotal->execute($params);
$total = (int)$stTotal->fetchColumn();
$totalPags = max(1,ceil($total/$perPage));
$offset = ($pagina-1)*$perPage;

$st = db()->prepare("SELECT u.*,
    (SELECT COUNT(*) FROM inscripciones i WHERE i.user_id=u.id AND i.estado_pago='pagado') as num_inscripciones
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
