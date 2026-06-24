<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Mailer.php';
require_once __DIR__ . '/../lib/TicketManager.php';

Auth::userCheck();
$user     = Auth::getUser();
$siteName = getSetting('site_name', 'Eventos');
$logoPath = getSetting('logo_path');
$logoUrl  = ($logoPath && file_exists(__DIR__.'/../'.$logoPath)) ? '../'.$logoPath : null;
$flash    = getFlash();
$error    = '';
$success  = '';

// ── ENVIAR ENTRADA INDIVIDUAL ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    Auth::checkCsrf();

    if ($_POST['action'] === 'enviar_entrada') {
        $entradaId  = (int)($_POST['entrada_id'] ?? 0);
        $emailDest  = filter_var(trim($_POST['email_destino'] ?? ''), FILTER_VALIDATE_EMAIL);

        // Verificar que la entrada pertenece al usuario
        $stE = db()->prepare('SELECT e.*, i.user_id, i.numero_pedido, i.evento_id as evid FROM entradas e JOIN inscripciones i ON i.id=e.inscripcion_id WHERE e.id=?');
        $stE->execute([$entradaId]);
        $entrada = $stE->fetch();

        if (!$entrada || $entrada['user_id'] != Auth::userId()) {
            $error = 'No tienes permiso para enviar esta entrada.';
        } elseif (!$emailDest) {
            $error = 'Introduce un email válido.';
        } else {
            // Generar PDF si no existe
            if (!$entrada['pdf_path'] || !file_exists(__DIR__ . '/../' . $entrada['pdf_path'])) {
                $stIns = db()->prepare('SELECT * FROM inscripciones WHERE id=?');
                $stIns->execute([$entrada['inscripcion_id']]);
                $inscripcion = $stIns->fetch();
                $stEv = db()->prepare('SELECT * FROM eventos WHERE id=?');
                $stEv->execute([$entrada['evid']]);
                $evento = $stEv->fetch();
                $pdfContent = TicketManager::generarPDF($entrada, $evento, $inscripcion);
                $pdfPath = TicketManager::guardarPDF($entradaId, $pdfContent);
            } else {
                $pdfPath = __DIR__ . '/../' . $entrada['pdf_path'];
            }

            $stEv2 = db()->prepare('SELECT * FROM eventos WHERE id=?');
            $stEv2->execute([$entrada['evid']]);
            $evData = $stEv2->fetch();

            $stIns2 = db()->prepare('SELECT * FROM inscripciones WHERE id=?');
            $stIns2->execute([$entrada['inscripcion_id']]);
            $insData = $stIns2->fetch();

            $m = new Mailer();
            $res = $m->send(
                $emailDest,
                $entrada['nombre_asistente'],
                'Tu entrada — ' . $evData['nombre'],
                Mailer::tplEntradaIndividual($entrada, $evData, $insData),
                [['path' => $pdfPath, 'name' => 'entrada_' . $entrada['nombre_asistente'] . '.pdf']]
            );
            if ($res['ok']) {
                $success = 'Entrada enviada correctamente a ' . $emailDest;
            } else {
                $error = 'Error al enviar: ' . $res['error'];
            }
        }
    }

    if ($_POST['action'] === 'reenviar_verificacion') {
        if ($user['email_verified']) {
            $success = 'Tu email ya está verificado.';
        } else {
            $verifyToken = generateToken(32);
            db()->prepare('UPDATE users SET email_verify_token=? WHERE id=?')->execute([$verifyToken, $user['id']]);
            $link = baseUrl() . '/public/verificar.php?token=' . $verifyToken;
            $m = new Mailer();
            $res = $m->send($user['email'], $user['name'], 'Verifica tu email — ' . $siteName, Mailer::tplVerificacion($user, $link));
            if ($res['ok']) {
                $success = 'Te hemos enviado un nuevo email de verificación a ' . $user['email'];
            } else {
                $error = 'No se pudo enviar el email: ' . $res['error'];
            }
        }
    }

    if ($_POST['action'] === 'cambiar_password') {
        $cur  = $_POST['cur'] ?? '';
        $new  = $_POST['new'] ?? '';
        $new2 = $_POST['new2'] ?? '';
        if (!password_verify($cur, $user['password_hash'])) {
            $error = 'La contraseña actual no es correcta.';
        } elseif (strlen($new) < 8) {
            $error = 'La nueva contraseña debe tener al menos 8 caracteres.';
        } elseif ($new !== $new2) {
            $error = 'Las contraseñas no coinciden.';
        } else {
            db()->prepare('UPDATE users SET password_hash=? WHERE id=?')
               ->execute([password_hash($new, PASSWORD_BCRYPT), Auth::userId()]);
            $success = 'Contraseña actualizada correctamente.';
        }
    }
}

// Cargar inscripciones del usuario
$stIns = db()->prepare("
    SELECT i.*, e.id as evt_id, e.nombre as evento_nombre, e.fecha_evento, e.lugar, e.slug as evento_slug, e.es_gratuito, e.archivado
    FROM inscripciones i
    JOIN eventos e ON e.id = i.evento_id
    WHERE i.user_id = ? AND i.estado_pago != 'fallido'
    ORDER BY i.created_at DESC
");
$stIns->execute([Auth::userId()]);
$inscripciones = $stIns->fetchAll();

// Construir, para cada pedido, sus entradas/consumiciones, si es mixto y si su evento ya pasó
$pedidosConDatos = [];
foreach ($inscripciones as $ins) {
    $stEnt = db()->prepare('SELECT * FROM entradas WHERE inscripcion_id=? ORDER BY es_titular DESC, id ASC');
    $stEnt->execute([$ins['id']]);
    $entradas = $stEnt->fetchAll();
    $entradasActivas = array_filter($entradas, fn($e) => !$e['usado']);
    $entradasCanjeadas = array_filter($entradas, fn($e) => $e['usado']);
    $stCons = db()->prepare('SELECT c.*, p.nombre as producto_nombre FROM consumiciones c JOIN productos_consumicion p ON p.id=c.producto_id WHERE c.inscripcion_id=? ORDER BY c.id ASC');
    $stCons->execute([$ins['id']]);
    $consumiciones = $stCons->fetchAll();
    $consActivas = array_filter($consumiciones, fn($c) => !$c['usado']);
    $consCanjeadas = array_filter($consumiciones, fn($c) => $c['usado']);
    $esMixto = !empty($entradas) && !empty($consumiciones);
    $esPasado = eventoFinalizado(['id' => $ins['evt_id'], 'archivado' => $ins['archivado'], 'fecha_evento' => $ins['fecha_evento']]);
    $pedidosConDatos[$ins['id']] = compact('ins','entradas','entradasActivas','entradasCanjeadas','consumiciones','consActivas','consCanjeadas','esMixto','esPasado');
}
$pedidosActuales = array_filter($pedidosConDatos, fn($pd) => !$pd['esPasado']);
$pedidosPasados   = array_filter($pedidosConDatos, fn($pd) => $pd['esPasado']);

$pedidosConEntradas = array_filter($pedidosActuales, fn($pd) => !empty($pd['entradas']));
$pedidosConConsumiciones = array_filter($pedidosActuales, fn($pd) => !empty($pd['consumiciones']));
$pedidosConEntradasPasadas = array_filter($pedidosPasados, fn($pd) => !empty($pd['entradas']));
$pedidosConConsumicionesPasadas = array_filter($pedidosPasados, fn($pd) => !empty($pd['consumiciones']));

function renderEntradaItem(array $ent, bool $canjeada): void
{
    ?>
    <div class="entrada-item<?= $canjeada ? ' is-canjeada' : '' ?>">
      <div class="entrada-nombre"><?= h($ent['nombre_asistente']) ?> <?= $ent['es_titular'] ? '<span class="badge badge-blue">titular</span>' : '' ?></div>
      <div class="entrada-token">QR: <?= h(substr($ent['qr_token'], 0, 12)) ?>...<?= !$canjeada && !empty($ent['codigo_corto']) ? ' &middot; Código: <strong>' . h($ent['codigo_corto']) . '</strong>' : '' ?></div>
      <?php if ($canjeada): ?>
        <div style="margin-top:8px;">
          <span class="badge badge-green">✓ Canjeada</span>
          <?php if ($ent['usado_at']): ?>
            <span style="font-size:11px;color:#aaa;margin-left:6px;"><?= date('d/m/Y H:i', strtotime($ent['usado_at'])) ?></span>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="entrada-actions">
          <button type="button" class="btn btn-sm btn-success" onclick="mostrarQR('entrada', <?= $ent['id'] ?>, '<?= h(addslashes($ent['nombre_asistente'])) ?>')">📱 QR</button>
          <a href="descargar-entrada.php?id=<?= $ent['id'] ?>" target="_blank" class="btn btn-sm btn-outline">⬇</a>
          <button class="btn btn-sm" onclick="toggleEnviar(<?= $ent['id'] ?>)">📧</button>
        </div>
        <div class="enviar-form" id="enviar-<?= $ent['id'] ?>">
          <form method="POST" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
            <input type="hidden" name="action" value="enviar_entrada">
            <input type="hidden" name="entrada_id" value="<?= $ent['id'] ?>">
            <div class="field" style="flex:1;min-width:160px;margin-bottom:0;">
              <label>Email del asistente</label>
              <input type="email" name="email_destino" required placeholder="asistente@email.com">
            </div>
            <button type="submit" class="btn btn-sm btn-success">Enviar</button>
            <button type="button" class="btn btn-sm btn-outline" onclick="toggleEnviar(<?= $ent['id'] ?>)">Cancelar</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
    <?php
}

function renderConsumicionItem(array $cons, bool $canjeada): void
{
    ?>
    <div class="entrada-item is-consumicion<?= $canjeada ? ' is-canjeada' : '' ?>">
      <div class="entrada-nombre"><?= h($cons['producto_nombre']) ?></div>
      <div class="entrada-token">QR: <?= h(substr($cons['qr_token'], 0, 12)) ?>...<?= !$canjeada && !empty($cons['codigo_corto']) ? ' &middot; Código: <strong>' . h($cons['codigo_corto']) . '</strong>' : '' ?></div>
      <?php if ($canjeada): ?>
        <div style="margin-top:8px;">
          <span class="badge badge-green">✓ Canjeada</span>
          <?php if ($cons['usado_at']): ?>
            <span style="font-size:11px;color:#aaa;margin-left:6px;"><?= date('d/m/Y H:i', strtotime($cons['usado_at'])) ?></span>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="entrada-actions">
          <span class="badge badge-gray">Pendiente</span>
          <button type="button" class="btn btn-sm btn-success" onclick="mostrarQR('consumicion', <?= $cons['id'] ?>, '<?= h(addslashes($cons['producto_nombre'])) ?>')">📱 QR</button>
        </div>
      <?php endif; ?>
    </div>
    <?php
}

// Renderiza el recuadro de un pedido dentro de la tab "entradas" o "consumiciones"
function renderPedidoBox(array $pd, string $tipo): void
{
    $ins      = $pd['ins'];
    $esMixto  = $pd['esMixto'];
    $activos    = $tipo === 'entradas' ? $pd['entradasActivas']   : $pd['consActivas'];
    $canjeados  = $tipo === 'entradas' ? $pd['entradasCanjeadas'] : $pd['consCanjeadas'];
    $items      = $tipo === 'entradas' ? $pd['entradas']          : $pd['consumiciones'];
    $otroTipo   = $tipo === 'entradas' ? 'consumiciones' : 'entradas';
    $otroLabel  = $tipo === 'entradas' ? '🍹 Ver consumiciones de este pedido' : '🎫 Ver entradas de este pedido';
    $tieneOtro  = $tipo === 'entradas' ? !empty($pd['consumiciones']) : !empty($pd['entradas']);

    $badgeClass = match($ins['estado_pago']) {
        'pagado'      => 'badge-green',
        'pendiente'   => 'badge-orange',
        'cancelado'   => 'badge-red',
        default       => 'badge-gray',
    };
    $estadoLabel = match($ins['estado_pago']) {
        'pagado'    => 'Pagado',
        'pendiente' => 'Pendiente',
        'cancelado' => 'Cancelado',
        default     => $ins['estado_pago'],
    };
    ?>
    <div class="pedido-box" id="pedido-<?= $tipo ?>-<?= $ins['id'] ?>">
      <div class="pedido-head">
        <div>
          <div style="font-size:12px;color:#aaa;margin-bottom:2px;"><?= h($ins['evento_nombre']) ?></div>
          <div class="pedido-meta"><strong>Pedido <?= h($ins['numero_pedido']) ?></strong> &middot; <strong><?= date('d/m/Y', strtotime($ins['created_at'])) ?></strong></div>
          <span class="badge <?= $badgeClass ?>" style="margin-top:6px;display:inline-block;"><?= $estadoLabel ?></span>
          <?php if ($esMixto): ?><span class="badge badge-purple" style="margin-top:6px;display:inline-block;margin-left:4px;">🔀 Mixto</span><?php endif; ?>
        </div>
        <div style="text-align:right;">
          <?php if (!$ins['es_gratuito']): ?>
            <div style="font-size:18px;font-weight:800;"><?= number_format((float)$ins['precio_total'], 2, ',', '.') ?> €</div>
          <?php endif; ?>
          <?php if ($ins['estado_pago'] === 'pagado' && !empty($items)): ?>
            <a href="descargar-pedido.php?id=<?= $ins['id'] ?>" target="_blank" class="btn btn-sm btn-outline" style="margin-top:6px;">⬇ PDF del pedido</a>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($tieneOtro): ?>
        <div style="margin-bottom:14px;">
          <a href="#" onclick="goToPedido('<?= $otroTipo ?>', <?= $ins['id'] ?>); return false;" class="badge badge-purple" style="text-decoration:none;">🔀 Mixto &middot; <?= $otroLabel ?> →</a>
        </div>
      <?php endif; ?>

      <?php if ($ins['estado_pago'] === 'pendiente' && in_array($ins['metodo_pago'], ['bizum','transferencia'])): ?>
        <div class="alert alert-warning" style="font-size:13px;margin-bottom:14px;">
          ⏳ Pago pendiente de confirmación. Revisa tu email con las instrucciones de pago.
        </div>
      <?php endif; ?>

      <?php if ($ins['estado_pago'] === 'pagado' && !empty($items)): ?>
        <?php if (empty($activos)): ?>
          <p style="font-size:13px;color:#aaa;margin-bottom:10px;">Todas las <?= $tipo === 'entradas' ? 'entradas' : 'consumiciones' ?> de este pedido ya han sido canjeadas.</p>
        <?php else: ?>
        <div class="tickets-grid">
        <?php foreach ($activos as $item): ?>
          <?php $tipo === 'entradas' ? renderEntradaItem($item, false) : renderConsumicionItem($item, false); ?>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($canjeados)): ?>
        <div class="canjeadas-toggle" onclick="toggleCanjeadas('canj-<?= $tipo ?>-<?= $ins['id'] ?>')">▾ Ver <?= $tipo === 'entradas' ? 'entradas' : 'consumiciones' ?> canjeadas (<?= count($canjeados) ?>)</div>
        <div id="canj-<?= $tipo ?>-<?= $ins['id'] ?>" style="display:none;margin-top:10px;">
          <div class="tickets-grid">
          <?php foreach ($canjeados as $item): ?>
            <?php $tipo === 'entradas' ? renderEntradaItem($item, true) : renderConsumicionItem($item, true); ?>
          <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
}

// Renderiza el bloque de tabs "Entradas" / "Consumiciones" con sus pedidos,
// usado tanto para inscripciones actuales como para pasadas (sufijo distinto
// para no duplicar ids en el DOM).
function renderTipoTabs(array $pedidosConEntradas, array $pedidosConConsumiciones, string $sufijo): void
{
    if (empty($pedidosConEntradas) && empty($pedidosConConsumiciones)) {
        ?>
        <p style="font-size:13px;color:#aaa;">No hay pedidos en esta sección.</p>
        <?php
        return;
    }
    ?>
    <div class="tabs" id="tipoTabs-<?= $sufijo ?>">
      <button type="button" class="tab active" onclick="showTipoTab('entradas','<?= $sufijo ?>',this)">🎫 Entradas (<?= count($pedidosConEntradas) ?>)</button>
      <button type="button" class="tab" onclick="showTipoTab('consumiciones','<?= $sufijo ?>',this)">🍹 Consumiciones (<?= count($pedidosConConsumiciones) ?>)</button>
    </div>

    <?php
      $entActivos    = array_filter($pedidosConEntradas, fn($pd) => $pd['ins']['estado_pago']!=='pagado' || !empty($pd['entradasActivas']));
      $entCanjeados  = array_filter($pedidosConEntradas, fn($pd) => $pd['ins']['estado_pago']==='pagado' && empty($pd['entradasActivas']));
      $consActivosP  = array_filter($pedidosConConsumiciones, fn($pd) => $pd['ins']['estado_pago']!=='pagado' || !empty($pd['consActivas']));
      $consCanjeadosP= array_filter($pedidosConConsumiciones, fn($pd) => $pd['ins']['estado_pago']==='pagado' && empty($pd['consActivas']));
    ?>

    <div class="tipo-tab-panel active" id="tipo-tab-entradas-<?= $sufijo ?>">
      <?php if (empty($pedidosConEntradas)): ?>
        <p style="font-size:13px;color:#aaa;">No tienes pedidos con entradas.</p>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:16px;">
        <?php foreach ($entActivos as $pd): renderPedidoBox($pd, 'entradas'); endforeach; ?>
        </div>
        <?php if (empty($entActivos)): ?>
          <p style="font-size:13px;color:#aaa;">Todos los pedidos de entradas ya han sido canjeados.</p>
        <?php endif; ?>
        <?php if (!empty($entCanjeados)): ?>
          <div class="canjeadas-toggle" onclick="toggleCanjeadas('pedCanj-entradas-<?= $sufijo ?>')">▾ Ver pedidos canjeados (<?= count($entCanjeados) ?>)</div>
          <div id="pedCanj-entradas-<?= $sufijo ?>" style="display:none;margin-top:14px;flex-direction:column;gap:16px;">
            <?php foreach ($entCanjeados as $pd): renderPedidoBox($pd, 'entradas'); endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="tipo-tab-panel" id="tipo-tab-consumiciones-<?= $sufijo ?>" style="display:none;">
      <?php if (empty($pedidosConConsumiciones)): ?>
        <p style="font-size:13px;color:#aaa;">No tienes pedidos con consumiciones.</p>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:16px;">
        <?php foreach ($consActivosP as $pd): renderPedidoBox($pd, 'consumiciones'); endforeach; ?>
        </div>
        <?php if (empty($consActivosP)): ?>
          <p style="font-size:13px;color:#aaa;">Todos los pedidos de consumiciones ya han sido canjeados.</p>
        <?php endif; ?>
        <?php if (!empty($consCanjeadosP)): ?>
          <div class="canjeadas-toggle" onclick="toggleCanjeadas('pedCanj-consumiciones-<?= $sufijo ?>')">▾ Ver pedidos canjeados (<?= count($consCanjeadosP) ?>)</div>
          <div id="pedCanj-consumiciones-<?= $sufijo ?>" style="display:none;margin-top:14px;flex-direction:column;gap:16px;">
            <?php foreach ($consCanjeadosP as $pd): renderPedidoBox($pd, 'consumiciones'); endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mi cuenta — <?= h($siteName) ?></title>
<link rel="stylesheet" href="assets/css/main.css">
<style>
.tabs { display: flex; gap: 4px; border-bottom: 2px solid #e8e8e8; margin-bottom: 24px; }
.tab { padding: 10px 18px; font-size: 14px; font-weight: 600; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; color: #888; transition: color .15s; background: none; border-top: none; border-left: none; border-right: none; font-family: inherit; }
.tab.active { color: #1a1a1a; border-bottom-color: #1a1a1a; }
.tab-panel { display: none; }
.tab-panel.active { display: block; }
.tickets-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr)); gap: 10px; }
.entrada-item { position: relative; border: 1px solid #e4e4e8; border-left: 4px solid #6366f1; border-radius: 10px; padding: 12px 14px 12px 16px; background: #fafaff; }
.entrada-item.is-consumicion { border-left-color: #c87f00; background: #fffaf2; }
.entrada-item.is-canjeada { border-left-color: #c8c8c8; background: #fafafa; opacity: .7; }
.entrada-nombre { font-size: 14px; font-weight: 700; margin-bottom: 2px; }
.entrada-token { font-family: monospace; font-size: 10.5px; color: #aaa; word-break: break-all; }
.entrada-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
.enviar-form { display: none; margin-top: 12px; padding-top: 12px; border-top: 1px solid #f0f0f0; }
.enviar-form.open { display: block; }
.pedido-box { border: 2px solid #d8d8e2; border-radius: 12px; padding: 18px 20px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.pedido-head { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid #f0f0f0; }
.pedido-meta { font-size: 13px; color: #555; }
.pedido-tipo-titulo { font-size: 12px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 10px; }
.canjeadas-toggle { font-size: 12px; color: #888; cursor: pointer; margin-top: 12px; user-select: none; display: inline-block; }
.canjeadas-toggle:hover { color: #1a1a1a; }
.badge-purple { background: #f3f0ff; color: #5a1aaa; }
</style>
</head>
<body>

<header class="site-header">
  <div class="inner">
    <a href="index.php" class="site-logo"><?php if ($logoUrl): ?><img src="<?= h($logoUrl) ?>"><?php else: ?><?= h($siteName) ?><?php endif; ?></a>
    <nav class="nav-links">
      <a href="index.php">Eventos</a>
      <a href="logout.php">Cerrar sesión</a>
    </nav>
  </div>
</header>

<main class="page-wrap">
  <div class="container">
    <h1 style="font-size:24px;font-weight:800;margin-bottom:6px;">Mi cuenta</h1>
    <p style="font-size:14px;color:#888;margin-bottom:24px;">Hola, <strong><?= h($user['name']) ?></strong>
      <?php if (!$user['email_verified']): ?>
        <span class="badge badge-orange">Email sin verificar</span>
        <form method="POST" style="display:inline-block;margin-left:6px;">
          <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
          <input type="hidden" name="action" value="reenviar_verificacion">
          <button type="submit" class="btn btn-sm btn-outline" style="vertical-align:middle;">Reenviar email de verificación</button>
        </form>
      <?php endif; ?>
    </p>

    <?php if ($flash): ?><div class="alert alert-<?= $flash['type']==='ok'?'success':$flash['type'] ?>"><?= h($flash['msg']) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>

    <div class="tabs" id="mainTabs">
      <button class="tab active" onclick="showTab('inscripciones',this)">Mis inscripciones</button>
      <button class="tab" onclick="showTab('pasadas',this)">Mis inscripciones pasadas (<?= count($pedidosPasados) ?>)</button>
      <button class="tab" onclick="showTab('cuenta',this)">Datos de cuenta</button>
    </div>

    <!-- TAB: INSCRIPCIONES ACTUALES -->
    <div class="tab-panel active" id="tab-inscripciones">
      <?php if (empty($inscripciones)): ?>
        <div class="card" style="text-align:center;padding:48px 24px;">
          <div style="font-size:48px;margin-bottom:14px;">🎫</div>
          <div style="font-size:17px;font-weight:700;margin-bottom:8px;">Sin inscripciones</div>
          <p style="color:#888;margin-bottom:20px;">Todavía no te has inscrito a ningún evento.</p>
          <a href="index.php" class="btn">Ver eventos disponibles</a>
        </div>
      <?php elseif (empty($pedidosActuales)): ?>
        <p style="font-size:13px;color:#aaa;">No tienes inscripciones en eventos actuales o próximos. Mira la pestaña "Mis inscripciones pasadas".</p>
      <?php else: ?>
        <?php renderTipoTabs($pedidosConEntradas, $pedidosConConsumiciones, 'actual'); ?>
      <?php endif; ?>
    </div>

    <!-- TAB: INSCRIPCIONES PASADAS -->
    <div class="tab-panel" id="tab-pasadas">
      <?php if (empty($pedidosPasados)): ?>
        <p style="font-size:13px;color:#aaa;">No tienes inscripciones de eventos ya finalizados.</p>
      <?php else: ?>
        <?php renderTipoTabs($pedidosConEntradasPasadas, $pedidosConConsumicionesPasadas, 'pasado'); ?>
      <?php endif; ?>
    </div>

    <!-- TAB: CUENTA -->
    <div class="tab-panel" id="tab-cuenta">
      <div class="card">
        <div class="card-title">Mis datos</div>
        <table style="width:100%;font-size:14px;">
          <tr><td style="color:#888;padding:8px 0;border-bottom:1px solid #f0f0f0;">Nombre</td><td style="font-weight:600;padding:8px 0;border-bottom:1px solid #f0f0f0;text-align:right;"><?= h($user['name']) ?></td></tr>
          <tr><td style="color:#888;padding:8px 0;border-bottom:1px solid #f0f0f0;">Email</td><td style="font-weight:600;padding:8px 0;border-bottom:1px solid #f0f0f0;text-align:right;"><?= h($user['email']) ?> <?= $user['email_verified'] ? '✓' : '<span class="badge badge-orange">Sin verificar</span>' ?></td></tr>
          <tr><td style="color:#888;padding:8px 0;">Teléfono</td><td style="font-weight:600;padding:8px 0;text-align:right;"><?= h($user['phone'] ?: '—') ?></td></tr>
        </table>
      </div>

      <div class="card">
        <div class="card-title">Cambiar contraseña</div>
        <form method="POST">
          <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
          <input type="hidden" name="action" value="cambiar_password">
          <div class="field"><label>Contraseña actual</label><input type="password" name="cur" required style="max-width:300px;"></div>
          <div class="field-row" style="max-width:460px;">
            <div class="field"><label>Nueva contraseña</label><input type="password" name="new" required minlength="8"></div>
            <div class="field"><label>Repetir</label><input type="password" name="new2" required minlength="8"></div>
          </div>
          <button type="submit" class="btn btn-sm">Cambiar contraseña</button>
        </form>
      </div>
    </div>
  </div>
</main>

<div id="qrModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:200;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;padding:24px;max-width:340px;width:90%;text-align:center;">
    <div id="qrModalTitulo" style="font-size:15px;font-weight:700;margin-bottom:14px;"></div>
    <img id="qrModalImg" src="" style="width:100%;max-width:280px;border-radius:8px;">
    <button type="button" class="btn btn-sm btn-outline" style="margin-top:16px;width:100%;" onclick="cerrarQR()">Cerrar</button>
  </div>
</div>

<script>
function showTab(id, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('#mainTabs .tab').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + id).classList.add('active');
    btn.classList.add('active');
}
function showTipoTab(tipo, sufijo, btn) {
    document.querySelectorAll('#tipoTabs-' + sufijo + ' .tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tipo-tab-entradas-' + sufijo).style.display = 'none';
    document.getElementById('tipo-tab-consumiciones-' + sufijo).style.display = 'none';
    document.getElementById('tipo-tab-' + tipo + '-' + sufijo).style.display = 'block';
}
function goToPedido(tipo, pedidoId) {
    var pedidoBox = document.getElementById('pedido-' + tipo + '-' + pedidoId);
    if (!pedidoBox) return;
    var sufijo = pedidoBox.closest('#tab-pasadas') ? 'pasado' : 'actual';
    var btn = document.querySelector('#tipoTabs-' + sufijo + ' .tab:nth-child(' + (tipo === 'entradas' ? 1 : 2) + ')');
    if (btn) showTipoTab(tipo, sufijo, btn);
    pedidoBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function toggleEnviar(id) {
    var el = document.getElementById('enviar-' + id);
    el.classList.toggle('open');
}
function mostrarQR(tipo, id, nombre) {
    document.getElementById('qrModalTitulo').textContent = nombre;
    document.getElementById('qrModalImg').src = 'qr-imagen.php?tipo=' + tipo + '&id=' + id;
    document.getElementById('qrModal').style.display = 'flex';
}
function cerrarQR() {
    document.getElementById('qrModal').style.display = 'none';
}
function toggleCanjeadas(id) {
    var el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>
</body>
</html>
