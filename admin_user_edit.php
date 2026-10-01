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

$id       = (int)($data['id'] ?? 0);
$name     = trim($data['name'] ?? '');
$email    = trim($data['email'] ?? '');
$location = trim($data['location'] ?? '');
$hectares = $data['hectares'] ?? null;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid farmer.']);
    exit;
}
if ($name === '' || mb_strlen($name) > 100) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid name.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}
if (mb_strlen($location) > 150) {
    echo json_encode(['success' => false, 'message' => 'Location is too long.']);
    exit;
}
if (!is_numeric($hectares) || (float)$hectares < 0) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid farm size.']);
    exit;
}

// Confirm target is actually a farmer account, not another admin
$check = $conn->prepare("SELECT id FROM users WHERE id=? AND role='user' LIMIT 1");
$check->bind_param('i', $id);
$check->execute();
$exists = $check->get_result()->fetch_assoc();
$check->close();
if (!$exists) {
    echo json_encode(['success' => false, 'message' => 'Farmer not found.']);
    exit;
}

$dupe = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
$dupe->bind_param('si', $email, $id);
$dupe->execute();
$dupeRow = $dupe->get_result()->fetch_assoc();
$dupe->close();
if ($dupeRow) {
    echo json_encode(['success' => false, 'message' => 'This email address is already in use by another account.']);
    exit;
}

$stmt = $conn->prepare("UPDATE users SET name=?, email=?, location=?, hectares=? WHERE id=?");
$stmt->bind_param('sssdi', $name, $email, $location, $hectares, $id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
$stmt->close();