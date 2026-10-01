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

$id     = (int)($data['id'] ?? 0);
$field  = trim($data['field'] ?? ''); // 'starred' or 'status'
$value  = $data['value'] ?? null;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid notification.']);
    exit;
}

// Ownership check
$check = $conn->prepare("SELECT id FROM notifications WHERE id = ? AND user_id = ? LIMIT 1");
$check->bind_param('ii', $id, $uid);
$check->execute();
$owned = $check->get_result()->fetch_assoc();
$check->close();

if (!$owned) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Notification not found.']);
    exit;
}

if ($field === 'starred') {
    $starred = $value ? 1 : 0;
    $stmt = $conn->prepare("UPDATE notifications SET starred = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param('iii', $starred, $id, $uid);
} elseif ($field === 'status') {
    $status = ($value === 'unread') ? 'unread' : 'read';
    $stmt = $conn->prepare("UPDATE notifications SET status = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param('sii', $status, $id, $uid);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid field.']);
    exit;
}

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
$stmt->close();