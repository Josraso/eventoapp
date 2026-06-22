<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

Auth::adminCheck('superadmin', 'admin');
$base = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : getSetting('app_base_url'), '/');

function usuarioEsPropio(int $uid): bool
{
    if (Auth::adminRole() === 'superadmin') return true;
    $st = db()->prepare('SELECT COUNT(*) FROM inscripciones i JOIN eventos e ON e.id=i.evento_id WHERE i.user_id=? AND e.admin_id=?');
    $st->execute([$uid, Auth::adminId()]);
    return (int)$st->fetchColumn() > 0;
}

$id = (int)($_GET['id'] ?? 0);
$stU = db()->prepare('SELECT * FROM users WHERE id=?');
$stU->execute([$id]);
$user = $stU->fetch();
if (!$user || !usuarioEsPropio($id)) { flash('error','Usuario no encontrado.'); header('Location: ' . $base . '/admin/usuarios/index.php'); exit; }

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if (!$name) $errores[] = 'El nombre es obligatorio.';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'Email no válido.';

    if (!$errores) {
        $stChk = db()->prepare('SELECT id FROM users WHERE email=? AND id<>?');
        $stChk->execute([$email, $id]);
        if ($stChk->fetch()) $errores[] = 'Ya existe otro usuario con ese email.';
    }

    if (!$errores) {
        db()->prepare('UPDATE users SET name=?, email=?, phone=? WHERE id=?')
           ->execute([$name, $email, $phone, $id]);
        if ($pass !== '') {
            db()->prepare('UPDATE users SET password_hash=? WHERE id=?')
               ->execute([password_hash($pass, PASSWORD_BCRYPT), $id]);
        }
        Auth::logAction('editar_usuario', 'Usuario #'.$id.' ('.$email.')');
        flash('ok','Usuario actualizado.');
        header('Location: ' . $base . '/admin/usuarios/index.php'); exit;
    }
    $user['name'] = $name; $user['email'] = $email; $user['phone'] = $phone;
}

$pageTitle = 'Editar usuario';
require_once __DIR__ . '/../_header.php';
?>

<a href="<?= h($base) ?>/admin/usuarios/index.php" style="font-size:13px;color:#888;text-decoration:none;">← Volver a usuarios</a>

<div class="card" style="max-width:520px;margin-top:16px;">
  <div class="card-title">Editar usuario</div>

  <?php if ($errores): ?>
    <div class="alert alert-error"><?= h(implode(' ', $errores)) ?></div>
  <?php endif; ?>

  <form method="POST">
    <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
    <div class="field">
      <label>Nombre</label>
      <input type="text" name="name" value="<?= h($user['name']) ?>" required>
    </div>
    <div class="field">
      <label>Email</label>
      <input type="email" name="email" value="<?= h($user['email']) ?>" required>
    </div>
    <div class="field">
      <label>Teléfono</label>
      <input type="text" name="phone" value="<?= h($user['phone'] ?? '') ?>">
    </div>
    <div class="field">
      <label>Nueva contraseña (dejar en blanco para no cambiar)</label>
      <input type="password" name="password" autocomplete="new-password">
    </div>
    <button type="submit" class="btn" style="width:100%;">Guardar cambios</button>
  </form>
</div>

<?php require_once __DIR__ . '/../_footer.php'; ?>
