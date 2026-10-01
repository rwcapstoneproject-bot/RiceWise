<?php
require_once 'config.php';
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['rw_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$uid = (int)$_SESSION['rw_user_id'];

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['days']) || !is_array($data['days'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing days[]']);
    exit;
}

$issued = date('Y-m-d');
$stmt = $conn->prepare(
    "INSERT INTO weather_forecast_runs
        (user_id, forecast_date, issued_date, temp_max, temp_min, rain_chance)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        temp_max = VALUES(temp_max),
        temp_min = VALUES(temp_min),
        rain_chance = VALUES(rain_chance)"
);

$saved = 0;
foreach ($data['days'] as $d) {
    if (empty($d['forecast_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['forecast_date'])) continue;
    if (!isset($d['temp_max'], $d['temp_min'], $d['rain_chance'])) continue;
    $fdate = $d['forecast_date'];
    $tmax  = (float)$d['temp_max'];
    $tmin  = (float)$d['temp_min'];
    $rain  = max(0, min(100, (int)$d['rain_chance']));
    $stmt->bind_param('issddi', $uid, $fdate, $issued, $tmax, $tmin, $rain);
    if ($stmt->execute()) $saved++;
}
$stmt->close();

echo json_encode(['success' => true, 'saved' => $saved]);
