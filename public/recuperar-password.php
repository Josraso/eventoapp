<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Mailer.php';

$siteName = getSetting('site_name', 'Eventos');
$error = ''; $ok = false; $step = 'email';

if (isset($_GET['token'])) {
    $step = 'reset';
    $token = preg_replace('/[^a-f0-9]/', '', $_GET['token']);
    $st = db()->prepare('SELECT * FROM users WHERE reset_token=? AND reset_token_expires > NOW()');
    $st->execute([$token]);
    $uReset = $st->fetch();
    if (!$uReset) { $error = 'Enlace no válido o expirado.'; $step = 'email'; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    if ($_POST['step'] === 'email') {
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$email) { $error = 'Introduce un email válido.'; }
        else {
            $st = db()->prepare('SELECT * FROM users WHERE email=? AND active=1');
            $st->execute([$email]);
            $u = $st->fetch();
            if ($u) {
                $token = generateToken(32);
                db()->prepare('UPDATE users SET reset_token=?, reset_token_expires=DATE_ADD(NOW(), INTERVAL 2 HOUR) WHERE id=?')
                   ->execute([$token, $u['id']]);
                $link = baseUrl() . '/public/recuperar-password.php?token=' . $token;
                $m = new Mailer();
                $m->send($u['email'], $u['name'], 'Recuperar contraseña — ' . $siteName, Mailer::tplRecuperarPassword($u, $link));
            }
            $ok = true; // Siempre OK para no revelar emails
        }
    } elseif ($_POST['step'] === 'reset') {
        $token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $pass2 = $_POST['password2'] ?? '';
        $st = db()->prepare('SELECT * FROM users WHERE reset_token=? AND reset_token_expires > NOW()');
        $st->execute([$token]);
        $u = $st->fetch();
        if (!$u) { $error = 'Enlace no válido o expirado.'; }
        elseif (strlen($pass) < 8) { $error = 'Mínimo 8 caracteres.'; $step = 'reset'; }
        elseif ($pass !== $pass2) { $error = 'Las contraseñas no coinciden.'; $step = 'reset'; }
        else {
            db()->prepare('UPDATE users SET password_hash=?, reset_token=NULL, reset_token_expires=NULL WHERE id=?')
               ->execute([password_hash($pass, PASSWORD_BCRYPT), $u['id']]);
            flash('ok', 'Contraseña actualizada. Ya puedes iniciar sesión.');
            redirect('login.php');
        }
        if ($step === 'reset') $uReset = $u;
    }
}
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recuperar contraseña — <?= h($siteName) ?></title>
<link rel="stylesheet" href="assets/css/main.css">
</head><body>
<header class="site-header"><div class="inner">
  <a href="index.php" class="site-logo"><?= h($siteName) ?></a>
  <nav class="nav-links"><a href="login.php">Iniciar sesión</a></nav>
</div></header>
<main class="page-wrap"><div class="container-sm">
  <div class="card anim-fade">
    <h1 style="font-size:22px;font-weight:800;margin-bottom:18px;">Recuperar contraseña</h1>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($ok): ?>
      <div class="alert alert-success">Si ese email está registrado, recibirás el enlace en breve. Revisa también el spam.</div>
      <a href="login.php" class="btn btn-full" style="margin-top:12px;">Volver al login</a>
    <?php elseif ($step === 'email'): ?>
      <p style="font-size:13px;color:#888;margin-bottom:16px;">Introduce tu email y te enviaremos un enlace para restablecer tu contraseña.</p>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
        <input type="hidden" name="step" value="email">
        <div class="field"><label>Email</label><input type="email" name="email" required autofocus></div>
        <button type="submit" class="btn btn-full">Enviar enlace</button>
      </form>
    <?php elseif ($step === 'reset' && isset($uReset)): ?>
      <p style="font-size:13px;color:#888;margin-bottom:16px;">Introduce tu nueva contraseña.</p>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
        <input type="hidden" name="step" value="reset">
        <input type="hidden" name="token" value="<?= h($_GET['token'] ?? '') ?>">
        <div class="field"><label>Nueva contraseña (mín. 8 caracteres)</label><input type="password" name="password" required minlength="8" autofocus></div>
        <div class="field"><label>Repetir contraseña</label><input type="password" name="password2" required minlength="8"></div>
        <button type="submit" class="btn btn-full">Guardar contraseña</button>
      </form>
    <?php endif; ?>
  </div>
</div></main></body></html>
