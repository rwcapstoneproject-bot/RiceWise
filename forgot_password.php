<?php
require_once 'config.php';
// No login required here — this page is specifically for people who can't log in.
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RiceWise - Forgot Password</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Lora:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap"
        rel="stylesheet">
    <!-- EmailJS SDK: sends the reset email from the browser, since AwardSpace
         blocks outbound connections from PHP. -->
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/@emailjs/browser@4/dist/email.min.js"></script>
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
            margin-bottom: 16px;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--sage-light);
            background: var(--white);
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
            margin: -8px 0 14px;
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

        .result-panel {
            display: none;
        }

        .result-panel.show {
            display: block;
        }

        #form-panel.hidden {
            display: none;
        }

        .result-icon {
            font-size: 40px;
            text-align: center;
            margin-bottom: 10px;
        }

        .result-title {
            font-family: 'Lora', serif;
            font-size: 18px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 8px;
        }

        .result-note {
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

        .expiry-note {
            font-size: 11.5px;
            color: var(--text-muted);
            text-align: center;
            margin-top: 10px;
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

        <div id="form-panel">
            <h1>Forgot your password?</h1>
            <p class="subtitle">Enter the email address on your account and we'll send you a reset link.</p>
            <form id="forgotForm">
                <label class="field-label">Email Address</label>
                <input type="email" id="email" class="form-input" placeholder="your@email.com" required>
                <div class="error-text" id="errorText"></div>
                <button type="submit" class="btn-submit" id="submitBtn">Send Reset Link</button>
            </form>
            <a href="index.php" class="back-link">← Back to Sign In</a>
        </div>

        <div id="result-panel" class="result-panel">
            <div class="result-icon">📧</div>
            <div class="result-title">Check your email</div>
            <p class="subtitle" style="text-align:center" id="resultSub"></p>
            <div class="result-note">Click the reset link we just emailed you to choose a new password.</div>
            <div class="expiry-note">The link expires in 15 minutes and can only be used once.</div>
            <a href="index.php" class="back-link">← Back to Sign In</a>
        </div>
    </div>

    <script>
        // ── EmailJS config ──
        // Fill these in with the values from your EmailJS dashboard.
        const EMAILJS_PUBLIC_KEY = 'KWNzZSrV9nmHlluvL';
        const EMAILJS_SERVICE_ID = 'service_x4b0a8r';
        const EMAILJS_TEMPLATE_ID = 'template_z22xlgo';

        emailjs.init({ publicKey: EMAILJS_PUBLIC_KEY });

        const form = document.getElementById('forgotForm');
        const errorText = document.getElementById('errorText');
        const submitBtn = document.getElementById('submitBtn');

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            errorText.classList.remove('show');
            const email = document.getElementById('email').value.trim();
            if (!email) return;

            submitBtn.disabled = true;
            submitBtn.textContent = 'Sending…';

            fetch('forgot_password_process.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email })
            }).then(res => res.json()).then(json => {
                if (!json.success) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Send Reset Link';
                    errorText.textContent = json.message || 'Something went wrong.';
                    errorText.classList.add('show');
                    return;
                }

                // Server generated the token/link — now send the actual
                // email from the browser via EmailJS.
                const templateParams = {
                    to_email: json.email,
                    to_name: json.user_name || 'there',
                    reset_link: json.reset_link
                };

                emailjs.send(EMAILJS_SERVICE_ID, EMAILJS_TEMPLATE_ID, templateParams)
                    .then(() => {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Send Reset Link';
                        document.getElementById('form-panel').classList.add('hidden');
                        document.getElementById('result-panel').classList.add('show');
                        document.getElementById('resultSub').textContent = `We've sent a reset link to ${email}.`;
                    })
                    .catch((err) => {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Send Reset Link';
                        errorText.textContent = 'Could not send the email. Please try again.';
                        errorText.classList.add('show');
                        console.error('EmailJS error:', err);
                    });
            }).catch(() => {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Send Reset Link';
                errorText.textContent = 'Network error — please try again.';
                errorText.classList.add('show');
            });
        });
    </script>
</body>

</html>