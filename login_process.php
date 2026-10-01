<?php
// ============================================================
//  RiceWise — Login Handler  (login_process.php)
// ============================================================
require_once 'config.php';

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$email    = trim(filter_input(INPUT_POST, 'email',    FILTER_SANITIZE_EMAIL));
$password = trim(filter_input(INPUT_POST, 'password', FILTER_DEFAULT));

// ── Basic validation ──────────────────────────────────────────
if (empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all fields.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit;
}

// ── Look up user ──────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT id, name, email, password_hash, role, location, is_blocked, email_verified
     FROM users
     WHERE email = ?
     LIMIT 1"
);
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$user   = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'No account found with this email.']);
    exit;
}

// ── Verify password ───────────────────────────────────────────
if (!password_verify($password, $user['password_hash'])) {
    echo json_encode(['success' => false, 'message' => 'Incorrect password. Please try again.']);
    exit;
}

// ── Blocked check ─────────────────────────────────────────────
if ((int)$user['is_blocked'] === 1) {
    echo json_encode([
        'success' => false,
        'blocked' => true,
        'message' => 'This account has been blocked. Contact your administrator.'
    ]);
    exit;
}

// ── Email verified check ────────────────────────────────────────
if ((int)$user['email_verified'] !== 1) {
    echo json_encode([
        'success' => false,
        'unverified' => true,
        'message' => 'Please verify your email before signing in. Check your inbox for the verification link.'
    ]);
    exit;
}

// ── Create session ────────────────────────────────────────────
$_SESSION['rw_user_id']   = $user['id'];
$_SESSION['rw_user_name'] = $user['name'];
$_SESSION['rw_user_email'] = $user['email'];
$_SESSION['rw_user_role'] = $user['role'];
$_SESSION['rw_location']  = $user['location'] ?? 'Philippines';

// Determine redirect
$redirect = ($user['role'] === 'admin') ? 'admin_dashboard.php' : 'dashboard.php';

echo json_encode([
    'success'  => true,
    'role'     => $user['role'],
    'redirect' => $redirect
]);
exit;
