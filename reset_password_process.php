<?php
require_once 'config.php';
header('Content-Type: application/json');


$data = json_decode(file_get_contents('php://input'), true);
$token = trim($data['token'] ?? '');
$newPassword = $data['new_password'] ?? '';


if ($token === '' || strlen($newPassword) < 6) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}


$tokenHash = hash('sha256', $token);


// Re-validate server-side — the page already checked this on load, but
// that check must never be trusted alone (the token could have expired,
// or been used from another tab, in the time since the page rendered).
$stmt = $conn->prepare(
    "SELECT id, user_id FROM password_resets
     WHERE token_hash = ? AND used = 0 AND expires_at > NOW()
     LIMIT 1"
);
$stmt->bind_param('s', $tokenHash);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();


if (!$row) {
    echo json_encode([
        'success' => false,
        'message' => 'This reset link is invalid or has expired. Please request a new one.'
    ]);
    exit;
}


$userId = (int)$row['user_id'];
$hash = password_hash($newPassword, PASSWORD_DEFAULT);


$upd = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
$upd->bind_param('si', $hash, $userId);
$ok = $upd->execute();
$upd->close();


if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit;
}


// Mark every outstanding token for this user as used — not just the one
// that was submitted — so an old, still-unused reset email from earlier
// can't be used to reset the password again after this succeeds.
$mark = $conn->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ?");
$mark->bind_param('i', $userId);
$mark->execute();
$mark->close();


echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);

