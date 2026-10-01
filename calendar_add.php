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

$title        = trim($data['title'] ?? '');
$description  = trim($data['description'] ?? '');
$eventDate    = trim($data['event_date'] ?? '');
$eventTime    = trim($data['event_time'] ?? '08:00');
$category     = trim($data['category'] ?? 'other');
$reminderType = trim($data['reminder_type'] ?? 'same-day');

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

$eventTimeFull = $t->format('H:i:s');
$color = $categoryColors[$category];

// Params: uid(i), title(s), description(s), event_date(s), event_time(s),
// category(s), reminder_type(s), color(s) = 1 int + 7 strings = 'isssssss'
$stmt = $conn->prepare(
    "INSERT INTO calendar_events (user_id, title, description, event_date, event_time, category, reminder_type, color)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    'isssssss',
    $uid,
    $title,
    $description,
    $eventDate,
    $eventTimeFull,
    $category,
    $reminderType,
    $color
);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
$stmt->close();
