<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
Auth::ensureSession();
Auth::adminLogout();
$destino = (strpos($_SERVER['HTTP_REFERER'] ?? '', 'barra.php') !== false) ? 'barra.php' : 'index.php';
header('Location: ' . $destino);
exit;
