<?php
// ============================================================
//  RiceWise — Database Configuration
// ============================================================
define('DB_HOST', 'fdb1029.awardspace.net');
define('DB_USER', '4772732_ricewise');          // Change to your MySQL username
define('DB_PASS', '4Projectuse!');              // Change to your MySQL password
define('DB_NAME', '4772732_ricewise');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die(json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $conn->connect_error
    ]));
}

$conn->set_charset('utf8mb4');

// ── Session setup ──
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,   // 1 day
        'path'     => '/',
        'secure'   => false,   // Set to true if using HTTPS
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}
