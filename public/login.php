<?php
// login.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';

if (Auth::isUserLogged()) redirect('mi-cuenta.php');

$siteName = getSetting('site_name', 'Eventos');
$error = '';
$back  = preg_replace('/[^a-zA-Z0-9\/_\-\.\?=&%]/', '', $_GET['back'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    if (Auth::userLogin($email, $pass)) {
        redirect($back ?: 'mi-cuenta.php');
    } else {
        $error = 'Email o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Iniciar sesión — <?= h($siteName) ?></title>
<link rel="stylesheet" href="assets/css/main.css">
</head><body>
<header class="site-header"><div class="inner">
  <a href="index.php" class="site-logo"><?= h($siteName) ?></a>
  <nav class="nav-links"><a href="registro.php">Crear cuenta</a></nav>
</div></header>
<main class="page-wrap"><div class="container-sm">
  <div class="card anim-fade">
    <h1 style="font-size:22px;font-weight:800;margin-bottom:18px;">Iniciar sesión</h1>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <?php $flash = getFlash(); if ($flash): ?><div class="alert alert-<?= $flash['type']==='ok'?'success':$flash['type'] ?>"><?= h($flash['msg']) ?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
      <?php if ($back): ?><input type="hidden" name="back" value="<?= h($back) ?>"><?php endif; ?>
      <div class="field"><label>Email</label><input type="email" name="email" required autofocus></div>
      <div class="field"><label>Contraseña</label><input type="password" name="password" required></div>
      <button type="submit" class="btn btn-full">Iniciar sesión</button>
    </form>
    <p style="text-align:center;font-size:13px;margin-top:14px;"><a href="recuperar-password.php" style="color:#888;">¿Olvidaste tu contraseña?</a></p>
    <p style="text-align:center;font-size:13px;margin-top:8px;">¿No tienes cuenta? <a href="registro.php">Regístrate</a></p>
  </div>
</div></main></body></html>
