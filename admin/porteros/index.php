<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

Auth::adminCheck('superadmin','admin');
$base = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : getSetting('app_base_url'), '/');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'crear') {
        $username = trim($_POST['username'] ?? '');
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $pass     = $_POST['password'] ?? '';
        $eids     = array_map('intval', $_POST['eventos'] ?? []);
        if (!$username || !$pass) { $error = 'Usuario y contraseña son obligatorios.'; }
        else {
            $chk = db()->prepare('SELECT id FROM admin_users WHERE username=?');
            $chk->execute([$username]);
            if ($chk->fetch()) { $error = 'Ese usuario ya existe.'; }
            else {
                db()->prepare('INSERT INTO admin_users (username,name,email,password_hash,role) VALUES(?,?,?,?,?)')
                   ->execute([$username,$name,$email,password_hash($pass,PASSWORD_BCRYPT),'portero']);
                $pid = (int)db()->lastInsertId();
                foreach ($eids as $eid) {
                    db()->prepare('INSERT IGNORE INTO portero_eventos (admin_id,evento_id) VALUES(?,?)')->execute([$pid,$eid]);
                }
                flash('ok','Portero creado.');
                header('Location: ' . $base . '/admin/porteros/index.php'); exit;
            }
        }
    }
    if ($action === 'toggle_activo') {
        db()->prepare('UPDATE admin_users SET active=NOT active WHERE id=? AND role="portero"')->execute([(int)$_POST['pid']]);
        flash('ok','Estado actualizado.');
        header('Location: ' . $base . '/admin/porteros/index.php'); exit;
    }
    if ($action === 'eliminar') {
        db()->prepare('DELETE FROM admin_users WHERE id=? AND role="portero"')->execute([(int)$_POST['pid']]);
        flash('ok','Portero eliminado.');
        header('Location: ' . $base . '/admin/porteros/index.php'); exit;
    }
    if ($action === 'asignar_eventos') {
        $pid  = (int)$_POST['pid'];
        $eids = array_map('intval', $_POST['eventos'] ?? []);
        db()->prepare('DELETE FROM portero_eventos WHERE admin_id=?')->execute([$pid]);
        foreach ($eids as $eid) {
            db()->prepare('INSERT IGNORE INTO portero_eventos (admin_id,evento_id) VALUES(?,?)')->execute([$pid,$eid]);
        }
        flash('ok','Asignación actualizada.');
        header('Location: ' . $base . '/admin/porteros/index.php'); exit;
    }
}

$porteros = db()->query("SELECT p.*, GROUP_CONCAT(e.nombre ORDER BY e.nombre SEPARATOR ', ') as eventos_asignados
    FROM admin_users p
    LEFT JOIN portero_eventos pe ON pe.admin_id=p.id
    LEFT JOIN eventos e ON e.id=pe.evento_id
    WHERE p.role='portero'
    GROUP BY p.id ORDER BY p.username")->fetchAll();

$eventos = db()->query("SELECT id, nombre FROM eventos WHERE activo=1 AND archivado=0 ORDER BY nombre")->fetchAll();
$pidEditar = (int)($_GET['editar'] ?? 0);

$pageTitle = 'Porteros';
require_once __DIR__ . '/../_header.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start;">
  <div>
    <div class="card" style="padding:0;">
      <div class="table-wrap">
        <table class="admin">
          <thead><tr><th>Usuario</th><th>Nombre</th><th>Eventos asignados</th><th>Estado</th><th></th></tr></thead>
          <tbody>
            <?php if (empty($porteros)): ?>
              <tr><td colspan="5" style="text-align:center;color:#aaa;padding:30px;">Sin porteros aún.</td></tr>
            <?php else: ?>
              <?php foreach ($porteros as $p):
                $evAsig = db()->prepare('SELECT evento_id FROM portero_eventos WHERE admin_id=?');
                $evAsig->execute([$p['id']]);
                $evAsigIds = $evAsig->fetchAll(PDO::FETCH_COLUMN);
              ?>
              <tr>
                <td><strong><?= h($p['username']) ?></strong></td>
                <td><?= h($p['name'] ?: '—') ?></td>
                <td style="font-size:12px;color:#888;max-width:180px;"><?= h($p['eventos_asignados'] ?: 'Ninguno') ?></td>
                <td><?= $p['active']?'<span class="badge badge-green">Activo</span>':'<span class="badge badge-red">Inactivo</span>' ?></td>
                <td>
                  <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <a href="<?= h($base) ?>/admin/porteros/index.php?editar=<?= $p['id'] ?>" class="btn btn-sm btn-outline">✏️ Editar</a>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
                      <input type="hidden" name="action" value="toggle_activo">
                      <input type="hidden" name="pid" value="<?= $p['id'] ?>">
                      <button class="btn btn-sm btn-outline"><?= $p['active']?'Desactivar':'Activar' ?></button>
                    </form>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este portero?')">
                      <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
                      <input type="hidden" name="action" value="eliminar">
                      <input type="hidden" name="pid" value="<?= $p['id'] ?>">
                      <button class="btn btn-sm btn-danger">🗑</button>
                    </form>
                  </div>
                  <?php if ($pidEditar === (int)$p['id']): ?>
                  <form method="POST" style="margin-top:12px;background:#f8f8f8;border-radius:8px;padding:14px;">
                    <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
                    <input type="hidden" name="action" value="asignar_eventos">
                    <input type="hidden" name="pid" value="<?= $p['id'] ?>">
                    <div style="font-size:12px;font-weight:700;color:#888;margin-bottom:10px;">Eventos asignados:</div>
                    <?php foreach ($eventos as $ev): ?>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-bottom:6px;">
                      <input type="checkbox" name="eventos[]" value="<?= $ev['id'] ?>" <?= in_array($ev['id'],$evAsigIds)?'checked':'' ?>>
                      <?= h($ev['nombre']) ?>
                    </label>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-sm" style="margin-top:10px;">Guardar asignación</button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-title">Nuevo portero</div>
    <form method="POST">
      <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
      <input type="hidden" name="action" value="crear">
      <div class="field"><label>Usuario *</label><input type="text" name="username" required></div>
      <div class="field"><label>Nombre</label><input type="text" name="name"></div>
      <div class="field"><label>Email</label><input type="email" name="email"></div>
      <div class="field"><label>Contraseña *</label><input type="password" name="password" required minlength="6"></div>
      <div class="field">
        <label>Eventos asignados</label>
        <?php foreach ($eventos as $ev): ?>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-bottom:6px;">
          <input type="checkbox" name="eventos[]" value="<?= $ev['id'] ?>">
          <?= h($ev['nombre']) ?>
        </label>
        <?php endforeach; ?>
        <?php if (empty($eventos)): ?><p style="font-size:12px;color:#aaa;">Sin eventos activos.</p><?php endif; ?>
      </div>
      <button type="submit" class="btn btn-full">Crear portero</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../_footer.php'; ?>
