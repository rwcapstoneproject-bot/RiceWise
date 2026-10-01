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

$name     = trim($data['name'] ?? '');
$email    = trim($data['email'] ?? '');
$location = trim($data['location'] ?? '');

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

$check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
$check->bind_param('si', $email, $aid);
$check->execute();
$dupe = $check->get_result()->fetch_assoc();
$check->close();
if ($dupe) {
    echo json_encode(['success' => false, 'message' => 'This email address is already in use by another account.']);
    exit;
}

$stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, location = ? WHERE id = ?");
$stmt->bind_param('sssi', $name, $email, $location, $aid);

if ($stmt->execute()) {
    $_SESSION['rw_user_name'] = $name;
    $_SESSION['rw_user_email'] = $email;
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
$stmt->close();