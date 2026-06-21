<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

Auth::adminCheck('superadmin', 'admin');
$base = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : getSetting('app_base_url'), '/');
$esSuperAdmin = Auth::adminRole() === 'superadmin';

$pageTitle = 'Consumiciones';
require_once __DIR__ . '/../_header.php';

// ── FILTROS ────────────────────────────────────────────────────────────────────
$eventoFiltro = (int)($_GET['evento_id'] ?? 0);
$origenFiltro = $_GET['origen'] ?? '';
$q            = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
if (!$esSuperAdmin) { $where[] = 'e.admin_id=?'; $params[] = Auth::adminId(); }
if ($eventoFiltro) { $where[] = 'c.evento_id=?'; $params[] = $eventoFiltro; }
if ($origenFiltro) { $where[] = 'c.origen=?'; $params[] = $origenFiltro; }
if ($q) {
    $qlike = '%' . $q . '%';
    $where[] = '(p.nombre LIKE ? OR c.nombre_comprador LIKE ? OR i.numero_pedido LIKE ?)';
    array_push($params, $qlike, $qlike, $qlike);
}
$whereStr = implode(' AND ', $where);

// Select de eventos para filtro
$eventos = $esSuperAdmin
    ? db()->query("SELECT id, nombre FROM eventos ORDER BY nombre ASC")->fetchAll()
    : (function() {
        $st = db()->prepare("SELECT id, nombre FROM eventos WHERE admin_id=? ORDER BY nombre ASC");
        $st->execute([Auth::adminId()]);
        return $st->fetchAll();
    })();

// ── TOTALES GENERALES ─────────────────────────────────────────────────────────
$stTot = db()->prepare("
    SELECT COUNT(*) as cnt, SUM(c.precio) as suma, SUM(c.usado) as canjeadas
    FROM consumiciones c
    JOIN eventos e ON e.id=c.evento_id
    JOIN productos_consumicion p ON p.id=c.producto_id
    LEFT JOIN inscripciones i ON i.id=c.inscripcion_id
    WHERE $whereStr
");
$stTot->execute($params);
$tot = $stTot->fetch();

// ── TOTALES POR PRODUCTO ──────────────────────────────────────────────────────
$stProd = db()->prepare("
    SELECT p.nombre as producto_nombre, e.nombre as evento_nombre, COUNT(*) as cnt,
        SUM(c.precio) as suma, SUM(c.usado) as canjeadas
    FROM consumiciones c
    JOIN eventos e ON e.id=c.evento_id
    JOIN productos_consumicion p ON p.id=c.producto_id
    LEFT JOIN inscripciones i ON i.id=c.inscripcion_id
    WHERE $whereStr
    GROUP BY c.producto_id
    ORDER BY cnt DESC
");
$stProd->execute($params);
$porProducto = $stProd->fetchAll();

// ── PEDIDOS QUE INCLUYEN CONSUMICIONES (online) ───────────────────────────────
$stPedidos = db()->prepare("
    SELECT i.id, i.numero_pedido, i.estado_pago, i.created_at, i.metodo_pago,
        e.nombre as evento_nombre, u.name as user_name, u.email as user_email,
        COUNT(c.id) as num_consumiciones, SUM(c.precio) as importe_consumiciones,
        SUM(c.usado) as canjeadas
    FROM consumiciones c
    JOIN eventos e ON e.id=c.evento_id
    JOIN productos_consumicion p ON p.id=c.producto_id
    JOIN inscripciones i ON i.id=c.inscripcion_id
    JOIN users u ON u.id=i.user_id
    WHERE $whereStr AND c.origen='online'
    GROUP BY i.id
    ORDER BY i.created_at DESC
    LIMIT 100
");
$stPedidos->execute($params);
$pedidos = $stPedidos->fetchAll();

// ── VENTAS EN CAJA (sin pedido) ───────────────────────────────────────────────
$stCaja = db()->prepare("
    SELECT c.id, c.nombre_comprador, c.precio, c.created_at, c.usado,
        p.nombre as producto_nombre, e.nombre as evento_nombre
    FROM consumiciones c
    JOIN eventos e ON e.id=c.evento_id
    JOIN productos_consumicion p ON p.id=c.producto_id
    LEFT JOIN inscripciones i ON i.id=c.inscripcion_id
    WHERE $whereStr AND c.origen='caja'
    ORDER BY c.created_at DESC
    LIMIT 100
");
$stCaja->execute($params);
$ventasCaja = $stCaja->fetchAll();
?>

<div class="filters">
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <select name="evento_id" onchange="this.form.submit()">
      <option value="">Todos los eventos</option>
      <?php foreach ($eventos as $ev): ?>
        <option value="<?= $ev['id'] ?>" <?= $ev['id']==$eventoFiltro?'selected':'' ?>><?= h($ev['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="origen" onchange="this.form.submit()">
      <option value="">Todos los orígenes</option>
      <option value="online" <?= $origenFiltro==='online'?'selected':'' ?>>Online (pedido)</option>
      <option value="caja" <?= $origenFiltro==='caja'?'selected':'' ?>>Venta en caja</option>
    </select>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar producto, comprador, pedido…" style="min-width:240px;">
    <button type="submit" class="btn btn-sm">Buscar</button>
    <?php if ($eventoFiltro || $origenFiltro || $q): ?>
      <a href="<?= h($base) ?>/admin/consumiciones/index.php" class="btn btn-sm btn-outline">✕ Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<div class="stat-grid">
  <div class="stat-box">
    <div class="val"><?= (int)($tot['cnt'] ?? 0) ?></div>
    <div class="lbl">Consumiciones vendidas</div>
  </div>
  <div class="stat-box">
    <div class="val"><?= number_format((float)($tot['suma'] ?? 0), 2, ',', '.') ?> €</div>
    <div class="lbl">Importe total</div>
  </div>
  <div class="stat-box">
    <div class="val"><?= (int)($tot['canjeadas'] ?? 0) ?></div>
    <div class="lbl">Canjeadas</div>
  </div>
  <div class="stat-box">
    <div class="val"><?= (int)($tot['cnt'] ?? 0) - (int)($tot['canjeadas'] ?? 0) ?></div>
    <div class="lbl">Pendientes de canjear</div>
  </div>
</div>

<div class="card">
  <div class="card-title">Totales por producto</div>
  <div class="table-wrap">
    <table class="admin">
      <thead>
        <tr><th>Producto</th><th>Evento</th><th>Vendidas</th><th>Canjeadas</th><th>Importe</th></tr>
      </thead>
      <tbody>
        <?php if (empty($porProducto)): ?>
          <tr><td colspan="5" style="text-align:center;color:#aaa;padding:30px;">Sin consumiciones vendidas.</td></tr>
        <?php else: foreach ($porProducto as $pr): ?>
          <tr>
            <td style="font-weight:600;"><?= h($pr['producto_nombre']) ?></td>
            <td style="font-size:13px;color:#666;"><?= h($pr['evento_nombre']) ?></td>
            <td><?= (int)$pr['cnt'] ?></td>
            <td><?= (int)$pr['canjeadas'] ?></td>
            <td style="font-weight:700;"><?= number_format((float)$pr['suma'], 2, ',', '.') ?> €</td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="padding:0;">
  <div class="card-title" style="padding:22px 24px 0;">Pedidos online con consumiciones</div>
  <div class="table-wrap">
    <table class="admin">
      <thead>
        <tr>
          <th>Pedido</th><th>Evento</th><th>Cliente</th><th>Consumiciones</th><th>Importe</th><th>Estado</th><th>Fecha</th><th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pedidos)): ?>
          <tr><td colspan="8" style="text-align:center;color:#aaa;padding:30px;">Sin pedidos con consumiciones.</td></tr>
        <?php else: foreach ($pedidos as $p):
          $bc = match($p['estado_pago']) { 'pagado'=>'badge-green','pendiente'=>'badge-orange','fallido'=>'badge-red','cancelado'=>'badge-gray',default=>'badge-red' };
        ?>
          <tr>
            <td><code style="font-size:12px;"><?= h($p['numero_pedido']) ?></code></td>
            <td style="font-size:13px;"><?= h($p['evento_nombre']) ?></td>
            <td>
              <div style="font-size:13px;font-weight:600;"><?= h($p['user_name']) ?></div>
              <div style="font-size:11px;color:#aaa;"><?= h($p['user_email']) ?></div>
            </td>
            <td><?= (int)$p['num_consumiciones'] ?> (<?= (int)$p['canjeadas'] ?> canjeadas)</td>
            <td style="font-weight:700;"><?= number_format((float)$p['importe_consumiciones'], 2, ',', '.') ?> €</td>
            <td><span class="badge <?= $bc ?>"><?= ucfirst($p['estado_pago']) ?></span></td>
            <td style="font-size:12px;color:#aaa;"><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <a href="<?= h($base) ?>/admin/inscripciones/detalle.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline">Ver pedido</a>
                <?php if ($p['estado_pago'] === 'pagado'): ?>
                  <a href="<?= h($base) ?>/admin/inscripciones/descargar-pdf.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline">⬇ PDF</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="padding:0;">
  <div class="card-title" style="padding:22px 24px 0;">Ventas en caja</div>
  <div class="table-wrap">
    <table class="admin">
      <thead>
        <tr><th>Producto</th><th>Evento</th><th>Comprador</th><th>Precio</th><th>Estado</th><th>Fecha</th></tr>
      </thead>
      <tbody>
        <?php if (empty($ventasCaja)): ?>
          <tr><td colspan="6" style="text-align:center;color:#aaa;padding:30px;">Sin ventas en caja.</td></tr>
        <?php else: foreach ($ventasCaja as $vc): ?>
          <tr>
            <td style="font-weight:600;"><?= h($vc['producto_nombre']) ?></td>
            <td style="font-size:13px;color:#666;"><?= h($vc['evento_nombre']) ?></td>
            <td style="font-size:13px;"><?= h($vc['nombre_comprador'] ?: '—') ?></td>
            <td style="font-weight:700;"><?= number_format((float)$vc['precio'], 2, ',', '.') ?> €</td>
            <td><?= $vc['usado'] ? '<span class="badge badge-green">✓ Canjeada</span>' : '<span class="badge badge-gray">Pendiente</span>' ?></td>
            <td style="font-size:12px;color:#aaa;"><?= date('d/m/Y H:i', strtotime($vc['created_at'])) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../_footer.php'; ?>
