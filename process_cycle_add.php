<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['rw_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}
if (($_SESSION['rw_user_role'] ?? '') === 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not allowed for admin accounts.']);
    exit;
}
$uid = (int)$_SESSION['rw_user_id'];

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}
$fieldLocation = trim((string)($data['field_location'] ?? ''));
$cropType      = trim((string)($data['crop_type'] ?? ''));
$startDate     = trim((string)($data['start_date'] ?? ''));

if ($fieldLocation === '') {
    echo json_encode(['success' => false, 'message' => 'Please select or enter a field.']);
    exit;
}
if ($startDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    echo json_encode(['success' => false, 'message' => 'Please choose a valid start date.']);
    exit;
}
$d = DateTime::createFromFormat('Y-m-d', $startDate);
if (!$d || $d->format('Y-m-d') !== $startDate) {
    echo json_encode(['success' => false, 'message' => 'Please choose a valid start date.']);
    exit;
}

$conn->begin_transaction();
try {
    // 1) Create the planting cycle (same columns process.php already reads:
    //    id, field_location, crop_type, start_date, status, completed_at).
    $stmt = $conn->prepare(
        "INSERT INTO planting_cycles (user_id, field_location, crop_type, start_date, status)
         VALUES (?, ?, ?, ?, 'active')"
    );
    $stmt->bind_param('isss', $uid, $fieldLocation, $cropType, $startDate);
    $stmt->execute();
    $cycleId = $stmt->insert_id;
    $stmt->close();

    // 2) Auto-generate the full step timeline on the calendar — mirrors
    //    process.php's $STEP_TEMPLATE exactly, so both files stay in sync.
    $STEP_TEMPLATE = [
        ['name'=>'🚜 Land Preparation',    'desc'=>'Prepare field and soil leveling.',     'startOffset'=>0,  'endOffset'=>5,  'category'=>'other'],
        ['name'=>'🌱 Planting Seedlings',  'desc'=>'Transplant rice seedlings.',           'startOffset'=>6,  'endOffset'=>10, 'category'=>'planting'],
        ['name'=>'🌿 Weeding',             'desc'=>'Remove unwanted grass.',               'startOffset'=>11, 'endOffset'=>15, 'category'=>'other'],
        ['name'=>'⏱️ Growth Stage',        'desc'=>'Vegetative growth phase.',             'startOffset'=>16, 'endOffset'=>38, 'category'=>'other'],
        ['name'=>'🧪 Fertilizer',          'desc'=>'Apply nutrients.',                     'startOffset'=>23, 'endOffset'=>26, 'category'=>'fertilize'],
        ['name'=>'🐛 Insecticide',         'desc'=>'Prevent pest damage.',                 'startOffset'=>28, 'endOffset'=>30, 'category'=>'pest'],
        ['name'=>'💧 Spray Insecticide',   'desc'=>'Liquid spray pest control.',           'startOffset'=>33, 'endOffset'=>36, 'category'=>'pest'],
        ['name'=>'🍄 Spray Fungicide',     'desc'=>'Prevent fungal disease.',              'startOffset'=>36, 'endOffset'=>40, 'category'=>'pest'],
        ['name'=>'🌾 Harvest',             'desc'=>'Collect mature rice crops.',           'startOffset'=>50, 'endOffset'=>54, 'category'=>'harvest'],
    ];

    $evt = $conn->prepare(
        "INSERT INTO calendar_events
            (user_id, cycle_id, step_index, title, description, event_date, event_end_date, event_time, category, reminder_type)
         VALUES (?, ?, ?, ?, ?, ?, ?, '08:00:00', ?, 'same-day')"
    );

    foreach ($STEP_TEMPLATE as $idx => $t) {
        $stepDate = (new DateTime($startDate))->modify("+{$t['startOffset']} days")->format('Y-m-d');
        $stepTitle = $t['name'] . ' — ' . $fieldLocation;
        $stepDesc  = $t['desc'] . ($cropType !== '' ? ' (' . $cropType . ')' : '');
        $evt->bind_param(
            'iiisssss',
            $uid, $cycleId, $idx, $stepTitle, $stepDesc, $stepDate, $stepDate, $t['category']
        );
        $evt->execute();
    }
    $evt->close();

    // 3) Flag the cycle so process.php's backfill (for cycles that predate
    //    this feature) never touches this one again — even if the user
    //    later deletes calendar entries via calendar.php, they stay gone.
    $mark = $conn->prepare("UPDATE planting_cycles SET calendar_marked = 1 WHERE id = ?");
    $mark->bind_param('i', $cycleId);
    $mark->execute();
    $mark->close();

    $conn->commit();
    echo json_encode(['success' => true, 'id' => $cycleId]);
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to start process. Please try again.']);
}