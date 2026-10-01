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

$items = $data['items'] ?? [];
if (!is_array($items) || empty($items)) {
    echo json_encode(['success' => false, 'message' => 'No notifications to restore.']);
    exit;
}

$validStatuses = ['unread', 'read'];
$validTypes = ['plant', 'weather', 'reminder', 'harvest', 'pest', 'water', 'system'];

$stmt = $conn->prepare(
    "INSERT INTO notifications (user_id, title, description, status, starred, type, notif_date)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);

$newIds = [];
foreach ($items as $item) {
    $title    = trim($item['title'] ?? '');
    $desc     = $item['description'] ?? '';
    $status   = in_array($item['status'] ?? '', $validStatuses, true) ? $item['status'] : 'unread';
    $starred  = !empty($item['starred']) ? 1 : 0;
    $type     = in_array($item['type'] ?? '', $validTypes, true) ? $item['type'] : 'system';
    $notifDate = $item['notif_date'] ?? null;

    if ($title === '') continue;

    $stmt->bind_param('isssiss', $uid, $title, $desc, $status, $starred, $type, $notifDate);
    if ($stmt->execute()) {
        $newIds[] = $stmt->insert_id;
    }
}
$stmt->close();

echo json_encode(['success' => true, 'ids' => $newIds]);