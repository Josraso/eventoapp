<?php
// validar-consumicion.php — API AJAX del lector QR de barra (camareros)
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/TicketManager.php';

Auth::ensureSession();

header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['resultado' => 'invalido', 'error' => 'no_auth']);
    exit;
}

$tokenRaw = trim($_POST['token'] ?? '');
$eventoId = (int)($_POST['evento_id'] ?? 0);

if (!$tokenRaw) {
    echo json_encode(['resultado' => 'invalido']);
    exit;
}

// Verificar que el camarero tiene acceso al evento
$eventosCamarero = Auth::camareroEventos();
if ($eventoId && !in_array($eventoId, $eventosCamarero)) {
    echo json_encode(['resultado' => 'invalido', 'error' => 'sin_acceso']);
    exit;
}

// El token largo del QR es hexadecimal (48 chars); el código corto manual es alfanumérico (6 chars)
if (preg_match('/^[a-f0-9]{48}$/i', $tokenRaw)) {
    $consumicion = TicketManager::validarQRTokenConsumicion(strtolower($tokenRaw));
} else {
    $consumicion = TicketManager::validarCodigoCortoConsumicion($tokenRaw);
}

if (!$consumicion) {
    echo json_encode(['resultado' => 'invalido']);
    exit;
}

// Verificar que la consumición es de este evento
if ($eventoId && $consumicion['evento_id'] != $eventoId) {
    echo json_encode([
        'resultado' => 'invalido',
        'producto'  => $consumicion['producto_nombre'],
        'evento'    => '⚠️ Consumición de otro evento: ' . $consumicion['evento_nombre'],
    ]);
    exit;
}

// Stats actualizadas
$stStats = db()->prepare("SELECT COUNT(*) FROM consumiciones WHERE evento_id=? AND usado=1");
$stStats->execute([$consumicion['evento_id']]);
$usados = (int)$stStats->fetchColumn();
$stTotal = db()->prepare("SELECT COUNT(*) FROM consumiciones WHERE evento_id=?");
$stTotal->execute([$consumicion['evento_id']]);
$totalCons = (int)$stTotal->fetchColumn();

// ¿Ya usada?
if ($consumicion['usado']) {
    db()->prepare('INSERT INTO qr_logs (consumicion_id,evento_id,admin_id,resultado,ip) VALUES(?,?,?,?,?)')
       ->execute([$consumicion['id'], $consumicion['evento_id'], Auth::adminId(), 'ya_usado', $_SERVER['REMOTE_ADDR'] ?? '']);

    echo json_encode([
        'resultado'  => 'ya_usado',
        'producto'   => $consumicion['producto_nombre'],
        'evento'     => $consumicion['evento_nombre'],
        'usado_at'   => $consumicion['usado_at'] ? date('d/m/Y H:i', strtotime($consumicion['usado_at'])) : '',
        'stats'      => ['usados' => $usados, 'total' => $totalCons, 'pendientes' => $totalCons - $usados],
    ]);
    exit;
}

// Marcar como usada
db()->prepare('UPDATE consumiciones SET usado=1, usado_at=NOW(), usado_por=? WHERE id=?')
   ->execute([Auth::adminId(), $consumicion['id']]);
db()->prepare('INSERT INTO qr_logs (consumicion_id,evento_id,admin_id,resultado,ip) VALUES(?,?,?,?,?)')
   ->execute([$consumicion['id'], $consumicion['evento_id'], Auth::adminId(), 'ok', $_SERVER['REMOTE_ADDR'] ?? '']);

echo json_encode([
    'resultado'  => 'ok',
    'producto'   => $consumicion['producto_nombre'],
    'evento'     => $consumicion['evento_nombre'],
    'stats'      => ['usados' => $usados + 1, 'total' => $totalCons, 'pendientes' => $totalCons - $usados - 1],
]);
