<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre>";
echo "PHP: " . PHP_VERSION . "\n";
echo "DIR: " . __DIR__ . "\n\n";

// Test 1: config.php
echo "=== config.php ===\n";
$cfg = __DIR__ . '/../config.php';
if (file_exists($cfg)) {
    echo "✓ Existe\n";
    require_once $cfg;
    echo "DB_HOST: " . DB_HOST . "\n";
    echo "DB_NAME: " . DB_NAME . "\n";
    echo "APP_BASE_URL: " . APP_BASE_URL . "\n";
} else {
    echo "✗ NO EXISTE\n";
}

// Test 2: PDO
echo "\n=== PDO MySQL ===\n";
try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS);
    echo "✓ Conexión OK\n";
    $v = $pdo->query('SELECT VERSION()')->fetchColumn();
    echo "MySQL: $v\n";
} catch(Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

// Test 3: lib/db.php
echo "\n=== lib/db.php ===\n";
try {
    require_once __DIR__ . '/../lib/db.php';
    echo "✓ Cargado OK\n";
    $s = getSetting('site_name', 'NO ENCONTRADO');
    echo "site_name: $s\n";
} catch(Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// Test 4: lib/Auth.php
echo "\n=== lib/Auth.php ===\n";
try {
    require_once __DIR__ . '/../lib/Auth.php';
    echo "✓ Cargado OK\n";
} catch(Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// Test 5: Sesión
echo "\n=== Sesión ===\n";
try {
    if (session_status() === PHP_SESSION_NONE) session_start();
    echo "✓ Sesión OK, id: " . session_id() . "\n";
} catch(Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

// Test 6: _header.php
echo "\n=== _header.php ===\n";
try {
    ob_start();
    $pageTitle = 'Test';
    include __DIR__ . '/_header.php';
    $out = ob_get_clean();
    echo "✓ Cargado OK (" . strlen($out) . " bytes)\n";
} catch(Throwable $e) {
    ob_end_clean();
    echo "✗ Error: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n=== FIN ===\n";
echo "</pre>";
