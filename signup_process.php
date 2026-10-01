<?php
// ============================================================
//  RiceWise — Sign Up Handler  (signup_process.php)
//  Now creates unverified accounts + returns a verify link
//  so the frontend can send it via EmailJS.
// ============================================================
require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

// ── Collect & sanitize inputs ─────────────────────────────────
$full_name        = trim(filter_input(INPUT_POST, 'full_name',        FILTER_SANITIZE_SPECIAL_CHARS));
$username         = trim(filter_input(INPUT_POST, 'username',         FILTER_SANITIZE_SPECIAL_CHARS));
$email            = trim(filter_input(INPUT_POST, 'email',            FILTER_SANITIZE_EMAIL));
$password         = trim(filter_input(INPUT_POST, 'password',         FILTER_DEFAULT));
$confirm_password = trim(filter_input(INPUT_POST, 'confirm_password', FILTER_DEFAULT));

// ── Validation ────────────────────────────────────────────────
if (!$full_name || !$username || !$email || !$password || !$confirm_password) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all fields.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'field' => 'email', 'message' => 'Invalid email format.']);
    exit;
}

if ($password !== $confirm_password) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
    exit;
}

// ── Block reserved / system emails ───────────────────────────
$reserved = ['admin@ricewise.com.ph', 'test@ricewise.com.ph'];
if (in_array(strtolower($email), $reserved)) {
    echo json_encode([
        'success' => false,
        'field' => 'email',
        'message' => 'This email address is not available for registration.'
    ]);
    exit;
}

// ── Check duplicate email ─────────────────────────────────────
$stmt = $conn->prepare("SELECT id, email_verified FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->close();
    echo json_encode([
        'success' => false,
        'field' => 'email',
        'message' => 'An account with this email already exists.'
    ]);
    exit;
}
$stmt->close();

// ── Check duplicate username ──────────────────────────────────
$stmt2 = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
$stmt2->bind_param('s', $username);
$stmt2->execute();
$stmt2->store_result();

if ($stmt2->num_rows > 0) {
    $stmt2->close();
    echo json_encode([
        'success' => false,
        'field' => 'username',
        'message' => 'This username is already taken.'
    ]);
    exit;
}
$stmt2->close();

// ── Generate verification token (valid 24 hours) ───────────────
$verify_token   = bin2hex(random_bytes(32));
$verify_expires = date('Y-m-d H:i:s', time() + (24 * 60 * 60));

// ── Insert new user (unverified) ────────────────────────────────
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$stmt3 = $conn->prepare(
    "INSERT INTO users (name, username, email, password_hash, role, email_verified, verification_token, verification_expires)
     VALUES (?, ?, ?, ?, 'user', 0, ?, ?)"
);
$stmt3->bind_param('ssssss', $full_name, $username, $email, $hash, $verify_token, $verify_expires);

if (!$stmt3->execute()) {
    $stmt3->close();
    echo json_encode(['success' => false, 'message' => 'Registration failed. Please try again.']);
    exit;
}
$stmt3->close();

// ── Build the verification link ───────────────────────────────
// Adjust the path below if signup.php lives in a subfolder on your host.
$scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'];
$base_path   = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$verify_link = "{$scheme}://{$host}{$base_path}/verify_email.php?token={$verify_token}";

// NOTE: We do NOT create a session here — the account stays "unverified"
// until the user clicks the link in their email. The frontend (signup.php)
// is responsible for actually sending that email via EmailJS using the
// data returned below.
echo json_encode([
    'success'            => true,
    'needs_verification' => true,
    'email'              => $email,
    'full_name'          => $full_name,
    'verify_link'        => $verify_link,
    'redirect'           => 'index.php?verify=pending'
]);
exit;