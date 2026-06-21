<?php
// descargar-entrada.php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/TicketManager.php';

Auth::userCheck();

$id = (int)($_GET['id'] ?? 0);
$stE = db()->prepare('SELECT e.*, i.user_id, i.numero_pedido, i.estado_pago FROM entradas e JOIN inscripciones i ON i.id=e.inscripcion_id WHERE e.id=?');
$stE->execute([$id]);
$entrada = $stE->fetch();

if (!$entrada || $entrada['user_id'] != Auth::userId() || $entrada['estado_pago'] !== 'pagado') {
    http_response_code(403);
    die('Sin acceso a esta entrada.');
}

// Generar PDF si no existe
if (!$entrada['pdf_path'] || !file_exists(__DIR__ . '/../' . $entrada['pdf_path'])) {
    $stIns = db()->prepare('SELECT * FROM inscripciones WHERE id=?');
    $stIns->execute([$entrada['inscripcion_id']]);
    $inscripcion = $stIns->fetch();
    $stEv = db()->prepare('SELECT * FROM eventos WHERE id=?');
    $stEv->execute([$entrada['evento_id']]);
    $evento = $stEv->fetch();
    $pdfContent = TicketManager::generarPDF($entrada, $evento, $inscripcion);
    $pdfPath = TicketManager::guardarPDF($id, $pdfContent);
} else {
    $pdfPath = __DIR__ . '/../' . $entrada['pdf_path'];
    $pdfContent = file_get_contents($pdfPath);
}

$nombre = preg_replace('/[^a-z0-9]/i', '_', $entrada['nombre_asistente']);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="entrada_' . $nombre . '.pdf"');
header('Content-Length: ' . strlen($pdfContent));
echo $pdfContent;
exit;
