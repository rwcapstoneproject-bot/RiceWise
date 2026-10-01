<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['rw_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$uid = (int)$_SESSION['rw_user_id'];

// Last 8 periods, oldest first — matches the shape ForecastAI expects
// (a plain ascending array), same as PSA_RETAIL_HISTORY.
$stmt = $conn->prepare(
    "SELECT period_label, period_date, net_profit, gross_revenue, total_cost, hectares, farmgate_price
     FROM (
        SELECT * FROM profit_history WHERE user_id = ? ORDER BY period_date DESC LIMIT 8
     ) t
     ORDER BY period_date ASC"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode(['success' => true, 'history' => $rows]);