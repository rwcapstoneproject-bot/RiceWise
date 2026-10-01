<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['rw_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}
if ($_SESSION['rw_user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = (int)($data['id'] ?? 0);
$isBlocked = isset($data['is_blocked']) && (int)$data['is_blocked'] === 1 ? 1 : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid farmer.']);
    exit;
}

$check = $conn->prepare("SELECT id FROM users WHERE id=? AND role='user' LIMIT 1");
$check->bind_param('i', $id);
$check->execute();
$exists = $check->get_result()->fetch_assoc();
$check->close();
if (!$exists) {
    echo json_encode(['success' => false, 'message' => 'Farmer not found.']);
    exit;
}

$stmt = $conn->prepare("UPDATE users SET is_blocked=? WHERE id=?");
$stmt->bind_param('ii', $isBlocked, $id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
$stmt->close();