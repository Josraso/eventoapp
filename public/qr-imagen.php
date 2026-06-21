<?php
// qr-imagen.php — Sirve la imagen PNG del QR de una entrada o consumición del usuario logueado,
// para poder mostrarla en pantalla cuando el portero/camarero no puede escanear el PDF.
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/TicketManager.php';

Auth::userCheck();

$tipo = $_GET['tipo'] ?? 'entrada';
$id   = (int)($_GET['id'] ?? 0);

if ($tipo === 'consumicion') {
    $st = db()->prepare('SELECT c.*, i.user_id FROM consumiciones c LEFT JOIN inscripciones i ON i.id=c.inscripcion_id WHERE c.id=?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || $row['user_id'] != Auth::userId()) {
        http_response_code(403);
        die('Sin acceso.');
    }
    $qrUrl = baseUrl() . '/qr-reader/validar-consumicion.php?t=' . urlencode($row['qr_token']);
} else {
    $st = db()->prepare('SELECT e.*, i.user_id FROM entradas e JOIN inscripciones i ON i.id=e.inscripcion_id WHERE e.id=?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || $row['user_id'] != Auth::userId()) {
        http_response_code(403);
        die('Sin acceso.');
    }
    $qrUrl = baseUrl() . '/qr-reader/validar.php?t=' . urlencode($row['qr_token']);
}

$png = base64_decode(TicketManager::generarQRImagenBase64($qrUrl));
if (!$png) {
    http_response_code(500);
    die('No se pudo generar el QR.');
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
echo $png;
