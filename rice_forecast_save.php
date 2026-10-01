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
if (!$data || empty($data['months']) || !is_array($data['months'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing months[]']);
    exit;
}

$issued = date('Y-m-d');
$stmt = $conn->prepare(
    "INSERT INTO rice_forecast_runs
        (user_id, forecast_month, issued_date, blended_price, lstm_price, rf_price)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        blended_price = VALUES(blended_price),
        lstm_price = VALUES(lstm_price),
        rf_price = VALUES(rf_price)"
);

$saved = 0;
foreach ($data['months'] as $mo) {
    if (empty($mo['forecast_month']) || !preg_match('/^(\d{4}-\d{2})-\d{2}$/', $mo['forecast_month'], $mm)) continue;
    $fmonth  = $mm[1] . '-01'; // always the 1st of the month
    $blended = (float)($mo['blended_price'] ?? 0);
    $lstm    = isset($mo['lstm_price']) ? (float)$mo['lstm_price'] : null;
    $rf      = isset($mo['rf_price']) ? (float)$mo['rf_price'] : null;
    $stmt->bind_param('issddd', $uid, $fmonth, $issued, $blended, $lstm, $rf);
    if ($stmt->execute()) $saved++;
}
$stmt->close();

echo json_encode(['success' => true, 'saved' => $saved]);
