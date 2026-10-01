<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['rw_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$uid = (int)$_SESSION['rw_user_id'];

// Last 14 saved daily snapshots, oldest first — mirrors the shape
// Open-Meteo's past_days array gave ForecastAI, but sourced from your
// own logWeatherSnapshot() writes instead of re-fetching external history.
$stmt = $conn->prepare(
    "SELECT log_date, temp_max, temp_min, humidity, wind_speed, wind_direction,
            precipitation_probability, weather_code
     FROM (
        SELECT * FROM weather_history WHERE user_id = ? ORDER BY log_date DESC LIMIT 14
     ) t
     ORDER BY log_date ASC"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode(['success' => true, 'history' => $rows]);