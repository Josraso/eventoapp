<?php
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

Auth::adminCheck('superadmin', 'admin');

$eventoFiltro = (int)($_GET['evento_id'] ?? 0);
$estadoFiltro = $_GET['estado'] ?? '';
$q            = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
if ($eventoFiltro) { $where[] = 'i.evento_id=?'; $params[] = $eventoFiltro; }
if ($estadoFiltro) { $where[] = 'i.estado_pago=?'; $params[] = $estadoFiltro; }
if ($q) {
    $qlike = '%' . $q . '%';
    $where[] = '(i.numero_pedido LIKE ? OR u.name LIKE ? OR u.email LIKE ?
                 OR EXISTS(SELECT 1 FROM entradas en WHERE en.inscripcion_id=i.id AND en.nombre_asistente LIKE ?))';
    array_push($params, $qlike, $qlike, $qlike, $qlike);
}
$whereStr = implode(' AND ', $where);

$stIns = db()->prepare("
    SELECT i.*, e.nombre as evento_nombre, e.fecha_evento,
           u.name as user_name, u.email as user_email, u.phone as user_phone,
           (SELECT COUNT(*) FROM entradas en2 WHERE en2.inscripcion_id=i.id) as num_inscritos
    FROM inscripciones i
    JOIN eventos e ON e.id = i.evento_id
    JOIN users u ON u.id = i.user_id
    WHERE $whereStr
    ORDER BY i.created_at DESC
");
$stIns->execute($params);
$pedidos = $stIns->fetchAll();

$fname = 'pedidos';
if ($eventoFiltro) {
    $stEv = db()->prepare('SELECT nombre FROM eventos WHERE id=?');
    $stEv->execute([$eventoFiltro]);
    $evNombre = preg_replace('/[^a-z0-9]/i', '_', $stEv->fetchColumn());
    $fname .= '_' . $evNombre;
}
$fname .= '_' . date('Ymd_His') . '.xlsx';

$headers = [
    'Pedido', 'Evento', 'Fecha evento', 'Titular nombre', 'Titular email', 'Titular teléfono',
    'Método pago', 'Estado', 'Total (€)', 'Confirmado', 'Fecha inscripción',
    'Cantidad de inscritos', 'Notas admin',
];

$sheet = new Spreadsheet();
$ws = $sheet->getActiveSheet();
$ws->setTitle('Pedidos');
$ws->fromArray($headers, null, 'A1');
$lastCol = $ws->getCellByColumnAndRow(count($headers), 1)->getColumn();
$ws->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);

$row = 2;
foreach ($pedidos as $p) {
    $ws->fromArray([
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
    ], null, 'A' . $row);
    $row++;
}

foreach (range('A', $ws->getHighestColumn()) as $col) {
    $ws->getColumnDimension($col)->setAutoSize(true);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($sheet);
$writer->save('php://output');
exit;
