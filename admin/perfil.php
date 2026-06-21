<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';

Auth::adminCheck('superadmin', 'admin', 'portero');
$base = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : getSetting('app_base_url'), '/');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if ($pass && $pass !== $pass2) {
        $error = 'Las contraseñas no coinciden.';
    } elseif ($pass && strlen($pass) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } else {
        db()->prepare('UPDATE admin_users SET name=?, email=? WHERE id=?')
           ->execute([$name, $email, Auth::adminId()]);
        if ($pass) {
            db()->prepare('UPDATE admin_users SET password_hash=? WHERE id=?')
               ->execute([password_hash($pass, PASSWORD_BCRYPT), Auth::adminId()]);
        }
        $_SESSION['admin_name'] = $name ?: $_SESSION['admin_name'];
        flash('ok', 'Datos actualizados.');
        header('Location: ' . $base . '/admin/perfil.php'); exit;
    }
}

$st = db()->prepare('SELECT * FROM admin_users WHERE id=?');
$st->execute([Auth::adminId()]);
$mi = $st->fetch();

$pageTitle = 'Mi perfil';
require_once __DIR__ . '/_header.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div class="card" style="max-width:480px;">
  <div class="card-title">Mis datos</div>
  <form method="POST">
    <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
    <div class="field"><label>Usuario</label><input type="text" value="<?= h($mi['username']) ?>" disabled></div>
    <div class="field"><label>Rol</label><input type="text" value="<?= h($mi['role']) ?>" disabled></div>
    <div class="field"><label>Nombre</label><input type="text" name="name" value="<?= h($mi['name']) ?>"></div>
    <div class="field"><label>Email</label><input type="email" name="email" value="<?= h($mi['email']) ?>"></div>
    <div class="field-row">
      <div class="field"><label>Nueva contraseña</label><input type="password" name="password" minlength="8" placeholder="Déjalo en blanco para no cambiarla"></div>
      <div class="field"><label>Repetir contraseña</label><input type="password" name="password2" minlength="8"></div>
    </div>
    <button type="submit" class="btn btn-full">Guardar cambios</button>
  </form>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
