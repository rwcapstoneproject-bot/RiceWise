<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['rw_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$uid = (int)$_SESSION['rw_user_id'];
$data = json_decode(file_get_contents('php://input'), true);

$cycleId   = (int)($data['cycle_id'] ?? 0);
$stepIndex = $data['step_index'] ?? null;

if ($cycleId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid cycle.']);
    exit;
}
if (!is_int($stepIndex) && !ctype_digit((string)$stepIndex)) {
    echo json_encode(['success' => false, 'message' => 'Invalid step.']);
    exit;
}
$stepIndex = (int)$stepIndex;
// STEP_TEMPLATE in process.php is a fixed 9-item array (indices 0-8).
if ($stepIndex < 0 || $stepIndex > 8) {
    echo json_encode(['success' => false, 'message' => 'Invalid step.']);
    exit;
}

// Ownership check — the cycle must belong to this user, and must still be active.
$check = $conn->prepare("SELECT id, status FROM planting_cycles WHERE id = ? AND user_id = ? LIMIT 1");
$check->bind_param('ii', $cycleId, $uid);
$check->execute();
$cycle = $check->get_result()->fetch_assoc();
$check->close();

if (!$cycle) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Cycle not found.']);
    exit;
}
if ($cycle['status'] === 'completed') {
    echo json_encode(['success' => false, 'message' => 'This cycle is already completed.']);
    exit;
}

// INSERT IGNORE — a repeat request for an already-completed step is a
// harmless no-op rather than an error.
$stmt = $conn->prepare(
    "INSERT IGNORE INTO planting_process_completed (user_id, cycle_id, step_index) VALUES (?, ?, ?)"
);
$stmt->bind_param('iii', $uid, $cycleId, $stepIndex);

if (!$stmt->execute()) {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit;
}
$stmt->close();

// Check whether all 9 steps are now marked complete for this cycle — if
// so, auto-close the cycle so it moves into history automatically instead
// of needing a separate "finish" action.
$countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM planting_process_completed WHERE cycle_id = ?");
$countStmt->bind_param('i', $cycleId);
$countStmt->execute();
$count = (int)$countStmt->get_result()->fetch_assoc()['c'];
$countStmt->close();

$cycleCompleted = false;
if ($count >= 9) {
    $upd = $conn->prepare("UPDATE planting_cycles SET status = 'completed', completed_at = NOW() WHERE id = ? AND status = 'active'");
    $upd->bind_param('i', $cycleId);
    $upd->execute();
    $cycleCompleted = $upd->affected_rows > 0;
    $upd->close();
}

echo json_encode(['success' => true, 'cycle_completed' => $cycleCompleted]);