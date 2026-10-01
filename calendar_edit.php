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

$validCategories = ['planting', 'harvest', 'irrigation', 'pest', 'fertilize', 'other'];
$validReminders  = ['same-day', '1-day', '1-week'];
$categoryColors  = [
    'planting'   => '#4a9b5f',
    'harvest'    => '#c8963e',
    'irrigation' => '#2980b9',
    'pest'       => '#c0392b',
    'fertilize'  => '#7b5ea7',
    'other'      => '#5a7a52',
];

$id           = (int)($data['id'] ?? 0);
$title        = trim($data['title'] ?? '');
$description  = trim($data['description'] ?? '');
$eventDate    = trim($data['event_date'] ?? '');
$eventTime    = trim($data['event_time'] ?? '08:00');
$category     = trim($data['category'] ?? 'other');
$reminderType = trim($data['reminder_type'] ?? 'same-day');

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid event.']);
    exit;
}
if ($title === '' || mb_strlen($title) > 200) {
    echo json_encode(['success' => false, 'message' => 'Event title is required.']);
    exit;
}
$d = DateTime::createFromFormat('Y-m-d', $eventDate);
if (!$d || $d->format('Y-m-d') !== $eventDate) {
    echo json_encode(['success' => false, 'message' => 'Invalid date.']);
    exit;
}
$t = DateTime::createFromFormat('H:i', $eventTime);
if (!$t) {
    echo json_encode(['success' => false, 'message' => 'Invalid time.']);
    exit;
}
if (!in_array($category, $validCategories, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid category.']);
    exit;
}
if (!in_array($reminderType, $validReminders, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid reminder type.']);
    exit;
}

// Ownership check — only allow editing the logged-in user's own events
$check = $conn->prepare("SELECT id FROM calendar_events WHERE id = ? AND user_id = ? LIMIT 1");
$check->bind_param('ii', $id, $uid);
$check->execute();
$owned = $check->get_result()->fetch_assoc();
$check->close();

if (!$owned) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Event not found.']);
    exit;
}

$eventTimeFull = $t->format('H:i:s');
$color = $categoryColors[$category];

$stmt = $conn->prepare(
    "UPDATE calendar_events
     SET title = ?, description = ?, event_date = ?, event_time = ?, category = ?, reminder_type = ?, color = ?
     WHERE id = ? AND user_id = ?"
);
$stmt->bind_param(
    'sssssssii',
    $title,
    $description,
    $eventDate,
    $eventTimeFull,
    $category,
    $reminderType,
    $color,
    $id,
    $uid
);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
$stmt->close();