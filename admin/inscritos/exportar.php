<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';

Auth::adminCheck('superadmin', 'admin');

$eventoFiltro = (int)($_GET['evento_id'] ?? 0);
$estadoFiltro = $_GET['estado'] ?? '';
$q            = trim($_GET['q'] ?? '');

$where  = ["i.estado_pago='pagado'"];
$params = [];
if ($eventoFiltro) { $where[] = 'e.evento_id=?'; $params[] = $eventoFiltro; }
if ($estadoFiltro === 'entrado')    $where[] = 'e.usado=1';
if ($estadoFiltro === 'no_entrado') $where[] = 'e.usado=0';
if ($q) {
    $qlike = '%' . $q . '%';
    $where[] = '(e.nombre_asistente LIKE ? OR i.numero_pedido LIKE ? OR u.name LIKE ?)';
    array_push($params, $qlike, $qlike, $qlike);
}
$whereStr = implode(' AND ', $where);

// Pedidos (un registro por pedido) que tienen al menos una entrada que cumple el filtro
$stIns = db()->prepare("
    SELECT DISTINCT i.*, ev.nombre as evento_nombre, ev.fecha_evento,
           u.name as user_name, u.email as user_email, u.phone as user_phone,
           (SELECT COUNT(*) FROM entradas en2 WHERE en2.inscripcion_id=i.id) as num_inscritos
    FROM entradas e
    JOIN inscripciones i ON i.id = e.inscripcion_id
    JOIN eventos ev ON ev.id = e.evento_id
    JOIN users u ON u.id = i.user_id
    WHERE $whereStr
    ORDER BY i.created_at DESC
");
$stIns->execute($params);
$pedidos = $stIns->fetchAll();

$fname = 'inscritos';
if ($eventoFiltro) {
    $stEv = db()->prepare('SELECT nombre FROM eventos WHERE id=?');
    $stEv->execute([$eventoFiltro]);
    $evNombre = preg_replace('/[^a-z0-9]/i', '_', $stEv->fetchColumn());
    $fname .= '_' . $evNombre;
}
$fname .= '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-cache');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Pedido', 'Evento', 'Fecha evento', 'Titular nombre', 'Titular email', 'Titular teléfono',
    'Método pago', 'Estado', 'Total (€)', 'Confirmado', 'Fecha inscripción',
    'Cantidad de inscritos', 'Notas admin',
], ';');

foreach ($pedidos as $p) {
    fputcsv($out, [
        $p['numero_pedido'],
        $p['evento_nombre'],
        $p['fecha_evento'] ? date('d/m/Y H:i', strtotime($p['fecha_evento'])) : '',
        $p['user_name'],
        $p['user_email'],
        $p['user_phone'] ?? '',
        $p['metodo_pago'],
        $p['estado_pago'],
        number_format((float)$p['precio_total'], 2, ',', '.'),
        $p['confirmado_at'] ? date('d/m/Y H:i', strtotime($p['confirmado_at'])) : '',
        date('d/m/Y H:i', strtotime($p['created_at'])),
        (int)$p['num_inscritos'],
        $p['notas_admin'] ?? '',
    ], ';');
}
fclose($out);
exit;
