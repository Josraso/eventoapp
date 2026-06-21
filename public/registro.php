<?php
// registro.php — Registro independiente (sin inscripción)
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Mailer.php';

if (Auth::isUserLogged()) redirect('mi-cuenta.php');

$siteName = getSetting('site_name', 'Eventos');
$error = '';
$ok    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $nombre   = trim($_POST['nombre'] ?? '');
    $email    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $telefono = trim($_POST['telefono'] ?? '');
    $pass     = $_POST['password'] ?? '';
    $pass2    = $_POST['password2'] ?? '';
    $captcha  = $_POST['g-recaptcha-response'] ?? '';

    if (!$nombre || !$email || !$pass)
        $error = 'Completa todos los campos obligatorios.';
    elseif (strlen($pass) < 8)
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    elseif ($pass !== $pass2)
        $error = 'Las contraseñas no coinciden.';
    elseif (getSetting('recaptcha_secret') && !verificarRecaptcha($captcha))
        $error = 'Verifica que no eres un robot.';
    else {
        $chk = db()->prepare('SELECT id FROM users WHERE email=?');
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $error = 'Ya existe una cuenta con ese email.';
        } else {
            $verifyToken = generateToken(32);
            db()->prepare('INSERT INTO users (name,email,phone,password_hash,email_verify_token,email_verified) VALUES(?,?,?,?,?,0)')
               ->execute([$nombre, $email, $telefono, password_hash($pass, PASSWORD_BCRYPT), $verifyToken]);
            $link = baseUrl() . '/public/verificar.php?token=' . $verifyToken;
            $m = new Mailer();
            $res = $m->send($email, $nombre, 'Verifica tu email — ' . $siteName, Mailer::tplVerificacion(['name' => $nombre], $link));
            Auth::userLogin($email, $pass);
            if ($res['ok']) {
                flash('ok', '¡Cuenta creada! Te enviamos un email de verificación.');
            } else {
                flash('error', 'Cuenta creada, pero no pudimos enviarte el email de verificación (' . $res['error'] . '). Puedes reenviarlo desde "Mi cuenta".');
            }
            redirect('mi-cuenta.php');
        }
    }
}

function verificarRecaptcha(string $token): bool
{
    $secret = getSetting('recaptcha_secret');
    if (!$secret) return true;
    $res = @file_get_contents('https://www.google.com/recaptcha/api/siteverify?secret=' . urlencode($secret) . '&response=' . urlencode($token));
    $json = json_decode($res, true);
    return !empty($json['success']);
}
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Crear cuenta — <?= h($siteName) ?></title>
<link rel="stylesheet" href="assets/css/main.css">
</head><body>
<header class="site-header"><div class="inner">
  <a href="index.php" class="site-logo"><?= h($siteName) ?></a>
  <nav class="nav-links"><a href="login.php">Ya tengo cuenta</a></nav>
</div></header>
<main class="page-wrap"><div class="container-sm">
  <div class="card anim-fade">
    <h1 style="font-size:22px;font-weight:800;margin-bottom:6px;">Crear cuenta</h1>
    <p style="font-size:13px;color:#888;margin-bottom:18px;">Tu cuenta te permite inscribirte a eventos y gestionar tus entradas.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
      <div class="field"><label>Nombre completo *</label><input type="text" name="nombre" required autofocus value="<?= h($_POST['nombre'] ?? '') ?>"></div>
      <div class="field"><label>Email *</label><input type="email" name="email" required value="<?= h($_POST['email'] ?? '') ?>"></div>
      <div class="field"><label>Teléfono</label><input type="tel" name="telefono" value="<?= h($_POST['telefono'] ?? '') ?>"></div>
      <div class="field-row">
        <div class="field"><label>Contraseña * (mín. 8 caracteres)</label><input type="password" name="password" required minlength="8"></div>
        <div class="field"><label>Repetir contraseña *</label><input type="password" name="password2" required minlength="8"></div>
      </div>
      <?php if ($sk = getSetting('recaptcha_sitekey')): ?>
        <div class="g-recaptcha" data-sitekey="<?= h($sk) ?>" style="margin-bottom:14px;"></div>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
      <?php endif; ?>
      <button type="submit" class="btn btn-full">Crear cuenta</button>
    </form>
    <p style="text-align:center;font-size:13px;margin-top:16px;">¿Ya tienes cuenta? <a href="login.php">Inicia sesión</a></p>
  </div>
</div></main></body></html>
