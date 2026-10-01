<?php
// ============================================================
//  RiceWise — Email Verification Handler  (verify_email.php)
// ============================================================
require_once 'config.php';

$state = 'invalid'; // invalid | expired | success
$user_name = '';
$dest = 'index.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';

if ($token !== '') {
    $stmt = $conn->prepare(
        "SELECT id, name, verification_expires, email_verified
         FROM users WHERE verification_token = ? LIMIT 1"
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if ((int)$row['email_verified'] === 1) {
            // Already verified before (e.g. link clicked twice)
            $state = 'success';
            $user_name = $row['name'];
        } elseif (strtotime($row['verification_expires']) < time()) {
            $state = 'expired';
        } else {
            $upd = $conn->prepare(
                "UPDATE users
                 SET email_verified = 1, verification_token = NULL, verification_expires = NULL
                 WHERE id = ?"
            );
            $upd->bind_param('i', $row['id']);
            $upd->execute();
            $upd->close();

            $state = 'success';
            $user_name = $row['name'];
            // No auto-login — user must sign in manually for extra security.
            $dest = 'index.php?verified=1';
        }
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RiceWise — Verify Email</title>
    <link rel="icon" type="image/png" href="pictures/RiceWise_Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,600;0,700;1,600&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --bg: #eef0ec;
            --white: #ffffff;
            --sage: #4a7a42;
            --sage-dark: #2d5228;
            --sage-pale: #e4efe1;
            --sage-mid: #b8d4b4;
            --text-primary: #111a10;
            --text-secondary: #4a5e45;
            --text-muted: #8fa489;
            --border: #dce8d8;
            --red: #c0392b;
            --red-pale: #fdecea;
            --shadow-md: 0 6px 28px rgba(0, 0, 0, .09);
            --radius-xs: 8px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .card {
            width: 100%;
            max-width: 440px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 46px 40px;
            box-shadow: var(--shadow-md);
            text-align: center;
        }

        .icon {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 22px;
            font-size: 32px;
        }

        .icon.ok {
            background: var(--sage-pale);
        }

        .icon.bad {
            background: var(--red-pale);
        }

        h1 {
            font-family: 'Lora', serif;
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        p {
            font-size: 15px;
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 26px;
        }

        .btn {
            display: inline-block;
            padding: 14px 28px;
            background: linear-gradient(135deg, var(--sage), var(--sage-dark));
            color: white;
            text-decoration: none;
            border-radius: var(--radius-xs);
            font-weight: 600;
            font-size: 15px;
        }
    </style>
</head>

<body>
    <div class="card">
        <?php if ($state === 'success'): ?>
            <div class="icon ok">✅</div>
            <h1>Email verified!</h1>
            <p>
                <?php echo $user_name ? 'Welcome, ' . htmlspecialchars($user_name) . '! ' : ''; ?>
                Your account is now active. Please sign in to continue.
            </p>
            <a class="btn" href="<?php echo htmlspecialchars($dest); ?>">Go to Sign In</a>

        <?php elseif ($state === 'expired'): ?>
            <div class="icon bad">⏰</div>
            <h1>Link expired</h1>
            <p>This verification link has expired. Please sign in — you'll be able to request a new verification email from there.</p>
            <a class="btn" href="index.php">Go to Sign In</a>

        <?php else: ?>
            <div class="icon bad">✕</div>
            <h1>Invalid link</h1>
            <p>This verification link is invalid or has already been used. If you already verified your account, just sign in.</p>
            <a class="btn" href="index.php">Go to Sign In</a>
        <?php endif; ?>
    </div>
</body>

</html>