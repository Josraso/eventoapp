<?php
// stripe/ok.php — Retorno de Stripe tras pago
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/TicketManager.php';
require_once __DIR__ . '/../../lib/Mailer.php';

$inscripcionId = (int)($_GET['inscripcion'] ?? 0);
$stIns = db()->prepare('SELECT i.*, e.nombre as evento_nombre, e.fecha_evento, e.lugar FROM inscripciones i JOIN eventos e ON e.id=i.evento_id WHERE i.id=?');
$stIns->execute([$inscripcionId]);
$ins = $stIns->fetch();
$siteName = getSetting('site_name', 'Eventos');

$ok = false;
if ($ins && $ins['stripe_payment_intent'] && getSetting('stripe_secret_key')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
    \Stripe\Stripe::setApiKey(getSetting('stripe_secret_key'));
    try {
        $pi = \Stripe\PaymentIntent::retrieve($ins['stripe_payment_intent']);
        if ($pi->status === 'succeeded' && $ins['estado_pago'] !== 'pagado') {
            db()->prepare("UPDATE inscripciones SET estado_pago='pagado', confirmado_at=NOW() WHERE id=?")
               ->execute([$inscripcionId]);
            // Generar PDFs y enviar email
            $paths = TicketManager::generarPDFsInscripcion($inscripcionId);
            $stUser = db()->prepare('SELECT * FROM users WHERE id=?');
            $stUser->execute([$ins['user_id']]);
            $user = $stUser->fetch();
            $stEv = db()->prepare('SELECT * FROM eventos WHERE id=?');
            $stEv->execute([$ins['evento_id']]);
            $evento = $stEv->fetch();
            $atts = array_map(fn($p) => ['path' => $p], $paths);
            $m = new Mailer();
            $m->send($user['email'], $user['name'],
                'Confirmación de tu pedido — ' . $evento['nombre'],
                Mailer::tplEntradas($ins, $evento, $user), $atts);
            db()->prepare('UPDATE inscripciones SET email_entradas_enviado=1 WHERE id=?')->execute([$inscripcionId]);
            $ok = true;
        } elseif ($pi->status === 'succeeded') {
            $ok = true; // Ya procesado
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $ok ? 'Pago confirmado' : 'Procesando...' ?> — <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= baseUrl() ?>/public/assets/css/main.css">
<?php if (!$ok): ?><meta http-equiv="refresh" content="3"><?php endif; ?>
</head><body>
<main class="page-wrap"><div class="container-sm">
  <div class="card anim-fade" style="text-align:center;padding:48px 24px;">
    <?php if ($ok): ?>
      <div style="font-size:52px;margin-bottom:16px;">✅</div>
      <h2 style="font-size:22px;font-weight:800;margin-bottom:8px;">¡Inscripción confirmada!</h2>
      <p style="color:#888;margin-bottom:20px;">Recibirás tus entradas por email en breve.</p>
      <a href="<?= baseUrl() ?>/public/mi-cuenta.php" class="btn">Ver mis entradas</a>
    <?php else: ?>
      <div style="font-size:52px;margin-bottom:16px;">⏳</div>
      <h2 style="font-size:20px;font-weight:700;margin-bottom:8px;">Confirmando pago...</h2>
      <p style="color:#888;">Espera un momento, verificando el pago con Stripe.</p>
    <?php endif; ?>
  </div>
</div></main></body></html>
