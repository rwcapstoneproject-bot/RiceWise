<?php
require_once 'config.php';
if (!isset($_SESSION['rw_user_id'])) { http_response_code(401); exit; }
$uid = (int)$_SESSION['rw_user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$month = date('Y-m');

$stmt = $conn->prepare(
    "INSERT INTO profit_snapshots (user_id, snapshot_month, net_profit, gross_revenue, total_cost, hectares, farmgate_price)
     VALUES (?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
       net_profit=VALUES(net_profit), gross_revenue=VALUES(gross_revenue),
       total_cost=VALUES(total_cost), hectares=VALUES(hectares), farmgate_price=VALUES(farmgate_price)"
);
$stmt->bind_param('isdddd', $uid, $month, $data['net_profit'], $data['gross_revenue'], $data['total_cost'], $data['hectares'], $data['farmgate_price']);
$stmt->execute();
echo json_encode(['success' => true]);