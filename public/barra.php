<?php
// barra.php — Compra de consumiciones SIN entrada, como pedido independiente.
// Usa los mismos métodos de pago/pasarelas que evento.php pero con la lista
// propia de métodos del evento (metodos_pago_barra) y sin crear entradas.
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/TicketManager.php';
require_once __DIR__ . '/../lib/Mailer.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['slug'] ?? ''));
if (!$slug) { redirect('index.php'); }

$stEv = db()->prepare('SELECT * FROM eventos WHERE slug=? AND activo=1');
$stEv->execute([$slug]);
$evento = $stEv->fetch();
if (!$evento) {
    flash('error', 'Evento no encontrado.');
    redirect('index.php');
}

$stProd = db()->prepare('SELECT * FROM productos_consumicion WHERE evento_id=? AND activo=1 ORDER BY sort_order ASC');
$stProd->execute([$evento['id']]);
$productosBarra = $stProd->fetchAll();

if (empty($productosBarra)) {
    flash('error', 'Este evento no tiene consumiciones disponibles.');
    redirect('evento.php?slug=' . urlencode($slug));
}

$metodosActivosBarra = array_filter(explode(',', $evento['metodos_pago_barra'] ?? 'stripe'));

if (!Auth::isUserLogged()) {
    redirect('login.php?back=' . urlencode('barra.php?slug=' . $slug));
}
$user  = Auth::getUser();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'comprar_barra') {
    Auth::checkCsrf();

    $productosBarraPorId = array_column($productosBarra, null, 'id');
    $seleccion = [];
    $total = 0.0;
    foreach ($_POST['consumiciones'] ?? [] as $pid => $qty) {
        $pid = (int)$pid;
        $qty = max(0, min(50, (int)$qty));
        if ($qty <= 0 || !isset($productosBarraPorId[$pid])) continue;
        $seleccion[$pid] = $qty;
        $total += (float)$productosBarraPorId[$pid]['precio'] * $qty;
    }

    $metodoPago = $_POST['metodo_pago'] ?? '';
    if (empty($seleccion)) {
        $error = 'Selecciona al menos una consumición.';
    } elseif ($total > 0 && !in_array($metodoPago, $metodosActivosBarra)) {
        $error = 'Método de pago no válido.';
    }

    if (!$error) {
        if ($total <= 0) $metodoPago = 'gratis';
        $numeroPedido = TicketManager::generarNumeroPedido();
        $estadoInicial = in_array($metodoPago, ['stripe', 'redsys']) ? 'fallido' : 'pendiente';

        db()->prepare('INSERT INTO inscripciones (numero_pedido,evento_id,user_id,num_personas,precio_total,metodo_pago,estado_pago) VALUES(?,?,?,0,?,?,?)')
           ->execute([$numeroPedido, $evento['id'], Auth::userId(), $total, $metodoPago, $estadoInicial]);
        $inscripcionId = (int)db()->lastInsertId();

        foreach ($seleccion as $pid => $qty) {
            TicketManager::crearConsumiciones($pid, $qty, $inscripcionId, 'online');
        }

        // Avisar al admin/superadmin del pedido recibido, independientemente
        // del método de pago o de si ya está confirmado.
        $insNueva = db()->prepare('SELECT * FROM inscripciones WHERE id=?');
        $insNueva->execute([$inscripcionId]);
        Mailer::notificarNuevoPedidoAdmin($insNueva->fetch(), $evento, $user);

        Auth::ensureSession();
        $_SESSION['inscripcion_id']     = $inscripcionId;
        $_SESSION['inscripcion_pedido'] = $numeroPedido;

        switch ($metodoPago) {
            case 'stripe':
                redirect('../pago/stripe.php?inscripcion=' . $inscripcionId);
                break;
            case 'redsys':
                redirect('../pago/redsys/iniciar.php?inscripcion=' . $inscripcionId);
                break;
            case 'bizum':
            case 'transferencia':
                $ins = db()->prepare('SELECT * FROM inscripciones WHERE id=?');
                $ins->execute([$inscripcionId]);
                $insRow = $ins->fetch();
                $m = new Mailer();
                $m->send($user['email'], $user['name'],
                    'Pedido de barra pendiente de pago — ' . $evento['nombre'],
                    Mailer::tplPedidoPendiente($insRow, $evento, $user, []));
                db()->prepare('UPDATE inscripciones SET email_pedido_enviado=1 WHERE id=?')->execute([$inscripcionId]);
                redirect('pedido-pendiente.php?pedido=' . urlencode($numeroPedido));
                break;
            case 'gratis':
                db()->prepare("UPDATE inscripciones SET estado_pago='pagado', confirmado_at=NOW() WHERE id=?")->execute([$inscripcionId]);
                $paths = TicketManager::generarPDFsInscripcion($inscripcionId);
                $atts = array_map(fn($p) => ['path' => $p], $paths);
                $ins = db()->prepare('SELECT * FROM inscripciones WHERE id=?');
                $ins->execute([$inscripcionId]);
                $insRow = $ins->fetch();
                $m = new Mailer();
                $m->send($user['email'], $user['name'], 'Tus consumiciones — ' . $evento['nombre'], Mailer::tplEntradas($insRow, $evento, $user), $atts);
                db()->prepare('UPDATE inscripciones SET email_entradas_enviado=1 WHERE id=?')->execute([$inscripcionId]);
                redirect('mi-cuenta.php?ok=1');
                break;
        }
    }
}

$siteName = getSetting('site_name', 'Eventos');
$logoPath = getSetting('logo_path');
$logoUrl  = ($logoPath && file_exists(__DIR__.'/../'.$logoPath)) ? '../'.$logoPath : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Comprar consumiciones — <?= h($evento['nombre']) ?> — <?= h($siteName) ?></title>
<link rel="stylesheet" href="assets/css/main.css">
</head>
<body>

<header class="site-header">
  <div class="inner">
    <a href="index.php" class="site-logo"><?php if ($logoUrl): ?><img src="<?= h($logoUrl) ?>"><?php else: ?><?= h($siteName) ?><?php endif; ?></a>
    <nav class="nav-links">
      <a href="mi-cuenta.php">Mi cuenta</a>
      <a href="logout.php">Salir</a>
    </nav>
  </div>
</header>

<main class="page-wrap">
  <div class="container-sm">
    <a href="evento.php?slug=<?= urlencode($slug) ?>" style="font-size:13px;color:#888;text-decoration:none;">← Volver al evento</a>
    <h1 style="font-size:22px;font-weight:800;margin:14px 0 4px;">🍹 Comprar consumiciones</h1>
    <p style="font-size:13px;color:#888;margin-bottom:20px;"><?= h($evento['nombre']) ?> — sin necesidad de comprar entrada.</p>

    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <form method="POST" action="" id="formBarra">
      <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
      <input type="hidden" name="action" value="comprar_barra">

      <div class="card">
        <div class="card-title">Consumiciones</div>
        <?php foreach ($productosBarra as $p): ?>
        <div class="field-row" style="align-items:center;">
          <div class="field" style="flex:1;margin-bottom:8px;">
            <label style="text-transform:none;font-weight:600;font-size:14px;"><?= h($p['nombre']) ?> — <?= number_format((float)$p['precio'], 2, ',', '.') ?> €</label>
          </div>
          <div class="field" style="max-width:90px;margin-bottom:8px;">
            <input type="number" name="consumiciones[<?= $p['id'] ?>]" value="0" min="0" max="50"
                   class="consumicion-qty" data-precio="<?= h(number_format((float)$p['precio'], 2, '.', '')) ?>"
                   onchange="recalcularTotalBarra()">
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="card">
        <div class="card-title">Método de pago</div>
        <div class="metodo-pago-list">
          <?php
          $metodosInfo = [
              'stripe'        => ['icon' => '💳', 'nombre' => 'Tarjeta de crédito/débito', 'desc' => 'Pago seguro con Stripe'],
              'redsys'        => ['icon' => '🏧', 'nombre' => 'Tarjeta (Redsys/TPV)', 'desc' => 'TPV bancario seguro'],
              'bizum'         => ['icon' => '📱', 'nombre' => 'Bizum', 'desc' => 'Transferencia Bizum al organizador'],
              'transferencia' => ['icon' => '🏦', 'nombre' => 'Transferencia bancaria', 'desc' => 'Transferencia bancaria al IBAN del organizador'],
          ];
          $primero = true;
          foreach ($metodosActivosBarra as $mid):
            if (!isset($metodosInfo[$mid])) continue;
            $info = $metodosInfo[$mid];
          ?>
          <label class="metodo-pago-opt <?= $primero ? 'selected' : '' ?>"
                 onclick="document.querySelectorAll('.metodo-pago-opt').forEach(e=>e.classList.remove('selected'));this.classList.add('selected')">
            <input type="radio" name="metodo_pago" value="<?= h($mid) ?>" <?= $primero ? 'checked' : '' ?>>
            <span class="metodo-pago-icon"><?= $info['icon'] ?></span>
            <div class="metodo-pago-info">
              <div class="nombre"><?= h($info['nombre']) ?></div>
              <div class="desc"><?= h($info['desc']) ?></div>
            </div>
          </label>
          <?php $primero = false; endforeach; ?>
        </div>
      </div>

      <div class="card" style="text-align:center;">
        <div style="font-size:13px;color:#888;margin-bottom:6px;">Total</div>
        <div id="totalBarra" style="font-size:28px;font-weight:800;margin-bottom:16px;">0,00 €</div>
        <button type="submit" class="btn btn-full">Comprar</button>
      </div>
    </form>
  </div>
</main>

<script>
function recalcularTotalBarra() {
    var total = 0;
    document.querySelectorAll('.consumicion-qty').forEach(function(el) {
        var qty = parseInt(el.value) || 0;
        total += qty * parseFloat(el.dataset.precio);
    });
    document.getElementById('totalBarra').textContent = total.toFixed(2).replace('.', ',') + ' €';
}
</script>
</body>
</html>
