<?php
$pageTitle = 'Eventos';
require_once __DIR__ . '/../_header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $id = (int)($_POST['evento_id'] ?? 0);
    switch ($_POST['action'] ?? '') {
        case 'archivar':
            db()->prepare('UPDATE eventos SET archivado=1, fecha_archivo=NOW() WHERE id=?')->execute([$id]);
            flash('ok', 'Evento archivado.');
            break;
        case 'desarchivar':
            db()->prepare('UPDATE eventos SET archivado=0, fecha_archivo=NULL WHERE id=?')->execute([$id]);
            flash('ok', 'Evento desarchivado.');
            break;
        case 'toggle_activo':
            db()->prepare('UPDATE eventos SET activo = NOT activo WHERE id=?')->execute([$id]);
            flash('ok', 'Estado actualizado.');
            break;
        case 'eliminar':
            if ($adminRole === 'superadmin') {
                $chk = db()->prepare("SELECT COUNT(*) FROM inscripciones WHERE evento_id=? AND estado_pago='pagado'");
                $chk->execute([$id]);
                if ((int)$chk->fetchColumn() > 0) {
                    flash('error', 'No se puede eliminar un evento con inscripciones pagadas.');
                } else {
                    db()->prepare('DELETE FROM eventos WHERE id=?')->execute([$id]);
                    flash('ok', 'Evento eliminado.');
                }
            }
            break;
    }
    header('Location: ' . $base . '/admin/eventos/index.php');
    exit;
}

$filtro = $_GET['filtro'] ?? 'activos';
$where  = match($filtro) {
    'archivados' => 'WHERE e.archivado=1',
    'todos'      => '',
    default      => 'WHERE e.archivado=0',
};

$eventos = db()->query("
    SELECT e.*,
        (SELECT COUNT(*) FROM inscripciones i WHERE i.evento_id=e.id AND i.estado_pago='pagado') as total_pagados,
        (SELECT COUNT(*) FROM inscripciones i WHERE i.evento_id=e.id AND i.estado_pago='pendiente') as total_pendientes
    FROM eventos e $where
    ORDER BY e.archivado ASC, e.sort_order ASC, e.fecha_evento DESC
")->fetchAll();
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
  <div style="display:flex;gap:6px;">
    <a href="?filtro=activos"   class="btn btn-sm <?= $filtro==='activos'   ?'':'btn-outline' ?>">Activos</a>
    <a href="?filtro=archivados" class="btn btn-sm <?= $filtro==='archivados'?'':'btn-outline' ?>">Archivados</a>
    <a href="?filtro=todos"     class="btn btn-sm <?= $filtro==='todos'     ?'':'btn-outline' ?>">Todos</a>
  </div>
  <a href="<?= h($base) ?>/admin/eventos/crear.php" class="btn">+ Nuevo evento</a>
</div>

<div class="card" style="padding:0;">
  <div class="table-wrap">
    <table class="admin">
      <thead>
        <tr><th>Nombre</th><th>Fecha</th><th>Precio</th><th>Inscritos</th><th>Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php if (empty($eventos)): ?>
          <tr><td colspan="6" style="text-align:center;color:#aaa;padding:40px;">No hay eventos en esta vista.</td></tr>
        <?php else: ?>
          <?php foreach ($eventos as $ev): ?>
          <tr>
            <td>
              <div style="font-weight:600;"><?= h($ev['nombre']) ?></div>
              <div style="font-size:11px;color:#aaa;"><?= h($ev['slug']) ?></div>
            </td>
            <td><?= $ev['fecha_evento'] ? date('d/m/Y H:i', strtotime($ev['fecha_evento'])) : '<span style="color:#ccc">—</span>' ?></td>
            <td><?= $ev['es_gratuito'] ? '<span class="badge badge-green">Gratis</span>' : h(number_format((float)$ev['precio'],2,',','.')).' €' ?></td>
            <td>
              <strong><?= (int)$ev['total_pagados'] ?></strong>
              <?php if ($ev['max_inscritos']): ?> / <?= (int)$ev['max_inscritos'] ?><?php endif; ?>
              <?php if ($ev['total_pendientes'] > 0): ?>
                <span class="badge badge-orange" style="margin-left:4px;"><?= (int)$ev['total_pendientes'] ?> pend.</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($ev['archivado']): ?>
                <span class="badge badge-gray">Archivado</span>
              <?php elseif ($ev['activo']): ?>
                <span class="badge badge-green">Activo</span>
              <?php else: ?>
                <span class="badge badge-red">Oculto</span>
              <?php endif; ?>
            </td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <a href="<?= h($base) ?>/admin/eventos/editar.php?id=<?= $ev['id'] ?>" class="btn btn-sm btn-outline">✏️ Editar</a>
                <a href="<?= h($base) ?>/admin/inscripciones/index.php?evento_id=<?= $ev['id'] ?>" class="btn btn-sm btn-outline">📋 Inscritos</a>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
                  <input type="hidden" name="evento_id" value="<?= $ev['id'] ?>">
                  <?php if ($ev['archivado']): ?>
                    <input type="hidden" name="action" value="desarchivar">
                    <button type="submit" class="btn btn-sm btn-outline">📤 Desarchivar</button>
                  <?php else: ?>
                    <input type="hidden" name="action" value="archivar">
                    <button type="submit" class="btn btn-sm btn-outline">📥 Archivar</button>
                  <?php endif; ?>
                </form>
                <?php if ($adminRole === 'superadmin'): ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este evento?')">
                  <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
                  <input type="hidden" name="evento_id" value="<?= $ev['id'] ?>">
                  <input type="hidden" name="action" value="eliminar">
                  <button type="submit" class="btn btn-sm btn-danger">🗑</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../_footer.php'; ?>
