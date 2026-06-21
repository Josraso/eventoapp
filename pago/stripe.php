<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/TicketManager.php';
require_once __DIR__ . '/../lib/Mailer.php';

Auth::userCheck();

$inscripcionId = (int)($_GET['inscripcion'] ?? 0);
$stIns = db()->prepare('SELECT i.*, e.nombre as evento_nombre, e.fecha_evento FROM inscripciones i JOIN eventos e ON e.id=i.evento_id WHERE i.id=? AND i.user_id=?');
$stIns->execute([$inscripcionId, Auth::userId()]);
$ins = $stIns->fetch();

if (!$ins || $ins['estado_pago'] !== 'pendiente') {
    redirect(baseUrl() . '/public/mi-cuenta.php');
}

$user     = Auth::getUser();
$siteName = getSetting('site_name', 'Eventos');
$stripeKey = getSetting('stripe_public_key');
$stripeSecret = getSetting('stripe_secret_key');

// Crear PaymentIntent si no existe
$clientSecret = '';
if ($stripeSecret) {
    require_once __DIR__ . '/../vendor/autoload.php';
    \Stripe\Stripe::setApiKey($stripeSecret);
    try {
        if ($ins['stripe_payment_intent']) {
            $pi = \Stripe\PaymentIntent::retrieve($ins['stripe_payment_intent']);
        } else {
            $pi = \Stripe\PaymentIntent::create([
                'amount'   => (int)round((float)$ins['precio_total'] * 100),
                'currency' => 'eur',
                'metadata' => [
                    'inscripcion_id' => $inscripcionId,
                    'numero_pedido'  => $ins['numero_pedido'],
                ],
                'description' => $ins['evento_nombre'] . ' — ' . $ins['numero_pedido'],
            ]);
            db()->prepare('UPDATE inscripciones SET stripe_payment_intent=? WHERE id=?')
               ->execute([$pi->id, $inscripcionId]);
        }
        $clientSecret = $pi->client_secret;
    } catch (Exception $e) {
        $stripeError = $e->getMessage();
    }
}
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pago con tarjeta — <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= baseUrl() ?>/public/assets/css/main.css">
<script src="https://js.stripe.com/v3/"></script>
</head><body>
<header class="site-header"><div class="inner">
  <a href="<?= baseUrl() ?>/public/index.php" class="site-logo"><?= h($siteName) ?></a>
</div></header>
<main class="page-wrap"><div class="container-sm">
  <div class="card anim-fade">
    <h1 style="font-size:20px;font-weight:700;margin-bottom:6px;">Pago con tarjeta</h1>
    <p style="font-size:13px;color:#888;margin-bottom:18px;"><?= h($ins['evento_nombre']) ?></p>

    <?php if (!empty($stripeError)): ?>
      <div class="alert alert-error">Error Stripe: <?= h($stripeError) ?></div>
    <?php elseif (!$stripeKey): ?>
      <div class="alert alert-warning">Stripe no está configurado. Contacta con el organizador.</div>
    <?php else: ?>
      <div class="card" style="background:#f8f8f8;border:none;margin-bottom:14px;">
        <table style="width:100%;font-size:14px;">
          <tr><td style="color:#888;padding:6px 0;">Evento</td><td style="font-weight:600;text-align:right;"><?= h($ins['evento_nombre']) ?></td></tr>
          <tr><td style="color:#888;padding:6px 0;">Pedido</td><td style="font-family:monospace;text-align:right;"><?= h($ins['numero_pedido']) ?></td></tr>
          <tr><td style="color:#888;padding:6px 0;">Personas</td><td style="font-weight:600;text-align:right;"><?= (int)$ins['num_personas'] ?></td></tr>
          <tr><td style="color:#888;padding:6px 0;"><strong>Total</strong></td><td style="font-weight:800;font-size:18px;text-align:right;"><?= number_format((float)$ins['precio_total'],2,',','.') ?> €</td></tr>
        </table>
      </div>
      <div id="payment-element" style="margin-bottom:16px;"></div>
      <div id="stripe-error" class="alert alert-error" style="display:none;"></div>
      <button id="btn-pagar" class="btn btn-full" onclick="pagar()">💳 Pagar <?= number_format((float)$ins['precio_total'],2,',','.') ?> €</button>
      <p style="text-align:center;font-size:11px;color:#bbb;margin-top:10px;">🔒 Pago seguro procesado por Stripe</p>
    <?php endif; ?>
  </div>
</div></main>

<script>
var stripe = Stripe('<?= h($stripeKey) ?>');
var elements = stripe.elements({clientSecret: '<?= h($clientSecret) ?>'});
var paymentElement = elements.create('payment');
paymentElement.mount('#payment-element');

async function pagar() {
    var btn = document.getElementById('btn-pagar');
    btn.disabled = true; btn.textContent = 'Procesando...';
    var errEl = document.getElementById('stripe-error');
    errEl.style.display = 'none';

    var {error} = await stripe.confirmPayment({
        elements,
        confirmParams: {
            return_url: '<?= baseUrl() ?>/pago/stripe/ok.php?inscripcion=<?= $inscripcionId ?>'
        }
    });
    if (error) {
        errEl.textContent = error.message;
        errEl.style.display = 'flex';
        btn.disabled = false;
        btn.textContent = '💳 Pagar <?= number_format((float)$ins['precio_total'],2,',','.') ?> €';
    }
}
</script>
</body></html>
