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

$validActivities = ['Planting', 'Fertilizing', 'Irrigation', 'Pest Control', 'Harvesting', 'Sales'];
$validStatuses   = ['Pending', 'In Progress', 'Completed'];
$validUnits      = ['peso', 'kg'];

$activity   = trim($data['activity'] ?? '');
$location   = trim($data['location'] ?? '');
$quantity   = $data['quantity'] ?? null;
$unit       = $data['unit'] ?? '';
$status     = trim($data['status'] ?? '');
$recordDate = trim($data['record_date'] ?? '');
$cropType   = isset($data['crop_type']) && $data['crop_type'] !== '' ? trim($data['crop_type']) : null;

// Validation
if (!in_array($activity, $validActivities, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid activity.']);
    exit;
}
if ($location === '' || mb_strlen($location) > 150) {
    echo json_encode(['success' => false, 'message' => 'Field location is required.']);
    exit;
}
if (!is_numeric($quantity) || (float)$quantity < 0) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid value.']);
    exit;
}
if (!in_array($unit, $validUnits, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid unit.']);
    exit;
}
if (!in_array($status, $validStatuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status.']);
    exit;
}
$d = DateTime::createFromFormat('Y-m-d', $recordDate);
if (!$d || $d->format('Y-m-d') !== $recordDate) {
    echo json_encode(['success' => false, 'message' => 'Invalid date.']);
    exit;
}
if ($activity === 'Planting' && !$cropType) {
    echo json_encode(['success' => false, 'message' => 'Crop type is required for Planting.']);
    exit;
}
if ($activity !== 'Planting') {
    $cropType = null;
}

$stmt = $conn->prepare(
    "INSERT INTO farm_records (user_id, activity, crop_type, location, quantity, unit, status, record_date)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    'isssdsss',
    $uid,
    $activity,
    $cropType,
    $location,
    $quantity,
    $unit,
    $status,
    $recordDate
);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
$stmt->close();
