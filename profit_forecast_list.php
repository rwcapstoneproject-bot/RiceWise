<?php
require_once 'config.php';
if (!isset($_SESSION['rw_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$uid = (int)$_SESSION['rw_user_id'];

$stmt = $conn->prepare(
    "SELECT forecast_period, blended_value, lstm_value, rf_value, based_on_quarters, created_at
     FROM profit_forecast WHERE user_id=? ORDER BY forecast_period DESC LIMIT 12"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode(['success' => true, 'forecasts' => $rows]);