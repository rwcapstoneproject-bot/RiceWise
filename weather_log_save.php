<?php
require_once 'config.php';
if (!isset($_SESSION['rw_user_id'])) { http_response_code(401); exit; }
$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['latitude'], $data['longitude'])) {
    http_response_code(400); echo json_encode(['success'=>false]); exit;
}
$lat = round((float)$data['latitude'], 6);
$lng = round((float)$data['longitude'], 6);
$today = date('Y-m-d');

$stmt = $conn->prepare(
    "INSERT INTO weather_history
     (location_lat, location_lng, log_date, temp_max, temp_min, humidity, wind_speed, wind_direction, precipitation_probability, precipitation_sum, weather_code)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
       temp_max=VALUES(temp_max), temp_min=VALUES(temp_min), humidity=VALUES(humidity),
       wind_speed=VALUES(wind_speed), wind_direction=VALUES(wind_direction),
       precipitation_probability=VALUES(precipitation_probability),
       precipitation_sum=VALUES(precipitation_sum), weather_code=VALUES(weather_code)"
);
$stmt->bind_param(
    'ddsdddiidid',
    $lat, $lng, $today,
    $data['temp_max'], $data['temp_min'], $data['humidity'],
    $data['wind_speed'], $data['wind_direction'],
    $data['precipitation_probability'], $data['precipitation_sum'], $data['weather_code']
);
$stmt->execute();
echo json_encode(['success' => true]);