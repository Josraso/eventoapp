<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';

$eventos = db()->query("
    SELECT e.*,
        (SELECT COUNT(*) FROM entradas en JOIN inscripciones i ON i.id=en.inscripcion_id WHERE i.evento_id=e.id AND i.estado_pago='pagado') as total_inscritos
    FROM eventos e
    WHERE e.activo=1 AND e.archivado=0
    ORDER BY e.sort_order ASC, e.fecha_evento ASC
")->fetchAll();

$siteName = getSetting('site_name', 'Eventos');
$logoPath = getSetting('logo_path');
$logoUrl  = ($logoPath && file_exists(__DIR__.'/../'.$logoPath)) ? '../'.$logoPath : null;
$isLogged = Auth::isUserLogged();
$flash    = getFlash();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($siteName) ?></title>
<link rel="stylesheet" href="assets/css/main.css">
</head>
<body>

<header class="site-header">
  <div class="inner">
    <a href="index.php" class="site-logo"><?php if ($logoUrl): ?><img src="<?= h($logoUrl) ?>"><?php else: ?><?= h($siteName) ?><?php endif; ?></a>
    <nav class="nav-links">
      <?php if ($isLogged): ?>
        <a href="mi-cuenta.php">Mi cuenta</a>
        <a href="logout.php">Salir</a>
      <?php else: ?>
        <a href="login.php">Iniciar sesión</a>
        <a href="registro.php" class="btn-sm">Crear cuenta</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main class="page-wrap">
  <div class="container">
    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'ok' ? 'success' : $flash['type'] ?>">
        <?= h($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <h1 style="font-size:26px;font-weight:800;letter-spacing:-.03em;margin-bottom:6px;">Eventos</h1>
    <p style="color:#888;font-size:14px;margin-bottom:8px;">Inscríbete en los eventos disponibles</p>

    <?php if (empty($eventos)): ?>
      <div class="card" style="text-align:center;padding:60px 24px;">
        <div style="font-size:52px;margin-bottom:16px;">📭</div>
        <div style="font-size:18px;font-weight:700;margin-bottom:8px;">No hay eventos disponibles</div>
        <p style="color:#888;">Vuelve a comprobarlo pronto.</p>
      </div>
    <?php else: ?>
      <div class="eventos-grid">
        <?php foreach ($eventos as $ev):
          $lleno = $ev['max_inscritos'] && $ev['total_inscritos'] >= $ev['max_inscritos'];
          $cerrado = $ev['fecha_limite_inscripcion'] && strtotime($ev['fecha_limite_inscripcion']) < time();
          $disponible = !$lleno && !$cerrado;
          $fechasEv = fechasEvento($ev['id']);
        ?>
        <a href="evento.php?slug=<?= urlencode($ev['slug']) ?>" class="evento-card">
          <?php if ($ev['imagen']): ?>
            <img src="../<?= h($ev['imagen']) ?>" alt="<?= h($ev['nombre']) ?>" class="evento-card-img">
          <?php else: ?>
            <div class="evento-card-img">🎫</div>
          <?php endif; ?>
          <div class="evento-card-body">
            <div class="evento-card-nombre"><?= h($ev['nombre']) ?></div>
            <div class="evento-card-meta">
              <?php if (!empty($fechasEv)): ?>
                <span>📅 <?= count($fechasEv) > 1
                    ? h(date('d/m/Y', strtotime($fechasEv[0])) . ' - ' . date('d/m/Y', strtotime(end($fechasEv))))
                    : h(date('d/m/Y H:i', strtotime($fechasEv[0]))) ?></span>
              <?php endif; ?>
              <?php if ($ev['lugar']): ?>
                <span>📍 <?= h($ev['lugar']) ?></span>
              <?php endif; ?>
              <?php if ($ev['fecha_limite_inscripcion']): ?>
                <span style="<?= $cerrado ? 'color:#d33;' : '' ?>">⏰ Inscripción hasta <?= date('d/m/Y', strtotime($ev['fecha_limite_inscripcion'])) ?></span>
              <?php endif; ?>
            </div>
            <?php $descPlana = trim(html_entity_decode(strip_tags($ev['descripcion'] ?? ''))); if ($descPlana): ?>
              <p style="font-size:13px;color:#666;margin-bottom:12px;line-height:1.5;"><?= h(substr($descPlana, 0, 100)) . (strlen($descPlana) > 100 ? '...' : '') ?></p>
            <?php endif; ?>
            <div class="evento-card-footer">
              <div class="evento-precio <?= $ev['es_gratuito'] ? 'evento-precio-gratis' : '' ?>">
                <?= $ev['es_gratuito'] ? 'Gratuito' : number_format((float)$ev['precio'], 2, ',', '.') . ' €' ?>
              </div>
              <?php if (!$disponible): ?>
                <span class="badge badge-red"><?= $lleno ? 'Completo' : 'Cerrado' ?></span>
              <?php elseif ($ev['max_inscritos']): ?>
                <span class="badge badge-gray"><?= (int)($ev['max_inscritos'] - $ev['total_inscritos']) ?> plazas</span>
              <?php else: ?>
                <span class="badge badge-green">Disponible</span>
              <?php endif; ?>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php if ($footer = getSetting('site_footer')): ?>
<footer style="text-align:center;padding:24px;font-size:12px;color:#bbb;border-top:1px solid #e8e8e8;background:#fff;margin-top:40px;">
  <?= nl2br(h($footer)) ?>
</footer>
<?php endif; ?>
</body>
</html>
