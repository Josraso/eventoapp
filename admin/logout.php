<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
Auth::adminLogout();
$base = defined('APP_BASE_URL') ? rtrim(APP_BASE_URL, '/') : '';
header('Location: ' . $base . '/admin/login.php');
exit;
