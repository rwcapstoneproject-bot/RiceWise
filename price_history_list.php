<?php
require_once 'config.php';
if (!isset($_SESSION['rw_user_id'])) { http_response_code(401); exit; }
$prices = $conn->query("SELECT price_month, regular_milled, well_milled, special FROM price_history ORDER BY price_month ASC")->fetch_all(MYSQLI_ASSOC);
$msrps  = $conn->query("SELECT effective_date, msrp, source, is_suspended, is_scheduled FROM msrp_history ORDER BY effective_date ASC")->fetch_all(MYSQLI_ASSOC);
echo json_encode(['success' => true, 'prices' => $prices, 'msrps' => $msrps]);