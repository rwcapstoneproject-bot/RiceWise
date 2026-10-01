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

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['forecast_period'], $input['blended_value'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing fields']);
    exit;
}

// forecast_period is the TARGET quarter, e.g. "2026-Q4"
$period = trim($input['forecast_period']);
if (!preg_match('/^(\d{4})-Q([1-4])$/', $period, $m)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid period']);
    exit;
}
$periodDate = sprintf('%04d-%02d-01', (int)$m[1], ((int)$m[2] - 1) * 3 + 1);
$issued     = date('Y-m-d');

$blended   = (float)$input['blended_value'];
$lstm      = (isset($input['lstm_value']) && is_numeric($input['lstm_value'])) ? (float)$input['lstm_value'] : null;
$rf        = (isset($input['rf_value']) && is_numeric($input['rf_value'])) ? (float)$input['rf_value'] : null;
$basedOn   = isset($input['based_on_quarters']) ? max(0, min(255, (int)$input['based_on_quarters'])) : 0;
$estimated = !empty($input['is_estimated']) ? 1 : 0;

$stmt = $conn->prepare(
    "INSERT INTO profit_forecast_runs
        (user_id, forecast_period, period_date, issued_date, blended_value, lstm_value, rf_value, based_on_quarters, is_estimated)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        blended_value = VALUES(blended_value),
        lstm_value = VALUES(lstm_value),
        rf_value = VALUES(rf_value),
        based_on_quarters = VALUES(based_on_quarters),
        is_estimated = VALUES(is_estimated)"
);
$stmt->bind_param('isssdddii', $uid, $period, $periodDate, $issued, $blended, $lstm, $rf, $basedOn, $estimated);
$ok = $stmt->execute();
$stmt->close();

echo json_encode(['success' => (bool)$ok]);
