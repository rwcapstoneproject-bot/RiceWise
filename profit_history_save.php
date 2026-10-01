<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['rw_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$uid = (int)$_SESSION['rw_user_id'];

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['period_label']) || !isset($data['net_profit'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$periodLabel   = substr(trim($data['period_label']), 0, 20);
$periodDate    = $data['period_date'] ?? date('Y-m-01');
$netProfit     = (float)$data['net_profit'];
$grossRevenue  = (float)($data['gross_revenue'] ?? 0);
$totalCost     = (float)($data['total_cost'] ?? 0);
$hectares      = (float)($data['hectares'] ?? 1);
$farmgatePrice = (float)($data['farmgate_price'] ?? 0);

// Upsert: recomputing the same period updates it instead of duplicating rows.
$stmt = $conn->prepare(
    "INSERT INTO profit_history
        (user_id, period_label, period_date, net_profit, gross_revenue, total_cost, hectares, farmgate_price)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        period_date = VALUES(period_date),
        net_profit = VALUES(net_profit),
        gross_revenue = VALUES(gross_revenue),
        total_cost = VALUES(total_cost),
        hectares = VALUES(hectares),
        farmgate_price = VALUES(farmgate_price)"
);
$stmt->bind_param('issdddd', $uid, $periodLabel, $periodDate, $netProfit, $grossRevenue, $totalCost, $hectares, $farmgatePrice);
$ok = $stmt->execute();
$stmt->close();

echo json_encode(['success' => $ok]);