<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(2592000, '/; SameSite=Lax', '', !empty($_SERVER['HTTPS']), true);
    session_start();
}
Auth::adminLogout();
header('Location: index.php');
exit;
