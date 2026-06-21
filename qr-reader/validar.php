<?php
// validar.php — API AJAX del lector QR
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/TicketManager.php';

Auth::ensureSession();

header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['resultado' => 'invalido', 'error' => 'no_auth']);
    exit;
}

$token   = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
$eventoId = (int)($_POST['evento_id'] ?? 0);

if (!$token) {
    echo json_encode(['resultado' => 'invalido']);
    exit;
}

// Verificar que el portero tiene acceso al evento
$eventosPortero = Auth::porteroEventos();
if ($eventoId && !in_array($eventoId, $eventosPortero)) {
    echo json_encode(['resultado' => 'invalido', 'error' => 'sin_acceso']);
    exit;
}

$entrada = TicketManager::validarQRToken($token);

if (!$entrada) {
    // Log intento inválido
    try {
        $stDummy = db()->prepare('SELECT id FROM entradas WHERE qr_token=? LIMIT 1');
        $stDummy->execute([$token]);
        $entDummy = $stDummy->fetch();
        if ($entDummy) {
            db()->prepare('INSERT INTO qr_logs (entrada_id,evento_id,admin_id,resultado,ip) VALUES(?,?,?,?,?)')
               ->execute([$entDummy['id'], $eventoId, Auth::adminId(), 'invalido', $_SERVER['REMOTE_ADDR'] ?? '']);
        }
    } catch (Exception $e) {}

    echo json_encode(['resultado' => 'invalido']);
    exit;
}

// Verificar que la entrada es de este evento
if ($eventoId && $entrada['evento_id'] != $eventoId) {
    echo json_encode([
        'resultado' => 'invalido',
        'nombre'    => $entrada['nombre_asistente'],
        'evento'    => '⚠️ Entrada de otro evento: ' . $entrada['evento_nombre'],
    ]);
    exit;
}

// Verificar que la inscripción está pagada
$stIns = db()->prepare("SELECT estado_pago FROM inscripciones WHERE id=?");
$stIns->execute([$entrada['inscripcion_id']]);
$insEstado = $stIns->fetchColumn();
if ($insEstado !== 'pagado') {
    echo json_encode(['resultado' => 'invalido', 'nombre' => $entrada['nombre_asistente'], 'evento' => '⚠️ Inscripción no pagada']);
    exit;
}

// Campo extra configurado para mostrar en QR
$campoExtra = '';
if (!empty($entrada['campo_qr_extra']) && !empty($entrada['campos_extra'])) {
    $campos = is_string($entrada['campos_extra']) ? json_decode($entrada['campos_extra'], true) : $entrada['campos_extra'];
    $campoExtra = $campos[$entrada['campo_qr_extra']] ?? '';
}

// Stats actualizadas
$stStats = db()->prepare("SELECT COUNT(*) FROM entradas WHERE evento_id=? AND usado=1");
$stStats->execute([$entrada['evento_id']]);
$usados = (int)$stStats->fetchColumn();
$stTotal = db()->prepare("SELECT COUNT(*) FROM entradas e JOIN inscripciones i ON i.id=e.inscripcion_id WHERE e.evento_id=? AND i.estado_pago='pagado'");
$stTotal->execute([$entrada['evento_id']]);
$totalEnt = (int)$stTotal->fetchColumn();

// ¿Ya usada?
if ($entrada['usado']) {
    db()->prepare('INSERT INTO qr_logs (entrada_id,evento_id,admin_id,resultado,ip) VALUES(?,?,?,?,?)')
       ->execute([$entrada['id'], $entrada['evento_id'], Auth::adminId(), 'ya_usado', $_SERVER['REMOTE_ADDR'] ?? '']);

    echo json_encode([
        'resultado'  => 'ya_usado',
        'nombre'     => $entrada['nombre_asistente'],
        'evento'     => $entrada['evento_nombre'],
        'campo_extra' => $campoExtra,
        'usado_at'   => $entrada['usado_at'] ? date('d/m/Y H:i', strtotime($entrada['usado_at'])) : '',
        'stats'      => ['usados' => $usados, 'total' => $totalEnt, 'pendientes' => $totalEnt - $usados],
    ]);
    exit;
}

// Marcar como usada
db()->prepare('UPDATE entradas SET usado=1, usado_at=NOW(), usado_por=? WHERE id=?')
   ->execute([Auth::adminId(), $entrada['id']]);
db()->prepare('INSERT INTO qr_logs (entrada_id,evento_id,admin_id,resultado,ip) VALUES(?,?,?,?,?)')
   ->execute([$entrada['id'], $entrada['evento_id'], Auth::adminId(), 'ok', $_SERVER['REMOTE_ADDR'] ?? '']);

echo json_encode([
    'resultado'   => 'ok',
    'nombre'      => $entrada['nombre_asistente'],
    'evento'      => $entrada['evento_nombre'],
    'campo_extra' => $campoExtra,
    'stats'       => ['usados' => $usados + 1, 'total' => $totalEnt, 'pendientes' => $totalEnt - $usados - 1],
]);
