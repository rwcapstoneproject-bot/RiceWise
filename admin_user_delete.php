<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['rw_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}
if ($_SESSION['rw_user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = (int)($data['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid farmer.']);
    exit;
}

$check = $conn->prepare("SELECT id FROM users WHERE id=? AND role='user' LIMIT 1");
$check->bind_param('i', $id);
$check->execute();
$exists = $check->get_result()->fetch_assoc();
$check->close();
if (!$exists) {
    echo json_encode(['success' => false, 'message' => 'Farmer not found.']);
    exit;
}

/*
 * None of farm_records, calendar_events, notifications, planting_cycles, or
 * planting_process_completed have an actual FK constraint (ON DELETE
 * CASCADE) tying them to users.id — just a plain index. So deleting a user
 * without manually cleaning these up first would leave orphaned rows
 * scattered across five tables. Doing the cleanup explicitly, in a
 * transaction, so a failure partway through doesn't leave things half-deleted.
 */
$conn->begin_transaction();
try {
    $tables = ['planting_process_completed', 'planting_cycles', 'farm_records', 'calendar_events', 'notifications'];
    foreach ($tables as $table) {
        $del = $conn->prepare("DELETE FROM `$table` WHERE user_id = ?");
        $del->bind_param('i', $id);
        $del->execute();
        $del->close();
    }

    $delUser = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'user'");
    $delUser->bind_param('i', $id);
    $delUser->execute();
    $affected = $delUser->affected_rows;
    $delUser->close();

    if ($affected === 0) {
        throw new Exception('User row was not deleted.');
    }

    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}