<?php
require_once 'config.php';
if (!isset($_SESSION['rw_user_id']) || $_SESSION['rw_user_role'] !== 'admin') {
    header('Location: index.php'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $conn->prepare(
        "INSERT INTO price_history (price_month, regular_milled, well_milled, special, da_msrp, msrp_effective_date, msrp_source, is_scheduled, created_by)
         VALUES (?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           regular_milled=VALUES(regular_milled), well_milled=VALUES(well_milled),
           special=VALUES(special), da_msrp=VALUES(da_msrp),
           msrp_effective_date=VALUES(msrp_effective_date), msrp_source=VALUES(msrp_source),
           is_scheduled=VALUES(is_scheduled)"
    );
    $stmt->bind_param('sdddsssii',
        $_POST['price_month'], $_POST['regular_milled'], $_POST['well_milled'],
        $_POST['special'], $_POST['da_msrp'], $_POST['msrp_effective_date'],
        $_POST['msrp_source'], $_POST['is_scheduled'], $_SESSION['rw_user_id']
    );
    $stmt->execute();
    header('Location: admin_price_entry.php?saved=1'); exit;
}