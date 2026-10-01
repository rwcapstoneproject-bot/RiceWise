<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['rw_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$uid = (int)$_SESSION['rw_user_id'];

// For each distinct field location this user has ever logged, find the
// activity from their most recent COMPLETED record. "Completed" only —
// same convention as reports.php — so a Pending/In Progress record
// doesn't get treated as if it already happened.
$stmt = $conn->prepare(
    "SELECT fr.location, fr.activity
     FROM farm_records fr
     INNER JOIN (
         SELECT location, MAX(record_date) AS max_date
         FROM farm_records
         WHERE user_id = ? AND status = 'Completed'
         GROUP BY location
     ) latest ON fr.location = latest.location AND fr.record_date = latest.max_date
     WHERE fr.user_id = ? AND fr.status = 'Completed'
     GROUP BY fr.location, fr.activity"
);
$stmt->bind_param('ii', $uid, $uid);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// If a field had two different activities logged on its single most recent
// date (e.g. Irrigation and Pest Control same day), just keep the first —
// good enough for a visual sync, not worth over-engineering a tiebreaker.
$fieldStatus = [];
foreach ($rows as $row) {
    if (!isset($fieldStatus[$row['location']])) {
        $fieldStatus[$row['location']] = $row['activity'];
    }
}

echo json_encode(['success' => true, 'field_status' => $fieldStatus]);
