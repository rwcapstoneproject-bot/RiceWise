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

$columns  = (int)($data['columns'] ?? 0);
$rows     = (int)($data['rows'] ?? 0);
$cellSize = (int)($data['cell_size'] ?? 52);
$grid     = $data['grid'] ?? null;

$validTypes = ['bare', 'seedling', 'growing', 'mature', 'harvested', 'flooded', 'water', 'path'];

if ($columns < 1 || $columns > 12 || $rows < 1 || $rows > 12) {
    echo json_encode(['success' => false, 'message' => 'Invalid grid dimensions.']);
    exit;
}
if ($cellSize < 20 || $cellSize > 120) {
    $cellSize = 52;
}
if (!is_array($grid) || count($grid) !== $columns * $rows) {
    echo json_encode(['success' => false, 'message' => 'Grid data does not match dimensions.']);
    exit;
}
foreach ($grid as $cell) {
    if (!in_array($cell, $validTypes, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid cell type in grid.']);
        exit;
    }
}

$gridJson = json_encode(array_values($grid));

$stmt = $conn->prepare(
    "INSERT INTO field_layouts (user_id, columns, `rows`, cell_size, grid_json)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE columns = VALUES(columns), `rows` = VALUES(`rows`),
                             cell_size = VALUES(cell_size), grid_json = VALUES(grid_json)"
);
$stmt->bind_param('iiiis', $uid, $columns, $rows, $cellSize, $gridJson);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
$stmt->close();