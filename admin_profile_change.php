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

$aid = (int)$_SESSION['rw_user_id'];
$data = json_decode(file_get_contents('php://input'), true);

$currentPassword = trim($data['current_password'] ?? '');
$newPassword     = trim($data['new_password'] ?? '');

if ($currentPassword === '' || $newPassword === '') {
    echo json_encode(['success' => false, 'message' => 'Please fill in all password fields.']);
    exit;
}
if (strlen($newPassword) < 6) {
    echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters.']);
    exit;
}

$stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $aid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Admin account not found.']);
    exit;
}

if (!password_verify($currentPassword, $row['password_hash'])) {
    echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
    exit;
}

$newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

$upd = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
$upd->bind_param('si', $newHash, $aid);

if ($upd->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
$upd->close();