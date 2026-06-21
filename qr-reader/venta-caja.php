<?php
// venta-caja.php — Registra una venta de consumiciones pagada en efectivo en el mostrador
// y entrega el PDF con los tickets generados. No requiere cuenta de usuario.
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/TicketManager.php';

Auth::ensureSession();

if (empty($_SESSION['admin_id']) || !in_array(Auth::adminRole(), ['camarero', 'admin', 'superadmin'])) {
    http_response_code(403);
    die('Sin permisos.');
}

$eventoId   = (int)($_POST['evento_id'] ?? 0);
$productoId = (int)($_POST['producto_id'] ?? 0);
$cantidad   = max(1, min(50, (int)($_POST['cantidad'] ?? 1)));
$nombre     = trim($_POST['nombre_comprador'] ?? '') ?: null;

$eventosCamarero = Auth::camareroEventos();
if (!$eventoId || !in_array($eventoId, $eventosCamarero)) {
    http_response_code(403);
    die('Sin acceso a este evento.');
}

$stP = db()->prepare('SELECT * FROM productos_consumicion WHERE id=? AND evento_id=? AND activo=1');
$stP->execute([$productoId, $eventoId]);
$producto = $stP->fetch();
if (!$producto) {
    http_response_code(404);
    die('Producto no encontrado.');
}

$ids = TicketManager::crearConsumiciones($productoId, $cantidad, null, 'caja', $nombre, Auth::adminId());
$pdfContent = TicketManager::generarPDFConsumicionesCaja($ids);

if (!$pdfContent) {
    http_response_code(500);
    die('No se pudo generar el PDF.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="venta_caja_' . implode('-', $ids) . '.pdf"');
header('Content-Length: ' . strlen($pdfContent));
echo $pdfContent;
