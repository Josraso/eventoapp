<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';

Auth::userCheck();

$pedido = preg_replace('/[^A-Z0-9\-]/', '', strtoupper($_GET['pedido'] ?? ''));
$stIns = db()->prepare('SELECT i.*, e.nombre as evento_nombre, e.fecha_evento, e.lugar FROM inscripciones i JOIN eventos e ON e.id=i.evento_id WHERE i.numero_pedido=? AND i.user_id=?');
$stIns->execute([$pedido, Auth::userId()]);
$ins = $stIns->fetch();
if (!$ins) { redirect('mi-cuenta.php'); }

$siteName = getSetting('site_name', 'Eventos');
$logoPath = getSetting('logo_path');
$logoUrl  = ($logoPath && file_exists(__DIR__.'/../'.$logoPath)) ? '../'.$logoPath : null;
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pedido pendiente — <?= h($siteName) ?></title>
<link rel="stylesheet" href="assets/css/main.css">
</head><body>
<header class="site-header"><div class="inner">
  <a href="index.php" class="site-logo"><?php if ($logoUrl): ?><img src="<?= h($logoUrl) ?>"><?php else: ?><?= h($siteName) ?><?php endif; ?></a>
  <nav class="nav-links"><a href="mi-cuenta.php">Mi cuenta</a></nav>
</div></header>
<main class="page-wrap"><div class="container-sm">
  <div class="card anim-fade">
    <div style="text-align:center;margin-bottom:20px;">
      <div style="font-size:52px;margin-bottom:12px;">⏳</div>
      <h1 style="font-size:22px;font-weight:800;margin-bottom:6px;">Inscripción recibida</h1>
      <p style="color:#888;font-size:14px;">Tu solicitud está pendiente de confirmación de pago.</p>
    </div>
    <div class="alert alert-warning">
      Hemos enviado las instrucciones de pago a tu email. Una vez confirmemos el pago, recibirás las entradas.
    </div>
    <table style="width:100%;font-size:14px;margin-top:14px;">
      <tr><td style="color:#888;padding:8px 0;border-bottom:1px solid #f0f0f0;">Evento</td><td style="font-weight:600;text-align:right;padding:8px 0;border-bottom:1px solid #f0f0f0;"><?= h($ins['evento_nombre']) ?></td></tr>
      <tr><td style="color:#888;padding:8px 0;border-bottom:1px solid #f0f0f0;">Número de pedido</td><td style="font-weight:700;text-align:right;padding:8px 0;border-bottom:1px solid #f0f0f0;font-family:monospace;"><?= h($ins['numero_pedido']) ?></td></tr>
      <tr><td style="color:#888;padding:8px 0;border-bottom:1px solid #f0f0f0;">Método de pago</td><td style="font-weight:600;text-align:right;padding:8px 0;border-bottom:1px solid #f0f0f0;"><?= ucfirst($ins['metodo_pago']) ?></td></tr>
      <tr><td style="color:#888;padding:8px 0;">Total</td><td style="font-weight:700;text-align:right;padding:8px 0;"><?= number_format((float)$ins['precio_total'],2,',','.') ?> €</td></tr>
    </table>
    <?php if ($ins['metodo_pago'] === 'bizum'): ?>
      <div class="alert alert-info" style="margin-top:16px;">
        📱 <strong>Bizum al:</strong> <?= h(getSetting('bizum_telefono', 'Ver email')) ?><br>
        Concepto: <strong><?= h($ins['numero_pedido']) ?></strong> + tu nombre
      </div>
    <?php elseif ($ins['metodo_pago'] === 'transferencia'): ?>
      <div class="alert alert-info" style="margin-top:16px;">
        🏦 <strong>Transferencia a:</strong> <?= h(getSetting('transferencia_iban', 'Ver email')) ?><br>
        Concepto: <strong><?= h($ins['numero_pedido']) ?></strong> + tu nombre
      </div>
    <?php endif; ?>
    <a href="mi-cuenta.php" class="btn btn-full btn-outline" style="margin-top:20px;">Ver mis inscripciones</a>
  </div>
</div></main></body></html>
