<?php
require_once 'config.php';


$token = $_GET['token'] ?? '';
$tokenValid = false;


if ($token !== '') {
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare(
        "SELECT id FROM password_resets
         WHERE token_hash = ? AND used = 0 AND expires_at > NOW()
         LIMIT 1"
    );
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $tokenValid = (bool)$row;
}
?>
<!DOCTYPE html>
<html lang="en">


<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RiceWise - Reset Password</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Lora:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --cream: #faf7f2;
            --cream-2: #f3ede3;
            --sage: #5a7a52;
            --sage-dark: #3d5c38;
            --sage-light: #8aab82;
            --sage-pale: #e8f0e4;
            --gold: #c8963e;
            --gold-pale: #fdf6ec;
            --red: #c0392b;
            --red-pale: #fdecea;
            --text-primary: #1e2a1a;
            --text-secondary: #5a6655;
            --text-muted: #94a18e;
            --border: #ddd8cf;
            --border-light: #ede8e0;
            --white: #ffffff;
            --shadow-lg: 0 12px 40px rgba(90, 122, 82, .18);
            --radius: 16px;
            --radius-sm: 10px;
        }


        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }


        html,
        body {
            height: 100%;
        }


        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--cream);
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }


        .card {
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 420px;
            padding: 34px 32px;
        }


        .logo-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 22px;
        }


        .logo-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--sage), var(--sage-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }


        .logo-icon img {
            width: 22px;
            height: 22px;
            object-fit: contain;
        }


        .logo-text {
            font-family: 'Lora', serif;
            font-size: 20px;
            font-weight: 700;
        }


        h1 {
            font-family: 'Lora', serif;
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 6px;
        }


        .subtitle {
            font-size: 13.5px;
            color: var(--text-secondary);
            line-height: 1.5;
            margin-bottom: 22px;
        }


        .field-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--text-muted);
            margin-bottom: 6px;
            display: block;
        }


        .form-input {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 14.5px;
            font-family: inherit;
            color: var(--text-primary);
            background: var(--cream-2);
            margin-bottom: 6px;
        }


        .form-input:focus {
            outline: none;
            border-color: var(--sage-light);
            background: var(--white);
        }


        .pw-strength {
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: 16px;
        }


        .pw-fill {
            height: 100%;
            width: 0;
            border-radius: 2px;
            transition: width .3s, background .3s;
        }


        .btn-submit {
            width: 100%;
            padding: 12px;
            background: var(--sage);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 14.5px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: background .18s;
            margin-top: 6px;
        }


        .btn-submit:hover {
            background: var(--sage-dark);
        }


        .btn-submit:disabled {
            opacity: .6;
            cursor: not-allowed;
        }


        .error-text {
            font-size: 12.5px;
            color: var(--red);
            font-weight: 600;
            margin: -4px 0 14px;
            display: none;
        }


        .error-text.show {
            display: block;
        }


        .back-link {
            display: block;
            text-align: center;
            margin-top: 18px;
            font-size: 13px;
            color: var(--sage-dark);
            text-decoration: none;
            font-weight: 600;
        }


        .back-link:hover {
            color: var(--sage);
        }


        .center-icon {
            font-size: 40px;
            text-align: center;
            margin-bottom: 10px;
        }


        .center-title {
            font-family: 'Lora', serif;
            font-size: 18px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 8px;
        }


        .center-note {
            font-size: 13px;
            color: var(--text-secondary);
            text-align: center;
            line-height: 1.55;
            margin-bottom: 20px;
        }


        .success-note {
            background: var(--sage-pale);
            border: 1px solid var(--sage-light);
            border-radius: var(--radius-sm);
            padding: 12px 14px;
            font-size: 12.5px;
            color: var(--sage-dark);
            line-height: 1.55;
            margin: 16px 0;
            text-align: center;
        }


        .hidden {
            display: none;
        }
    </style>
</head>


<body>
    <div class="card">
        <div class="logo-row">
            <div class="logo-icon"><img src="pictures/RiceWise_Logo.png" alt=""
                    onerror="this.style.display='none';this.parentElement.textContent='🌾'"></div>
            <span class="logo-text">RiceWise</span>
        </div>


        <?php if (!$tokenValid): ?>
            <!-- Invalid / expired / already-used token -->
            <div class="center-icon">⚠️</div>
            <div class="center-title">This link isn't valid</div>
            <p class="center-note">This password reset link is invalid, has already been used, or expired (links only last 15 minutes). Please request a new one.</p>
            <a href="forgot_password.php" class="back-link" style="margin-top:0;">← Request a new reset link</a>
        <?php else: ?>
            <!-- Valid token: show the reset form -->
            <div id="form-panel">
                <h1>Choose a new password</h1>
                <p class="subtitle">Make it something you haven't used before.</p>
                <form id="resetForm">
                    <label class="field-label">New Password</label>
                    <input type="password" id="newPassword" class="form-input" placeholder="••••••••" minlength="6" required oninput="checkStrength(this.value)">
                    <div class="pw-strength">
                        <div class="pw-fill" id="pwFill"></div>
                    </div>
                    <label class="field-label">Confirm New Password</label>
                    <input type="password" id="confirmPassword" class="form-input" placeholder="••••••••" minlength="6" required>
                    <div class="error-text" id="errorText"></div>
                    <button type="submit" class="btn-submit" id="submitBtn">Reset Password</button>
                </form>
                <a href="index.php" class="back-link">← Back to Sign In</a>
            </div>


            <div id="success-panel" class="hidden">
                <div class="center-icon">✅</div>
                <div class="center-title">Password updated</div>
                <div class="success-note">Your password has been changed successfully. You can now sign in with your new password.</div>
                <a href="index.php" class="back-link" style="margin-top:0;">→ Go to Sign In</a>
            </div>
        <?php endif; ?>
    </div>


    <?php if ($tokenValid): ?>
        <script>
            const TOKEN = <?= json_encode($token) ?>;


            function checkStrength(val) {
                const fill = document.getElementById('pwFill');
                if (!val) {
                    fill.style.width = '0';
                    return;
                }
                let score = 0;
                if (val.length >= 8) score++;
                if (/[A-Z]/.test(val) && /[0-9]/.test(val)) score++;
                if (/[^A-Za-z0-9]/.test(val)) score++;
                const levels = [
                    { w: '30%', c: '#c0392b' },
                    { w: '60%', c: '#e67e22' },
                    { w: '85%', c: '#f1c40f' },
                    { w: '100%', c: '#27ae60' }
                ];
                const l = levels[score] || levels[0];
                fill.style.width = l.w;
                fill.style.background = l.c;
            }


            const form = document.getElementById('resetForm');
            const errorText = document.getElementById('errorText');
            const submitBtn = document.getElementById('submitBtn');


            form.addEventListener('submit', function(e) {
                e.preventDefault();
                errorText.classList.remove('show');


                const newPassword = document.getElementById('newPassword').value;
                const confirmPassword = document.getElementById('confirmPassword').value;


                if (newPassword.length < 6) {
                    errorText.textContent = 'Password must be at least 6 characters.';
                    errorText.classList.add('show');
                    return;
                }
                if (newPassword !== confirmPassword) {
                    errorText.textContent = 'Passwords do not match.';
                    errorText.classList.add('show');
                    return;
                }


                submitBtn.disabled = true;
                submitBtn.textContent = 'Resetting…';


                fetch('reset_password_process.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ token: TOKEN, new_password: newPassword })
                    })
                    .then(res => res.json())
                    .then(json => {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Reset Password';
                        if (!json.success) {
                            errorText.textContent = json.message || 'Something went wrong.';
                            errorText.classList.add('show');
                            return;
                        }
                        document.getElementById('form-panel').classList.add('hidden');
                        document.getElementById('success-panel').classList.remove('hidden');
                    })
                    .catch(() => {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Reset Password';
                        errorText.textContent = 'Network error — please try again.';
                        errorText.classList.add('show');
                    });
            });
        </script>
    <?php endif; ?>
</body>


</html>



