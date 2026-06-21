<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

Auth::adminCheck('superadmin');
$base = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : getSetting('app_base_url'), '/');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'crear') {
        $username = trim($_POST['username'] ?? '');
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $role     = in_array($_POST['role'] ?? '', ['admin', 'superadmin']) ? $_POST['role'] : 'admin';
        $pass     = $_POST['password'] ?? '';
        if (!$username || !$pass || !$email) {
            $error = 'Usuario, email y contraseña son obligatorios.';
        } elseif (strlen($pass) < 8) {
            $error = 'La contraseña debe tener al menos 8 caracteres.';
        } else {
            $chk = db()->prepare('SELECT id FROM admin_users WHERE username=?');
            $chk->execute([$username]);
            if ($chk->fetch()) {
                $error = 'Ese usuario ya existe.';
            } else {
                db()->prepare('INSERT INTO admin_users (username,name,email,password_hash,role) VALUES(?,?,?,?,?)')
                   ->execute([$username, $name, $email, password_hash($pass, PASSWORD_BCRYPT), $role]);
                Auth::logAction('admin_crear', 'Administrador creado: ' . $username . ' (' . $role . ')');
                flash('ok', 'Administrador creado.');
                header('Location: ' . $base . '/admin/administradores/index.php'); exit;
            }
        }
    }

    if ($action === 'editar') {
        $aid   = (int)$_POST['admin_id'];
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role  = in_array($_POST['role'] ?? '', ['admin', 'superadmin']) ? $_POST['role'] : 'admin';

        if ($aid === Auth::adminId() && $role !== 'superadmin') {
            $error = 'No puedes quitarte a ti mismo el rol de superadmin.';
        } else {
            db()->prepare('UPDATE admin_users SET name=?, email=?, role=? WHERE id=?')
               ->execute([$name, $email, $role, $aid]);
            if (!empty($_POST['password'])) {
                if (strlen($_POST['password']) < 8) {
                    $error = 'La contraseña debe tener al menos 8 caracteres.';
                } else {
                    db()->prepare('UPDATE admin_users SET password_hash=? WHERE id=?')
                       ->execute([password_hash($_POST['password'], PASSWORD_BCRYPT), $aid]);
                }
            }
            if (!$error) {
                if ($aid === Auth::adminId()) $_SESSION['admin_name'] = $name ?: $_SESSION['admin_name'];
                Auth::logAction('admin_editar', 'Administrador editado: #' . $aid);
                flash('ok', 'Administrador actualizado.');
                header('Location: ' . $base . '/admin/administradores/index.php'); exit;
            }
        }
    }

    if ($action === 'toggle_activo') {
        $aid = (int)$_POST['admin_id'];
        if ($aid === Auth::adminId()) {
            $error = 'No puedes desactivarte a ti mismo.';
        } else {
            db()->prepare("UPDATE admin_users SET active=NOT active WHERE id=? AND role IN ('admin','superadmin')")->execute([$aid]);
            flash('ok', 'Estado actualizado.');
            header('Location: ' . $base . '/admin/administradores/index.php'); exit;
        }
    }

    if ($action === 'eliminar') {
        $aid = (int)$_POST['admin_id'];
        if ($aid === Auth::adminId()) {
            $error = 'No puedes eliminarte a ti mismo.';
        } else {
            db()->prepare("DELETE FROM admin_users WHERE id=? AND role IN ('admin','superadmin')")->execute([$aid]);
            flash('ok', 'Administrador eliminado.');
            header('Location: ' . $base . '/admin/administradores/index.php'); exit;
        }
    }
}

$admins = db()->query("SELECT * FROM admin_users WHERE role IN ('admin','superadmin') ORDER BY role DESC, username ASC")->fetchAll();
$aidEditar = (int)($_GET['editar'] ?? 0);

$pageTitle = 'Administradores';
require_once __DIR__ . '/../_header.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 320px;gap:16px;align-items:start;">
  <div>
    <div class="card" style="padding:0;">
      <div class="table-wrap">
        <table class="admin">
          <thead><tr><th>Usuario</th><th>Nombre</th><th>Email</th><th>Rol</th><th>Estado</th><th></th></tr></thead>
          <tbody>
            <?php if (empty($admins)): ?>
              <tr><td colspan="6" style="text-align:center;color:#aaa;padding:30px;">Sin administradores.</td></tr>
            <?php else: ?>
              <?php foreach ($admins as $a): ?>
              <tr>
                <td><strong><?= h($a['username']) ?></strong><?= $a['id']===Auth::adminId() ? ' <span class="badge badge-blue">tú</span>' : '' ?></td>
                <td><?= h($a['name'] ?: '—') ?></td>
                <td style="font-size:13px;color:#888;"><?= h($a['email']) ?></td>
                <td><span class="badge <?= $a['role']==='superadmin'?'badge-purple':'badge-gray' ?>"><?= h($a['role']) ?></span></td>
                <td><?= $a['active'] ? '<span class="badge badge-green">Activo</span>' : '<span class="badge badge-red">Inactivo</span>' ?></td>
                <td>
                  <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <a href="<?= h($base) ?>/admin/administradores/index.php?editar=<?= $a['id'] ?>" class="btn btn-sm btn-outline">✏️ Editar</a>
                    <?php if ($a['id'] !== Auth::adminId()): ?>
                    <form method="POST" style="display:inline;">
                      <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
                      <input type="hidden" name="action" value="toggle_activo">
                      <input type="hidden" name="admin_id" value="<?= $a['id'] ?>">
                      <button class="btn btn-sm btn-outline"><?= $a['active']?'Desactivar':'Activar' ?></button>
                    </form>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este administrador?')">
                      <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
                      <input type="hidden" name="action" value="eliminar">
                      <input type="hidden" name="admin_id" value="<?= $a['id'] ?>">
                      <button class="btn btn-sm btn-danger">🗑</button>
                    </form>
                    <?php endif; ?>
                  </div>
                  <?php if ($aidEditar === (int)$a['id']): ?>
                  <form method="POST" style="margin-top:12px;background:#f8f8f8;border-radius:8px;padding:14px;">
                    <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
                    <input type="hidden" name="action" value="editar">
                    <input type="hidden" name="admin_id" value="<?= $a['id'] ?>">
                    <div class="field"><label>Nombre</label><input type="text" name="name" value="<?= h($a['name']) ?>"></div>
                    <div class="field"><label>Email</label><input type="email" name="email" value="<?= h($a['email']) ?>"></div>
                    <div class="field">
                      <label>Rol</label>
                      <select name="role">
                        <option value="admin" <?= $a['role']==='admin'?'selected':'' ?>>admin</option>
                        <option value="superadmin" <?= $a['role']==='superadmin'?'selected':'' ?>>superadmin</option>
                      </select>
                    </div>
                    <div class="field"><label>Nueva contraseña (opcional)</label><input type="password" name="password" minlength="8" placeholder="Déjalo en blanco para no cambiarla"></div>
                    <button type="submit" class="btn btn-sm">Guardar cambios</button>
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
    <div class="card-title">Nuevo administrador</div>
    <form method="POST">
      <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
      <input type="hidden" name="action" value="crear">
      <div class="field"><label>Usuario *</label><input type="text" name="username" required></div>
      <div class="field"><label>Nombre</label><input type="text" name="name"></div>
      <div class="field"><label>Email *</label><input type="email" name="email" required></div>
      <div class="field">
        <label>Rol</label>
        <select name="role">
          <option value="admin">admin</option>
          <option value="superadmin">superadmin</option>
        </select>
      </div>
      <div class="field"><label>Contraseña * (mín. 8 caracteres)</label><input type="password" name="password" required minlength="8"></div>
      <button type="submit" class="btn btn-full">Crear administrador</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../_footer.php'; ?>
