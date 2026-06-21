<?php
// descargar-pedido.php — PDF combinado del pedido (entradas + consumiciones)
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/TicketManager.php';

Auth::userCheck();

$id = (int)($_GET['id'] ?? 0);
$stIns = db()->prepare('SELECT * FROM inscripciones WHERE id=?');
$stIns->execute([$id]);
$inscripcion = $stIns->fetch();

if (!$inscripcion || $inscripcion['user_id'] != Auth::userId() || $inscripcion['estado_pago'] !== 'pagado') {
    http_response_code(403);
    die('Sin acceso a este pedido.');
}

if (!$inscripcion['pdf_path'] || !file_exists(__DIR__ . '/../' . $inscripcion['pdf_path'])) {
    TicketManager::generarPDFsInscripcion($id);
    $stIns->execute([$id]);
    $inscripcion = $stIns->fetch();
}

$pdfPath = __DIR__ . '/../' . $inscripcion['pdf_path'];
$pdfContent = file_get_contents($pdfPath);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="pedido_' . $inscripcion['numero_pedido'] . '.pdf"');
header('Content-Length: ' . strlen($pdfContent));
echo $pdfContent;
exit;
