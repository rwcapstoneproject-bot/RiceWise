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

$validTypes = ['plant', 'weather', 'reminder', 'harvest', 'pest', 'water', 'system'];

$recipient   = trim($data['recipient'] ?? '');
$title       = trim($data['title'] ?? '');
$description = trim($data['description'] ?? '');
$type        = trim($data['type'] ?? 'system');
$notifDate   = trim($data['notif_date'] ?? '');

if ($title === '' || mb_strlen($title) > 200) {
    echo json_encode(['success' => false, 'message' => 'Please enter a title.']);
    exit;
}
if (!in_array($type, $validTypes, true)) {
    $type = 'system';
}
$d = DateTime::createFromFormat('Y-m-d', $notifDate);
if (!$d || $d->format('Y-m-d') !== $notifDate) {
    echo json_encode(['success' => false, 'message' => 'Invalid date.']);
    exit;
}

// Figure out which farmer(s) this goes to.
if ($recipient === 'all') {
    $fu = $conn->query("SELECT id, name FROM users WHERE role='user'");
    $targets = $fu->fetch_all(MYSQLI_ASSOC);
} else {
    $recipientId = (int)$recipient;
    $fu = $conn->prepare("SELECT id, name FROM users WHERE id=? AND role='user' LIMIT 1");
    $fu->bind_param('i', $recipientId);
    $fu->execute();
    $targets = $fu->get_result()->fetch_all(MYSQLI_ASSOC);
    $fu->close();
}

if (!$targets) {
    echo json_encode(['success' => false, 'message' => 'No valid recipient(s) found.']);
    exit;
}

$stmt = $conn->prepare(
    "INSERT INTO notifications (user_id, title, description, status, starred, type, notif_date)
     VALUES (?, ?, ?, 'unread', 0, ?, ?)"
);

$created = [];
foreach ($targets as $t) {
    $uid = (int)$t['id'];
    $stmt->bind_param('issss', $uid, $title, $description, $type, $notifDate);
    if ($stmt->execute()) {
        $created[] = [
            'id' => $stmt->insert_id,
            'user_id' => $uid,
            'farmer' => $t['name'],
            'title' => $title,
            'description' => $description,
            'status' => 'unread',
            'starred' => 0,
            'type' => $type,
            'notif_date' => $notifDate,
        ];
    }
}
$stmt->close();

if (!$created) {
    echo json_encode(['success' => false, 'message' => 'Failed to send notification.']);
    exit;
}

echo json_encode(['success' => true, 'created' => $created]);
