<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/_header.php';

// Aislamiento multi-admin: un admin (no superadmin) solo ve estadísticas de sus propios eventos
$esSuperAdmin = Auth::adminRole() === 'superadmin';
$evCond  = $esSuperAdmin ? '1=1' : 'e.admin_id=' . (int)Auth::adminId();
$evCondI = $esSuperAdmin ? '1=1' : 'i.evento_id IN (SELECT id FROM eventos WHERE admin_id=' . (int)Auth::adminId() . ')';
$evCondE = $esSuperAdmin ? '1=1' : 'e.evento_id IN (SELECT id FROM eventos WHERE admin_id=' . (int)Auth::adminId() . ')';
$usuariosSql = $esSuperAdmin ? 'COUNT(*) FROM users' : "COUNT(DISTINCT i.user_id) FROM inscripciones i WHERE $evCondI";

$stats = db()->query("
    SELECT
        (SELECT COUNT(*) FROM eventos e WHERE activo=1 AND archivado=0 AND $evCond) AS eventos_activos,
        (SELECT COUNT(*) FROM inscripciones i WHERE estado_pago='pagado' AND $evCondI) AS inscripciones_pagadas,
        (SELECT COUNT(*) FROM inscripciones i WHERE estado_pago='pendiente' AND $evCondI) AS inscripciones_pendientes,
        (SELECT $usuariosSql) AS usuarios,
        (SELECT COALESCE(SUM(precio_total),0) FROM inscripciones i WHERE estado_pago='pagado' AND $evCondI) AS total_ingresos,
        (SELECT COUNT(*) FROM entradas e WHERE usado=1 AND $evCondE) AS entradas_validadas,
        (SELECT COUNT(*) FROM entradas e JOIN inscripciones i ON i.id=e.inscripcion_id WHERE i.estado_pago='pagado' AND $evCondE) AS entradas_total
")->fetch();

$ultimasIns = db()->query("
    SELECT i.*, e.nombre as evento_nombre, u.name as user_name
    FROM inscripciones i
    JOIN eventos e ON e.id=i.evento_id
    JOIN users u ON u.id=i.user_id
    WHERE $evCond
    ORDER BY i.created_at DESC LIMIT 8
")->fetchAll();

$topEventos = db()->query("
    SELECT e.id, e.nombre,
        COUNT(ent.id) as pagados
    FROM eventos e
    LEFT JOIN inscripciones i ON i.evento_id=e.id AND i.estado_pago='pagado'
    LEFT JOIN entradas ent ON ent.inscripcion_id=i.id
    WHERE $evCond
    GROUP BY e.id, e.nombre
    ORDER BY pagados DESC LIMIT 5
")->fetchAll();
?>

<div class="stat-grid">
  <div class="stat-box"><div class="val"><?= (int)$stats['eventos_activos'] ?></div><div class="lbl">Eventos activos</div></div>
  <div class="stat-box"><div class="val"><?= (int)$stats['inscripciones_pagadas'] ?></div><div class="lbl">Pedidos pagados</div></div>
  <div class="stat-box" style="<?= $stats['inscripciones_pendientes']>0?'border-color:#f0d98a;':'' ?>">
    <div class="val" style="<?= $stats['inscripciones_pendientes']>0?'color:#c87f00;':'' ?>"><?= (int)$stats['inscripciones_pendientes'] ?></div>
    <div class="lbl">Pendientes de pago</div>
  </div>
  <div class="stat-box"><div class="val"><?= number_format((float)$stats['total_ingresos'],0,',','.') ?> €</div><div class="lbl">Ingresos confirmados</div></div>
  <div class="stat-box"><div class="val"><?= (int)$stats['usuarios'] ?></div><div class="lbl">Usuarios registrados</div></div>
  <div class="stat-box"><div class="val"><?= (int)$stats['entradas_validadas'] ?> / <?= (int)$stats['entradas_total'] ?></div><div class="lbl">Entradas validadas</div></div>
</div>

<?php if ($stats['inscripciones_pendientes'] > 0): ?>
<div class="alert alert-warning">
  ⚠️ Hay <strong><?= (int)$stats['inscripciones_pendientes'] ?></strong> inscripción(es) pendientes de confirmar pago.
  <a href="<?= h($base) ?>/admin/inscripciones/index.php?estado=pendiente" style="color:inherit;font-weight:700;">Ver →</a>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
  <div class="card">
    <div class="card-title">Últimas inscripciones</div>
    <div class="table-wrap">
      <table class="admin">
        <thead><tr><th>Evento</th><th>Usuario</th><th>Estado</th><th>Fecha</th></tr></thead>
        <tbody>
          <?php if (empty($ultimasIns)): ?>
            <tr><td colspan="4" style="color:#aaa;text-align:center;padding:20px;">Sin inscripciones aún</td></tr>
          <?php else: ?>
            <?php foreach ($ultimasIns as $ins):
              $bc = match($ins['estado_pago']) { 'pagado'=>'badge-green','pendiente'=>'badge-orange',default=>'badge-red' };
            ?>
            <tr>
              <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($ins['evento_nombre']) ?></td>
              <td style="max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($ins['user_name']) ?></td>
              <td><span class="badge <?= $bc ?>"><?= ucfirst($ins['estado_pago']) ?></span></td>
              <td style="font-size:12px;color:#aaa;"><?= date('d/m H:i', strtotime($ins['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div style="margin-top:12px;"><a href="<?= h($base) ?>/admin/inscripciones/index.php" class="btn btn-sm btn-outline">Ver todas →</a></div>
  </div>

  <div class="card">
    <div class="card-title">Eventos más activos</div>
    <div class="table-wrap">
      <table class="admin">
        <thead><tr><th>Evento</th><th>Pagadas</th></tr></thead>
        <tbody>
          <?php if (empty($topEventos)): ?>
            <tr><td colspan="2" style="color:#aaa;text-align:center;padding:20px;">Sin datos</td></tr>
          <?php else: ?>
            <?php foreach ($topEventos as $ev): ?>
            <tr>
              <td><a href="<?= h($base) ?>/admin/eventos/editar.php?id=<?= $ev['id'] ?>" style="color:#6366f1;text-decoration:none;"><?= h($ev['nombre']) ?></a></td>
              <td><strong><?= (int)$ev['pagados'] ?></strong></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div style="margin-top:12px;"><a href="<?= h($base) ?>/admin/eventos/index.php" class="btn btn-sm btn-outline">Ver eventos →</a></div>
  </div>
</div>

<?php require_once __DIR__ . '/_footer.php'; ?>
