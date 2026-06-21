<?php
// verificar.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';

$token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
$ok = false;
if ($token) {
    $st = db()->prepare('SELECT id FROM users WHERE email_verify_token=? AND email_verified=0');
    $st->execute([$token]);
    $u = $st->fetch();
    if ($u) {
        db()->prepare('UPDATE users SET email_verified=1, email_verify_token=NULL WHERE id=?')->execute([$u['id']]);
        $ok = true;
    }
}
$siteName = getSetting('site_name', 'Eventos');
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Verificación — <?= h($siteName) ?></title>
<link rel="stylesheet" href="assets/css/main.css">
</head><body>
<main class="page-wrap"><div class="container-sm">
  <div class="card anim-fade" style="text-align:center;padding:48px 24px;">
    <?php if ($ok): ?>
      <div style="font-size:52px;margin-bottom:16px;">✅</div>
      <h2 style="font-size:20px;font-weight:700;margin-bottom:10px;">Email verificado</h2>
      <p style="color:#888;margin-bottom:20px;">Tu cuenta está completamente activa.</p>
      <a href="mi-cuenta.php" class="btn">Ir a mi cuenta</a>
    <?php else: ?>
      <div style="font-size:52px;margin-bottom:16px;">❌</div>
      <h2 style="font-size:20px;font-weight:700;margin-bottom:10px;">Enlace no válido</h2>
      <p style="color:#888;margin-bottom:20px;">El enlace ya fue usado o ha expirado.</p>
      <a href="index.php" class="btn">Ir al inicio</a>
    <?php endif; ?>
  </div>
</div></main></body></html>
