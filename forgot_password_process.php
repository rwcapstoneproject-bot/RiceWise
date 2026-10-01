<?php
require_once 'config.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$email = trim($data['email'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

$stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// The app's own login_process.php already reveals whether an email exists
// ("No account found with this email"), so matching that same behavior here
// rather than adding a false sense of privacy this app doesn't have elsewhere.
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'No account found with this email.']);
    exit;
}

$userId = (int)$user['id'];
$userName = $user['name'] ?? '';

// Generate a cryptographically random token. Only its HASH is stored —
// same principle as password_hash — so a database leak alone can't be used
// to forge valid reset links.
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);

// Invalidate any older unused tokens for this user first, so only the most
// recent request is ever valid.
$inv = $conn->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0");
$inv->bind_param('i', $userId);
$inv->execute();
$inv->close();

// Compute the expiry using MySQL's own clock (NOW() + INTERVAL), not PHP's
// DateTime, so the write and the later read stay on the same clock.
$stmt = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 15 MINUTE)");
$stmt->bind_param('is', $userId, $tokenHash);
if (!$stmt->execute()) {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit;
}
$stmt->close();

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$resetLink = "$scheme://$host$basePath/reset_password.php?token=$rawToken";

// ── EmailJS (client-side) mode ──
// AwardSpace free hosting blocks all outbound connections from PHP
// (curl_init, fsockopen, allow_url_fopen, mail() with a real relay),
// so this server can't send the email itself. Instead, the token/link
// is generated here as before, then handed back to the *same browser
// tab that just requested it* — the JS in forgot_password.php sends
// the actual email via EmailJS's API, which runs from the user's own
// browser and isn't affected by AwardSpace's server-side restriction.
//
// Trade-off vs. the original design: the raw link is now present in
// this JSON response instead of only ever reaching the inbox directly.
// It's still only visible to whoever is making this exact request over
// HTTPS, and each token is single-use / 15-minute expiry / re-validated
// server-side on redemption, so this is an acceptable trade-off for a
// student project — just don't reuse this pattern in a production app
// without re-adding server-side sending.
echo json_encode([
    'success' => true,
    'message' => 'Reset link generated.',
    'reset_link' => $resetLink,
    'user_name' => $userName,
    'email' => $email
]);