<?php
// ============================================================
//  RiceWise — Check Your Email Page  (check_email.php)
//  Shown right after signup, before the account is verified.
// ============================================================
require_once 'config.php';

$email = isset($_GET['email']) ? trim($_GET['email']) : '';
$email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RiceWise — Check Your Email</title>
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
            --gold: #c8963e;
            --text-primary: #111a10;
            --text-secondary: #4a5e45;
            --text-muted: #8fa489;
            --border: #dce8d8;
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
            max-width: 460px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 48px 42px;
            box-shadow: var(--shadow-md);
            text-align: center;
        }

        .icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: var(--sage-pale);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 22px;
            font-size: 32px;
        }

        h1 {
            font-family: 'Lora', serif;
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        p {
            font-size: 15px;
            color: var(--text-secondary);
            line-height: 1.65;
            margin-bottom: 8px;
        }

        .email-chip {
            display: inline-block;
            background: var(--sage-pale);
            color: var(--sage-dark);
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 20px;
            margin: 6px 0 22px;
            font-size: 14px;
            word-break: break-all;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--sage), var(--sage-dark));
            color: white;
            text-decoration: none;
            border-radius: var(--radius-xs);
            font-weight: 600;
            font-size: 15px;
            box-shadow: 0 6px 22px rgba(45, 82, 40, .26);
        }

        .note {
            margin-top: 22px;
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        .note a {
            color: var(--sage);
            font-weight: 600;
            text-decoration: none;
        }

        .back {
            display: block;
            margin-top: 18px;
            font-size: 14px;
            color: var(--text-muted);
            text-decoration: none;
        }

        .back:hover {
            color: var(--sage);
        }
    </style>
</head>

<body>
    <div class="card">
        <div class="icon">📩</div>
        <h1>Check your email</h1>
        <?php if ($email): ?>
            <p>We sent a link to <strong><?php echo htmlspecialchars($email); ?></strong> to verify your email.</p>
        <?php else: ?>
            <p>We sent a link to your email address to verify it.</p>
        <?php endif; ?>
        <p>Click the link in that email to activate your RiceWise account.</p>

        <a class="btn" href="https://mail.google.com" target="_blank" rel="noopener">
            Open Gmail
        </a>

        <p class="note">
            Didn't get it? Check your Spam or Promotions folder — the link expires in 24 hours.
        </p>

        <a class="back" href="index.php">Already verified? Sign in</a>
    </div>
</body>

</html>