<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

Auth::adminCheck('superadmin', 'admin');
$base = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : getSetting('app_base_url'), '/');

$id = (int)($_GET['id'] ?? 0);
$stIns = db()->prepare('SELECT i.*, e.nombre as evento_nombre, e.fecha_evento, e.lugar, e.campo_qr_extra,
    u.name as user_name, u.email as user_email, u.phone as user_phone
    FROM inscripciones i
    JOIN eventos e ON e.id=i.evento_id
    JOIN users u ON u.id=i.user_id
    WHERE i.id=?');
$stIns->execute([$id]);
$ins = $stIns->fetch();
if (!$ins) { flash('error','Inscripción no encontrada.'); header('Location: ' . $base . '/admin/inscripciones/index.php'); exit; }
if (Auth::adminRole() !== 'superadmin') {
    $stOwn = db()->prepare('SELECT admin_id FROM eventos WHERE id=?');
    $stOwn->execute([$ins['evento_id']]);
    if ((int)$stOwn->fetchColumn() !== Auth::adminId()) {
        flash('error','No tienes permiso sobre este pedido.'); header('Location: ' . $base . '/admin/inscripciones/index.php'); exit;
    }
}

$stEnt = db()->prepare('SELECT * FROM entradas WHERE inscripcion_id=? ORDER BY es_titular DESC, id ASC');
$stEnt->execute([$id]);
$entradas = $stEnt->fetchAll();

$stCons = db()->prepare('SELECT c.*, p.nombre as producto_nombre FROM consumiciones c JOIN productos_consumicion p ON p.id=c.producto_id WHERE c.inscripcion_id=? ORDER BY c.id ASC');
$stCons->execute([$id]);
$consumiciones = $stCons->fetchAll();

// Campos del evento
$stCampos = db()->prepare('SELECT * FROM evento_campos WHERE evento_id=? ORDER BY sort_order');
$stCampos->execute([$ins['evento_id']]);
$campos = $stCampos->fetchAll();
$camposById = array_column($campos, null, 'nombre');

// Procesar acción
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'guardar_nota') {
        db()->prepare('UPDATE inscripciones SET notas_admin=? WHERE id=?')->execute([$_POST['nota'],$id]);
        flash('ok','Nota guardada.');
        header('Location: ' . $base . '/admin/inscripciones/detalle.php?id='.$id); exit;
    }
    if ($action === 'editar_pedido') {
        $nuevoEstado = $_POST['estado_pago'] ?? $ins['estado_pago'];
        $nuevoMetodo = $_POST['metodo_pago'] ?? $ins['metodo_pago'];
        $nuevoPrecio = (float)str_replace(',', '.', $_POST['precio_total'] ?? $ins['precio_total']);
        db()->prepare('UPDATE inscripciones SET estado_pago=?, metodo_pago=?, precio_total=? WHERE id=?')
           ->execute([$nuevoEstado, $nuevoMetodo, $nuevoPrecio, $id]);
        Auth::logAction('editar_pedido', 'Inscripción #'.$id.' pedido:'.$ins['numero_pedido']);
        flash('ok','Pedido actualizado.');
        header('Location: ' . $base . '/admin/inscripciones/detalle.php?id='.$id); exit;
    }
    if ($action === 'editar_entrada') {
        $entId = (int)($_POST['entrada_id'] ?? 0);
        $stChk = db()->prepare('SELECT id FROM entradas WHERE id=? AND inscripcion_id=?');
        $stChk->execute([$entId, $id]);
        if ($stChk->fetch()) {
            $nuevoNombre = trim($_POST['nombre_asistente'] ?? '');
            $extras = [];
            foreach ($campos as $c) {
                $extras[$c['nombre']] = trim($_POST['campo_'.$c['id']] ?? '');
            }
            if ($nuevoNombre) {
                db()->prepare('UPDATE entradas SET nombre_asistente=?, campos_extra=? WHERE id=?')
                   ->execute([$nuevoNombre, json_encode($extras), $entId]);
                flash('ok','Entrada actualizada.');
            }
        }
        header('Location: ' . $base . '/admin/inscripciones/detalle.php?id='.$id); exit;
    }
    if ($action === 'confirmar_pago') {
        require_once __DIR__ . '/../../lib/TicketManager.php';
        require_once __DIR__ . '/../../lib/Mailer.php';
        db()->prepare("UPDATE inscripciones SET estado_pago='pagado', confirmado_por=?, confirmado_at=NOW() WHERE id=?")
           ->execute([Auth::adminId(),$id]);
        $paths = TicketManager::generarPDFsInscripcion($id);
        $stUser = db()->prepare('SELECT * FROM users WHERE id=?');
        $stUser->execute([$ins['user_id']]);
        $user = $stUser->fetch();
        $stEv = db()->prepare('SELECT * FROM eventos WHERE id=?');
        $stEv->execute([$ins['evento_id']]);
        $evento = $stEv->fetch();
        $atts = array_map(fn($p) => ['path'=>$p], $paths);
        $m = new Mailer();
        $m->send($user['email'],$user['name'],'Confirmación de tu pedido de '.mb_strtolower(etiquetaPedido($id)).' — '.$evento['nombre'],
            Mailer::tplEntradas($ins,$evento,$user),$atts);
        db()->prepare('UPDATE inscripciones SET email_entradas_enviado=1 WHERE id=?')->execute([$id]);
        Auth::logAction('confirmar_pago','Inscripción #'.$id);
        flash('ok','Pago confirmado y entradas enviadas.');
        header('Location: ' . $base . '/admin/inscripciones/detalle.php?id='.$id); exit;
    }
    if ($action === 'eliminar_entrada') {
        $entId = (int)($_POST['entrada_id'] ?? 0);
        $stChk = db()->prepare('SELECT id FROM entradas WHERE id=? AND inscripcion_id=?');
        $stChk->execute([$entId, $id]);
        if ($stChk->fetch()) {
            db()->prepare('DELETE FROM entradas WHERE id=?')->execute([$entId]);
            flash('ok','Entrada eliminada.');
        }
        header('Location: ' . $base . '/admin/inscripciones/detalle.php?id='.$id); exit;
    }
    if ($action === 'eliminar_pedido') {
        db()->prepare('DELETE FROM inscripciones WHERE id=?')->execute([$id]);
        Auth::logAction('eliminar_inscripcion', 'Inscripción #' . $id . ' pedido:' . $ins['numero_pedido']);
        flash('ok','Pedido eliminado.');
        header('Location: ' . $base . '/admin/inscripciones/index.php'); exit;
    }
    if ($action === 'reenviar_entradas') {
        require_once __DIR__ . '/../../lib/TicketManager.php';
        require_once __DIR__ . '/../../lib/Mailer.php';
        $paths = TicketManager::generarPDFsInscripcion($id);
        $stUser = db()->prepare('SELECT * FROM users WHERE id=?');
        $stUser->execute([$ins['user_id']]);
        $user = $stUser->fetch();
        $stEv = db()->prepare('SELECT * FROM eventos WHERE id=?');
        $stEv->execute([$ins['evento_id']]);
        $evento = $stEv->fetch();
        $atts = array_map(fn($p) => ['path'=>$p], $paths);
        $m = new Mailer();
        $m->send($user['email'],$user['name'],'Confirmación de tu pedido de '.mb_strtolower(etiquetaPedido($id)).' — '.$evento['nombre'],
            Mailer::tplEntradas($ins,$evento,$user),$atts);
        flash('ok','Entradas reenviadas al cliente.');
        header('Location: ' . $base . '/admin/inscripciones/detalle.php?id='.$id); exit;
    }
}

$pageTitle = 'Detalle inscripción';
require_once __DIR__ . '/../_header.php';

$bc = match($ins['estado_pago']) { 'pagado'=>'badge-green','pendiente'=>'badge-orange',default=>'badge-red' };
?>

<a href="<?= h($base) ?>/admin/inscripciones/index.php" style="font-size:13px;color:#888;text-decoration:none;">← Volver a inscripciones</a>
<div style="margin-top:16px;display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start;">

  <div>
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;">
        <div>
          <div style="font-size:11px;color:#aaa;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Inscripción</div>
          <div style="font-size:22px;font-weight:800;font-family:monospace;"><?= h($ins['numero_pedido']) ?></div>
        </div>
        <span class="badge <?= $bc ?>" style="font-size:13px;padding:6px 14px;"><?= ucfirst($ins['estado_pago']) ?></span>
      </div>
      <table style="width:100%;font-size:14px;">
        <tr><td style="color:#888;padding:8px 0;border-bottom:1px solid #f0f0f0;width:40%;">Evento</td><td style="padding:8px 0;border-bottom:1px solid #f0f0f0;font-weight:600;"><?= h($ins['evento_nombre']) ?></td></tr>
        <?php if ($ins['fecha_evento']): ?>
        <tr><td style="color:#888;padding:8px 0;border-bottom:1px solid #f0f0f0;">Fecha evento</td><td style="padding:8px 0;border-bottom:1px solid #f0f0f0;"><?= date('d/m/Y H:i',strtotime($ins['fecha_evento'])) ?></td></tr>
        <?php endif; ?>
        <tr><td style="color:#888;padding:8px 0;border-bottom:1px solid #f0f0f0;">Cliente</td><td style="padding:8px 0;border-bottom:1px solid #f0f0f0;"><strong><?= h($ins['user_name']) ?></strong><br><span style="font-size:12px;color:#888;"><?= h($ins['user_email']) ?></span></td></tr>
        <tr><td style="color:#888;padding:8px 0;border-bottom:1px solid #f0f0f0;">Personas</td><td style="padding:8px 0;border-bottom:1px solid #f0f0f0;"><?= (int)$ins['num_personas'] ?></td></tr>
        <tr><td style="color:#888;padding:8px 0;border-bottom:1px solid #f0f0f0;">Método de pago</td><td style="padding:8px 0;border-bottom:1px solid #f0f0f0;"><?= ucfirst($ins['metodo_pago']) ?></td></tr>
        <tr><td style="color:#888;padding:8px 0;border-bottom:1px solid #f0f0f0;"><strong>Total</strong></td><td style="padding:8px 0;border-bottom:1px solid #f0f0f0;font-size:18px;font-weight:800;"><?= number_format((float)$ins['precio_total'],2,',','.') ?> €</td></tr>
        <?php if ($ins['confirmado_at']): ?>
        <tr><td style="color:#888;padding:8px 0;border-bottom:1px solid #f0f0f0;">Confirmado</td><td style="padding:8px 0;border-bottom:1px solid #f0f0f0;"><?= date('d/m/Y H:i',strtotime($ins['confirmado_at'])) ?></td></tr>
        <?php endif; ?>
        <tr><td style="color:#888;padding:8px 0;">Creada</td><td style="padding:8px 0;"><?= date('d/m/Y H:i',strtotime($ins['created_at'])) ?></td></tr>
      </table>
    </div>

    <!-- ENTRADAS -->
    <div class="card">
      <div class="card-title">Entradas (<?= count($entradas) ?>)</div>
      <?php foreach ($entradas as $ent):
        $extras = !empty($ent['campos_extra']) ? (is_string($ent['campos_extra']) ? json_decode($ent['campos_extra'],true) : $ent['campos_extra']) : [];
      ?>
      <div style="border:1px solid #e4e4e8;border-radius:10px;padding:14px;margin-bottom:10px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
          <div>
            <div style="font-size:14px;font-weight:700;"><?= h($ent['nombre_asistente']) ?> <?= $ent['es_titular']?'<span class="badge badge-blue">titular</span>':'' ?></div>
            <?php if (!empty($extras)): ?>
              <?php foreach ($extras as $k=>$v): if ($v): ?>
                <div style="font-size:12px;color:#888;margin-top:3px;"><?= h(ucfirst(str_replace('_',' ',$k))) ?>: <strong><?= h($v) ?></strong></div>
              <?php endif; endforeach; ?>
            <?php endif; ?>
          </div>
          <div style="text-align:right;">
            <?php if ($ent['usado']): ?>
              <span class="badge badge-green">✓ Validada</span>
              <div style="font-size:11px;color:#aaa;margin-top:3px;"><?= date('d/m/Y H:i',strtotime($ent['usado_at'])) ?></div>
            <?php else: ?>
              <span class="badge badge-gray">Sin validar</span>
            <?php endif; ?>
          </div>
        </div>
        <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
          <code style="font-size:11px;color:#aaa;"><?= h(substr($ent['qr_token'],0,20)) ?>...</code>
          <?php if (!empty($ent['codigo_corto'])): ?>
            <span class="badge badge-blue" style="font-family:monospace;letter-spacing:.05em;">Código: <?= h($ent['codigo_corto']) ?></span>
          <?php endif; ?>
          <a href="descargar-pdf.php?id=<?= $ins['id'] ?>" class="btn btn-sm btn-outline" target="_blank">⬇ PDF</a>
          <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('edit-ent-<?= $ent['id'] ?>').style.display='block';this.style.display='none';">✎ Editar</button>
          <?php if (count($entradas) > 1): ?>
          <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar esta entrada?')">
            <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
            <input type="hidden" name="action" value="eliminar_entrada">
            <input type="hidden" name="entrada_id" value="<?= $ent['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">🗑</button>
          </form>
          <?php endif; ?>
        </div>
        <form method="POST" id="edit-ent-<?= $ent['id'] ?>" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid #f0f0f0;">
          <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
          <input type="hidden" name="action" value="editar_entrada">
          <input type="hidden" name="entrada_id" value="<?= $ent['id'] ?>">
          <div class="field">
            <label>Nombre asistente</label>
            <input type="text" name="nombre_asistente" value="<?= h($ent['nombre_asistente']) ?>">
          </div>
          <?php foreach ($campos as $c): ?>
          <div class="field">
            <label><?= h($c['nombre']) ?></label>
            <input type="text" name="campo_<?= $c['id'] ?>" value="<?= h($extras[$c['nombre']] ?? '') ?>">
          </div>
          <?php endforeach; ?>
          <button type="submit" class="btn btn-sm" style="width:100%;">Guardar cambios</button>
        </form>
      </div>
      <?php endforeach; ?>
      <?php if (empty($entradas)): ?>
        <p style="font-size:13px;color:#aaa;">Este pedido no tiene entradas.</p>
      <?php endif; ?>
    </div>

    <!-- CONSUMICIONES -->
    <?php if (!empty($consumiciones)): ?>
    <div class="card">
      <div class="card-title">Consumiciones (<?= count($consumiciones) ?>)</div>
      <?php foreach ($consumiciones as $cons): ?>
      <div style="border:1px solid #e4e4e8;border-radius:10px;padding:14px;margin-bottom:10px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
          <div>
            <div style="font-size:14px;font-weight:700;"><?= h($cons['producto_nombre']) ?></div>
            <div style="font-size:12px;color:#888;margin-top:3px;"><?= number_format((float)$cons['precio'],2,',','.') ?> €</div>
          </div>
          <div style="text-align:right;">
            <?php if ($cons['usado']): ?>
              <span class="badge badge-green">✓ Canjeada</span>
              <div style="font-size:11px;color:#aaa;margin-top:3px;"><?= date('d/m/Y H:i',strtotime($cons['usado_at'])) ?></div>
            <?php else: ?>
              <span class="badge badge-gray">Sin canjear</span>
            <?php endif; ?>
          </div>
        </div>
        <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
          <code style="font-size:11px;color:#aaa;"><?= h(substr($cons['qr_token'],0,20)) ?>...</code>
          <?php if (!empty($cons['codigo_corto'])): ?>
            <span class="badge badge-blue" style="font-family:monospace;letter-spacing:.05em;">Código: <?= h($cons['codigo_corto']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($ins['estado_pago'] === 'pagado' && (!empty($entradas) || !empty($consumiciones))): ?>
    <div class="card" style="text-align:center;">
      <a href="descargar-pdf.php?id=<?= $ins['id'] ?>" class="btn btn-outline" target="_blank">⬇ Descargar PDF del pedido</a>
    </div>
    <?php endif; ?>
  </div>

  <!-- SIDEBAR ACCIONES -->
  <div>
    <?php if ($ins['estado_pago'] === 'pendiente'): ?>
    <div class="card" style="border:2px solid #f0d98a;">
      <div class="card-title">Confirmar pago</div>
      <p style="font-size:13px;color:#888;margin-bottom:14px;">Confirma el pago manual (Bizum o Transferencia). Se enviarán las entradas al cliente.</p>
      <form method="POST" onsubmit="return confirm('¿Confirmar el pago y enviar entradas al cliente?')">
        <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
        <input type="hidden" name="action" value="confirmar_pago">
        <button type="submit" class="btn btn-success" style="width:100%;">✓ Confirmar pago</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($ins['estado_pago'] === 'pagado'): ?>
    <div class="card">
      <div class="card-title">Reenviar entradas</div>
      <p style="font-size:13px;color:#888;margin-bottom:12px;">Reenvía todas las entradas al email del cliente.</p>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
        <input type="hidden" name="action" value="reenviar_entradas">
        <button type="submit" class="btn btn-outline" style="width:100%;">📧 Reenviar entradas</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-title">Editar pedido</div>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
        <input type="hidden" name="action" value="editar_pedido">
        <div class="field">
          <label>Estado de pago</label>
          <select name="estado_pago">
            <?php foreach (['pendiente','pagado','cancelado','fallido','reembolsado'] as $est): ?>
            <option value="<?= $est ?>" <?= $ins['estado_pago']===$est?'selected':'' ?>><?= ucfirst($est) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Método de pago</label>
          <select name="metodo_pago">
            <?php foreach (['stripe','redsys','bizum','transferencia','gratis'] as $met): ?>
            <option value="<?= $met ?>" <?= $ins['metodo_pago']===$met?'selected':'' ?>><?= ucfirst($met) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>Precio total (€)</label>
          <input type="text" name="precio_total" value="<?= h($ins['precio_total']) ?>">
        </div>
        <button type="submit" class="btn btn-sm btn-outline" style="width:100%;">Guardar pedido</button>
      </form>
    </div>

    <div class="card" style="border:2px solid #fcc;">
      <div class="card-title">Eliminar pedido</div>
      <p style="font-size:13px;color:#888;margin-bottom:12px;">Borra este pedido y todas sus entradas de forma permanente.</p>
      <form method="POST" onsubmit="return confirm('¿Eliminar este pedido y todas sus entradas? Esta acción no se puede deshacer.')">
        <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
        <input type="hidden" name="action" value="eliminar_pedido">
        <button type="submit" class="btn btn-danger" style="width:100%;">🗑 Eliminar pedido</button>
      </form>
    </div>

    <div class="card">
      <div class="card-title">Nota interna</div>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(Auth::csrfToken()) ?>">
        <input type="hidden" name="action" value="guardar_nota">
        <textarea name="nota" rows="4" style="width:100%;border:1.5px solid #e0e0e0;border-radius:8px;padding:10px;font-size:13px;font-family:inherit;resize:vertical;"><?= h($ins['notas_admin'] ?? '') ?></textarea>
        <button type="submit" class="btn btn-sm btn-outline" style="margin-top:8px;width:100%;">Guardar nota</button>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../_footer.php'; ?>
