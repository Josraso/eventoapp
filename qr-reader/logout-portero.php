<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/Auth.php';
Auth::ensureSession();
Auth::adminLogout();
header('Location: index.php');
exit;
