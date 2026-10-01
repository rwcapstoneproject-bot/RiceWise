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

$name     = trim($data['name'] ?? '');
$email    = trim($data['email'] ?? '');
$password = trim($data['password'] ?? '');
$location = trim($data['location'] ?? '');
$hectares = $data['hectares'] ?? null;

if ($name === '' || mb_strlen($name) > 100) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid name.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}
if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Temporary password must be at least 6 characters.']);
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

$check = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$check->bind_param('s', $email);
$check->execute();
$dupe = $check->get_result()->fetch_assoc();
$check->close();
if ($dupe) {
    echo json_encode(['success' => false, 'message' => 'This email address is already in use.']);
    exit;
}

$passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$stmt = $conn->prepare(
    "INSERT INTO users (name, email, password_hash, location, role, is_blocked, hectares)
     VALUES (?, ?, ?, ?, 'user', 0, ?)"
);
$stmt->bind_param('ssssd', $name, $email, $passwordHash, $location, $hectares);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
$stmt->close();