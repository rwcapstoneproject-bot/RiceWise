<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['rw_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$uid = (int)$_SESSION['rw_user_id'];
$data = json_decode(file_get_contents('php://input'), true);

$ids = $data['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No notifications specified.']);
    exit;
}
$ids = array_values(array_unique(array_map('intval', $ids)));
$ids = array_values(array_filter($ids, fn($v) => $v > 0));
if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No valid notifications specified.']);
    exit;
}

// Build placeholders for the IN clause
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids)) . 'i'; // last 'i' is for user_id
$params = array_merge($ids, [$uid]);

$stmt = $conn->prepare("DELETE FROM notifications WHERE id IN ($placeholders) AND user_id = ?");
$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'deleted' => $stmt->affected_rows]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
$stmt->close();