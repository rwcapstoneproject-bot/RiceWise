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

$id         = (int)($data['id'] ?? 0);
$activity   = trim($data['activity'] ?? '');
$location   = trim($data['location'] ?? '');
$quantity   = $data['quantity'] ?? null;
$unit       = $data['unit'] ?? '';
$status     = trim($data['status'] ?? '');
$recordDate = trim($data['record_date'] ?? '');
$cropType   = isset($data['crop_type']) && $data['crop_type'] !== '' ? trim($data['crop_type']) : null;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid record.']);
    exit;
}
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

// Ownership check: only allow editing the logged-in user's own records
$check = $conn->prepare("SELECT id FROM farm_records WHERE id = ? AND user_id = ? LIMIT 1");
$check->bind_param('ii', $id, $uid);
$check->execute();
$owned = $check->get_result()->fetch_assoc();
$check->close();

if (!$owned) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Record not found.']);
    exit;
}

$stmt = $conn->prepare(
    "UPDATE farm_records
     SET activity = ?, crop_type = ?, location = ?, quantity = ?, unit = ?, status = ?, record_date = ?
     WHERE id = ? AND user_id = ?"
);
$stmt->bind_param(
    'sssdsssii',
    $activity,
    $cropType,
    $location,
    $quantity,
    $unit,
    $status,
    $recordDate,
    $id,
    $uid
);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
$stmt->close();