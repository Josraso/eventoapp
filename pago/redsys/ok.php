<?php
// redsys/ok.php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
$siteName = getSetting('site_name', 'Eventos');
Auth::ensureSession();
$ord = preg_replace('/[^A-Za-z0-9]/', '', $_GET['Ds_Order'] ?? $_SESSION['redsys_order'] ?? '');
$insId = (int)($_SESSION['redsys_inscripcion_id'] ?? 0);
$stIns = db()->prepare('SELECT i.*, e.nombre as evento_nombre FROM inscripciones i JOIN eventos e ON e.id=i.evento_id WHERE i.redsys_order=?');
$stIns->execute([$ord]);
$ins = $stIns->fetch() ?: ($insId ? db()->prepare('SELECT i.*, e.nombre as evento_nombre FROM inscripciones i JOIN eventos e ON e.id=i.evento_id WHERE i.id=?')->execute([$insId]) && null : null);
$isOk = $ins && $ins['estado_pago'] === 'pagado';
$isPend = $ins && $ins['estado_pago'] === 'fallido';
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $isOk?'Pago confirmado':'Procesando...' ?> — <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= baseUrl() ?>/public/assets/css/main.css">
<?php if ($isPend): ?><meta http-equiv="refresh" content="3"><?php endif; ?>
</head><body>
<main class="page-wrap"><div class="container-sm">
  <div class="card anim-fade" style="text-align:center;padding:48px 24px;">
    <?php if ($isOk): ?>
      <div style="font-size:52px;margin-bottom:16px;">✅</div>
      <h2 style="font-size:22px;font-weight:800;margin-bottom:8px;">¡Pago confirmado!</h2>
      <p style="color:#888;margin-bottom:20px;">Tu inscripción está confirmada. Recibirás las entradas por email.</p>
      <a href="<?= baseUrl() ?>/public/mi-cuenta.php" class="btn">Ver mis entradas</a>
    <?php else: ?>
      <div style="font-size:52px;margin-bottom:16px;">⏳</div>
      <h2>Procesando pago...</h2>
      <p style="color:#888;">Verificando con el banco, espera un momento.</p>
    <?php endif; ?>
  </div>
</div></main></body></html>
