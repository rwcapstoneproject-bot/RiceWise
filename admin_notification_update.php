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

$id    = (int)($data['id'] ?? 0);
$field = trim($data['field'] ?? '');
$value = $data['value'] ?? null;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid notification.']);
    exit;
}

if ($field === 'status') {
    $status = ($value === 'unread') ? 'unread' : 'read';
    $stmt = $conn->prepare("UPDATE notifications SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $id);
} elseif ($field === 'starred') {
    $starred = $value ? 1 : 0;
    $stmt = $conn->prepare("UPDATE notifications SET starred = ? WHERE id = ?");
    $stmt->bind_param('ii', $starred, $id);
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