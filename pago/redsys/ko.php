<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/RedsysAPI.php';
$siteName = getSetting('site_name', 'Eventos');
$resp = preg_replace('/[^0-9]/', '', $_GET['Ds_Response'] ?? '9999');
$txt  = RedsysAPI::getResponseText($resp);
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pago no completado — <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= baseUrl() ?>/public/assets/css/main.css">
</head><body>
<main class="page-wrap"><div class="container-sm">
  <div class="card anim-fade" style="text-align:center;padding:48px 24px;">
    <div style="font-size:52px;margin-bottom:16px;">❌</div>
    <h2 style="font-size:20px;font-weight:700;margin-bottom:8px;">Pago no completado</h2>
    <p style="color:#888;margin-bottom:14px;">No se ha realizado ningún cargo en tu tarjeta.</p>
    <div class="alert alert-error" style="text-align:left;"><?= h($txt) ?></div>
    <div style="display:flex;gap:12px;justify-content:center;margin-top:20px;">
      <a href="<?= baseUrl() ?>/public/index.php" class="btn btn-outline">Volver a eventos</a>
      <a href="<?= baseUrl() ?>/public/mi-cuenta.php" class="btn">Mi cuenta</a>
    </div>
  </div>
</div></main></body></html>
