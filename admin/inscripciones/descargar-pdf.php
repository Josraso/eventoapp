<?php
if (!defined('ADMIN_GUARD')) define('ADMIN_GUARD', true);

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/TicketManager.php';

Auth::adminCheck('superadmin', 'admin');

$id = (int)($_GET['id'] ?? 0);
$stE = db()->prepare('SELECT e.*, i.numero_pedido, i.estado_pago, i.pdf_path as ins_pdf_path FROM entradas e JOIN inscripciones i ON i.id=e.inscripcion_id WHERE e.id=?');
$stE->execute([$id]);
$entrada = $stE->fetch();

if (!$entrada || $entrada['estado_pago'] !== 'pagado') {
    http_response_code(404);
    die('Entrada no encontrada.');
}

if (!$entrada['ins_pdf_path'] || !file_exists(__DIR__ . '/../../' . $entrada['ins_pdf_path'])) {
    TicketManager::generarPDFsInscripcion($entrada['inscripcion_id']);
    $stIns = db()->prepare('SELECT pdf_path FROM inscripciones WHERE id=?');
    $stIns->execute([$entrada['inscripcion_id']]);
    $pdfPath = $stIns->fetchColumn();
} else {
    $pdfPath = $entrada['ins_pdf_path'];
}
$pdfContent = file_get_contents(__DIR__ . '/../../' . $pdfPath);

$nombre = preg_replace('/[^a-z0-9]/i', '_', $entrada['numero_pedido']);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="pedido_' . $nombre . '.pdf"');
header('Content-Length: ' . strlen($pdfContent));
echo $pdfContent;
exit;
