<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['rw_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$uid = (int)$_SESSION['rw_user_id'];

// Most recent first, capped at 10 — matches the dropdown's original scope.
// (notifications.php, the full list page, fetches all rows separately.)
$stmt = $conn->prepare(
    "SELECT id, title, description, status, starred, type, notif_date, created_at
     FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode(['success' => true, 'notifications' => $rows]);
