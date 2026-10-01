<?php
// ============================================================
//  RiceWise — Login Page  (index.php)
// ============================================================
require_once 'config.php';

// Redirect if already logged in
if (isset($_SESSION['rw_user_id'])) {
    $dest = ($_SESSION['rw_user_role'] === 'admin') ? 'admin_dashboard.php' : 'dashboard.php';
    header("Location: $dest");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RiceWise — Sign In</title>
	<link rel="icon" type="image/png" href="pictures/RiceWise_Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,600;0,700;1,600&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap"
        rel="stylesheet">
    <!-- EmailJS SDK: sends the password-reset email from the browser, since
         AwardSpace blocks outbound connections from PHP. Used by the
         "Forgot password?" modal below. -->
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/@emailjs/browser@4/dist/email.min.js"></script>
    <style>
        :root {
            --bg: #eef0ec;
            --white: #ffffff;
            --sage: #4a7a42;
            --sage-dark: #2d5228;
            --sage-light: #72a868;
            --sage-pale: #e4efe1;
            --sage-mid: #b8d4b4;
            --gold: #c8963e;
            --gold-pale: #fef6e8;
            --text-primary: #111a10;
            --text-secondary: #4a5e45;
            --text-muted: #8fa489;
            --border: #dce8d8;
            --border-light: #ecf2ea;
            --red: #c0392b;
            --red-pale: #fdecea;
            --red-border: #e8b4b0;
            --shadow-xs: 0 1px 4px rgba(0, 0, 0, .05);
            --shadow-sm: 0 2px 12px rgba(0, 0, 0, .07);
            --shadow-md: 0 6px 28px rgba(0, 0, 0, .09);
            --shadow-lg: 0 20px 56px rgba(0, 0, 0, .13);
            --radius: 16px;
            --radius-sm: 10px;
            --radius-xs: 8px;
        }

        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
            font-size: 16px;
        }

        /* ── Background photo layer ── */
        .bg-photo {
            position: fixed;
            inset: 0;
            z-index: -1;
            background-image: url('pictures/RiceWise_Background.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            opacity: 0.25;
            pointer-events: none;
        }

        /* ── Background decorations ── */
        .bg-orbs {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            overflow: hidden;
        }

        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            animation: orbFloat 16s ease-in-out infinite;
        }

        .o1 {
            width: 700px;
            height: 700px;
            background: radial-gradient(circle at 40% 40%, rgba(74, 122, 66, .13), transparent 70%);
            top: -220px;
            left: -200px;
        }

        .o2 {
            width: 500px;
            height: 500px;
            background: radial-gradient(circle at 60% 60%, rgba(200, 150, 62, .09), transparent 70%);
            bottom: -160px;
            right: -100px;
            animation-delay: -5s;
        }

        .o3 {
            width: 360px;
            height: 360px;
            background: radial-gradient(circle, rgba(114, 168, 104, .08), transparent 70%);
            top: 45%;
            left: 55%;
            animation-delay: -10s;
        }

        @keyframes orbFloat {

            0%,
            100% {
                transform: translate(0, 0) scale(1)
            }

            33% {
                transform: translate(22px, -30px) scale(1.04)
            }

            66% {
                transform: translate(-18px, 18px) scale(.97)
            }
        }

        .grain {
            position: fixed;
            inset: 0;
            z-index: 1;
            pointer-events: none;
            opacity: .018;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)'/%3E%3C/svg%3E");
            background-size: 280px;
        }

        .stalks {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 220px;
            z-index: 2;
            pointer-events: none;
            opacity: .2;
        }

        /* ── Layout ── */
        .page {
            position: relative;
            z-index: 3;
            width: 100%;
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            max-width: 1280px;
            margin: 0 auto;
        }

        /* Left-aligned, vertically centered (level with the login card) */
        .left {
            padding: 0 0 0 100px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 32px;
            animation: fadeUp .4s ease .08s both;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ── Brand ── */
        .brand {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .brand-gem {
            width: 60px;
            height: 60px;
            background: transparent;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: none;
            transition: transform .2s;
            flex-shrink: 0;
            padding: 0;
        }

        .brand-gem img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .brand-gem:hover {
            transform: scale(1.07);
        }

        .brand-txt {
            font-family: 'Lora', serif;
            font-size: 30px;
            font-weight: 700;
            letter-spacing: -.4px;
            color: var(--text-primary);
        }

        /* ── Hero ── */
        .hero-mid {
            padding: 0;
        }

        .eyebrow {
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            color: var(--sage);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .eyebrow::before {
            content: '';
            width: 24px;
            height: 1.5px;
            background: linear-gradient(90deg, var(--sage), var(--gold));
            border-radius: 2px;
        }

        h1 {
            font-family: 'Lora', serif;
            font-size: 44px;
            font-weight: 700;
            line-height: 1.12;
            letter-spacing: -.6px;
            margin-bottom: 18px;
            color: var(--text-primary);
        }

        h1 em {
            font-style: italic;
            background: linear-gradient(130deg, var(--sage-light) 20%, var(--gold));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-p {
            font-size: 16px;
            color: var(--text-secondary);
            line-height: 1.65;
            max-width: 380px;
        }

        /* ── Card ── */
        .right {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            padding: 52px 50px 52px 40px;
            animation: fadeUp .4s ease .08s both;
        }

        .card {
            width: 100%;
            max-width: 440px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 48px 44px;
            box-shadow: var(--shadow-md);
        }

        .card-title {
            font-family: 'Lora', serif;
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 5px;
            letter-spacing: -.4px;
            color: var(--text-primary);
        }

        .card-sub {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 30px;
        }

        /* ── Alerts ── */
        .alert {
            display: none;
            align-items: flex-start;
            gap: 10px;
            padding: 13px 14px;
            border-radius: var(--radius-xs);
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 18px;
            border: 1px solid;
            animation: alertIn .2s ease;
        }

        @keyframes alertIn {
            from {
                opacity: 0;
                transform: translateY(-6px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .alert.err {
            background: var(--red-pale);
            color: var(--red);
            border-color: var(--red-border);
        }

        .alert.blocked {
            background: var(--red-pale);
            color: var(--red);
            border-color: var(--red-border);
            align-items: center;
        }

        .alert svg {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        /* ── Form ── */
        .f-label {
            display: block;
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 1.4px;
            text-transform: none;
            color: var(--text-muted);
            margin-bottom: 6px;
            transition: color .2s;
        }

        .field {
            margin-bottom: 20px;
            position: relative;
        }

        .field:focus-within .f-label {
            color: var(--sage);
        }

        .f-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .f-icon {
            position: absolute;
            left: 17px;
            width: 18px;
            height: 18px;
            color: var(--text-muted);
            pointer-events: none;
            transition: color .2s;
            z-index: 2;
        }

        .field:focus-within .f-icon {
            color: var(--sage);
        }

        .f-input {
            width: 100%;
            background: var(--white);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-xs);
            padding: 10px 14px 10px 46px;
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            color: var(--text-primary);
            outline: none;
            transition: all .22s;
        }

        .f-input::placeholder {
            color: var(--text-muted);
        }

        .f-input:focus {
            border-color: var(--sage-mid);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(74, 122, 66, .1);
        }
            
        .f-input.error {
            border-color: var(--red);
        }

        .f-toggle {
            position: absolute;
            right: 17px;
            width: 19px;
            height: 19px;
            color: var(--text-muted);
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color .2s;
        }

        .f-toggle:hover {
            color: var(--sage);
        }

        #loginPassword {
            padding-right: 46px;
        }

        .fgt {
            text-align: right;
            margin-top: 8px;
        }

        .fgt a {
            font-size: 13px;
            color: var(--text-muted);
            text-decoration: none;
            transition: color .2s;
            cursor: pointer;
        }

        .fgt a:hover {
            color: var(--sage);
        }
        /* ── Button ── */
        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            width: 100%;
            height: 40px;
            margin-top: 28px;
            padding: 0 12px;
            background: linear-gradient(135deg, var(--sage), var(--sage-dark));
            border: none;
            border-radius: var(--radius-xs);
            font-family: 'DM Sans', sans-serif;
            font-size: 12px;
            font-weight: 600;
            color: white;
            cursor: pointer;
            text-align: center;
            transition: all .28s;
            box-shadow: 0 6px 22px rgba(45, 82, 40, .26);
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255, 255, 255, .16), transparent 60%);
            opacity: 0;
            transition: opacity .25s;
        }

        .btn:hover::before {
            opacity: 1;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--sage-dark), #1e3a1b);
        }

        .btn:active {
            transform: none;
        }

        .btn:disabled {
            opacity: .5;
            cursor: not-allowed;
            transform: none !important;
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 14px;
            margin: 24px 0;
        }

        .div-line {
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .div-text {
            font-size: 13px;
            color: var(--text-muted);
        }

        .sign-p {
            text-align: center;
            font-size: 11px;
            color: var(--text-muted);
        }

        .sign-p a {
            color: var(--sage);
            text-decoration: none;
            font-weight: 600;
            transition: color .2s;
        }

        .sign-p a:hover {
            color: var(--sage-dark);
        }

        /* ── Loader ── */
        .loader {
            position: fixed;
            inset: 0;
            background: var(--bg);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 20px;
            opacity: 0;
            visibility: hidden;
            transition: opacity .4s, visibility .4s;
        }

        .loader.show {
            opacity: 1;
            visibility: visible;
        }

        .loader-stalk {
            font-size: 52px;
            animation: sway 1.4s ease-in-out infinite;
        }

        @keyframes sway {

            0%,
            100% {
                transform: rotate(-6deg)
            }

            50% {
                transform: rotate(6deg)
            }
        }

        .loader-text {
            font-family: 'Lora', serif;
            font-size: 18px;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .loader-track {
            width: 130px;
            height: 3px;
            background: var(--border);
            border-radius: 3px;
            overflow: hidden;
        }

        .loader-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--sage), var(--gold));
            border-radius: 3px;
            width: 0;
            transition: width 1.3s cubic-bezier(.4, 0, .2, 1);
        }

        .loader-bar.go {
            width: 100%;
        }

        /* ═══════════════════════════════════════════
           Forgot Password Modal
           ═══════════════════════════════════════════ */
        .modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 1000;
            background: rgba(17, 26, 16, .45);
            backdrop-filter: blur(3px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            visibility: hidden;
            transition: opacity .22s ease, visibility .22s ease;
        }

        .modal-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-card {
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 400px;
            padding: 32px 30px;
            position: relative;
            transform: translateY(14px) scale(.97);
            transition: transform .25s cubic-bezier(.2, .8, .2, 1);
        }

        .modal-overlay.show .modal-card {
            transform: translateY(0) scale(1);
        }

        .modal-close {
            position: absolute;
            top: 30px;
            right: 16px;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: none;
            background: transparent;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: color .18s;
        }

        .modal-close:hover {
            color: var(--sage-dark);
        }

        .modal-close svg {
            width: 15px;
            height: 15px;
        }

        .modal-title {
            font-family: 'Lora', serif;
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 6px;
            padding-right: 26px;
        }

        .modal-sub {
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.5;
            margin-bottom: 22px;
        }

        .modal-error {
            font-size: 12.5px;
            color: var(--red);
            font-weight: 600;
            margin: -8px 0 14px;
            display: none;
        }

        .modal-error.show {
            display: block;
        }

        #modalResultPanel {
            display: none;
        }

        #modalResultPanel.show {
            display: block;
        }

        #modalFormPanel.hidden {
            display: none;
        }

        .modal-result-icon {
            font-size: 38px;
            text-align: center;
            margin-bottom: 8px;
        }

        .modal-result-title {
            font-family: 'Lora', serif;
            font-size: 18px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 8px;
        }

        .modal-result-note {
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

        .modal-expiry-note {
            font-size: 11.5px;
            color: var(--text-muted);
            text-align: center;
            margin-top: 6px;
        }

        .modal-back-btn {
            display: block;
            width: 100%;
            text-align: center;
            margin-top: 16px;
            font-size: 13px;
            color: var(--sage-dark);
            background: none;
            border: none;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
        }

        .modal-back-btn:hover {
            color: var(--sage);
        }

        /* ── Responsive ── */
        @media (max-width:960px) {
            h1 {
                font-size: 46px
            }

            .left {
                padding: 56px 50px
            }

            .right {
                padding: 40px 36px
            }
        }

        @media (max-width:768px) {
            body {
                overflow-y: auto;
            }

            .page {
                grid-template-columns: 1fr;
                min-height: 100vh;
                align-items: center;
            }

            .left {
                display: none;
            }

            .right {
                padding: 40px 20px;
                min-height: 100vh;
                align-items: center;
            }

            .card {
                padding: 38px 28px;
                max-width: 100%;
            }
        }

        @media (max-width:480px) {
            .right {
                padding: 24px 16px
            }

            .card {
                padding: 30px 22px
            }

            .card-title {
                font-size: 28px
            }

            .card-sub {
                font-size: 15px
            }

            .btn {
                font-size: 16px;
                padding: 15px
            }

            .f-input {
                font-size: 15px;
                padding: 13px 15px
            }
        }
    </style>
</head>

<body>

    <!-- Loading overlay -->
    <div class="loader" id="loader">
        <div class="loader-stalk">🌾</div>
        <div class="loader-text" id="loader-text">Signing you in…</div>
        <div class="loader-track">
            <div class="loader-bar" id="loaderBar"></div>
        </div>
    </div>

    <div class="bg-photo"></div>
    <div class="bg-orbs">
        <div class="orb o1"></div>
        <div class="orb o2"></div>
        <div class="orb o3"></div>
    </div>
    <div class="grain"></div>

    <!-- Rice stalks decoration -->
    <div class="stalks">
        <svg viewBox="0 0 1440 220" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" fill="none">
            <g stroke="#4a7a42" stroke-width="1.3">
                <line x1="60" y1="220" x2="55" y2="48" />
                <ellipse cx="54" cy="42" rx="5" ry="13" transform="rotate(-20 54 42)" />
                <line x1="130" y1="220" x2="135" y2="32" />
                <ellipse cx="136" cy="26" rx="5" ry="13" transform="rotate(12 136 26)" />
                <line x1="200" y1="220" x2="195" y2="62" />
                <ellipse cx="194" cy="56" rx="5" ry="13" transform="rotate(-8 194 56)" />
                <line x1="275" y1="220" x2="280" y2="40" />
                <ellipse cx="281" cy="34" rx="5" ry="13" transform="rotate(18 281 34)" />
                <line x1="350" y1="220" x2="345" y2="56" />
                <ellipse cx="344" cy="50" rx="5" ry="13" transform="rotate(-15 344 50)" />
                <line x1="430" y1="220" x2="435" y2="36" />
                <ellipse cx="436" cy="30" rx="5" ry="13" transform="rotate(10 436 30)" />
                <line x1="510" y1="220" x2="505" y2="52" />
                <ellipse cx="504" cy="46" rx="5" ry="13" transform="rotate(-22 504 46)" />
                <line x1="590" y1="220" x2="595" y2="28" />
                <ellipse cx="596" cy="22" rx="5" ry="13" transform="rotate(16 596 22)" />
                <line x1="665" y1="220" x2="660" y2="46" />
                <ellipse cx="659" cy="40" rx="5" ry="13" transform="rotate(-10 659 40)" />
                <line x1="740" y1="220" x2="745" y2="60" />
                <ellipse cx="746" cy="54" rx="5" ry="13" transform="rotate(7 746 54)" />
                <line x1="820" y1="220" x2="815" y2="34" />
                <ellipse cx="814" cy="28" rx="5" ry="13" transform="rotate(-18 814 28)" />
                <line x1="900" y1="220" x2="905" y2="50" />
                <ellipse cx="906" cy="44" rx="5" ry="13" transform="rotate(13 906 44)" />
                <line x1="975" y1="220" x2="970" y2="40" />
                <ellipse cx="969" cy="34" rx="5" ry="13" transform="rotate(-6 969 34)" />
                <line x1="1050" y1="220" x2="1055" y2="58" />
                <ellipse cx="1056" cy="52" rx="5" ry="13" transform="rotate(9 1056 52)" />
                <line x1="1130" y1="220" x2="1125" y2="31" />
                <ellipse cx="1124" cy="25" rx="5" ry="13" transform="rotate(-20 1124 25)" />
                <line x1="1210" y1="220" x2="1215" y2="46" />
                <ellipse cx="1216" cy="40" rx="5" ry="13" transform="rotate(14 1216 40)" />
                <line x1="1290" y1="220" x2="1285" y2="38" />
                <ellipse cx="1284" cy="32" rx="5" ry="13" transform="rotate(-12 1284 32)" />
                <line x1="1375" y1="220" x2="1380" y2="53" />
                <ellipse cx="1381" cy="47" rx="5" ry="13" transform="rotate(17 1381 47)" />
            </g>
        </svg>
    </div>

    <div class="page">
        <!-- Left hero panel (stats removed, content centered) -->
        <div class="left">
            <div class="brand">
                <div class="brand-gem">
                    <img src="pictures/RiceWise_Logo.png" alt="RiceWise">
                </div>
                <span class="brand-txt">RiceWise</span>
            </div>
            <div class="hero-mid">
                <div class="eyebrow">Smart Farm Management</div>
                <h1>Grow smarter,<br>harvest <em>better</em></h1>
                <p class="hero-p">A complete platform for Filipino rice farmers to track every crop cycle, optimize finances, and make data-driven decisions each season.</p>
            </div>
        </div>

        <!-- Right login card -->
        <div class="right">
            <div class="card">
                <div class="card-title">Welcome back</div>
                <div class="card-sub">Sign in to your farm dashboard</div>

                <!-- Standard error alert -->
                <div class="alert err" id="auth-alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="15" y1="9" x2="9" y2="15" />
                        <line x1="9" y1="9" x2="15" y2="15" />
                    </svg>
                    <span id="alert-msg"></span>
                </div>

                <!-- Blocked account alert -->
                <div class="alert blocked" id="blocked-alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="15" y1="9" x2="9" y2="15" />
                        <line x1="9" y1="9" x2="15" y2="15" />
                    </svg>
                    <span>This account has been blocked. Try a different account or contact your administrator.</span>
                </div>

                <div class="field">
                    <label class="f-label">Email Address</label>
                    <div class="f-input-wrap">
                        <svg class="f-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                        <input class="f-input" type="email" id="loginEmail" placeholder="farmer@ricewise.ph" autocomplete="email">
                    </div>
                </div>
                <div class="field">
                    <label class="f-label">Password</label>
                    <div class="f-input-wrap">
                        <svg class="f-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                        </svg>
                        <input class="f-input" type="password" id="loginPassword" placeholder="••••••••" autocomplete="current-password">
                        <button type="button" class="f-toggle" id="pwToggle" onclick="togglePassword()">
                            <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="19" height="19">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                        </button>
                    </div>
                    <div class="fgt"><a onclick="openForgotModal()">Forgot password?</a></div>
                </div>

                <button class="btn" id="login-btn" onclick="handleLogin()">
                    Sign In
                    
                </button>

                <div class="divider">
                    <div class="div-line"></div>
                    <span class="div-text">or</span>
                    <div class="div-line"></div>
                </div>

                <p class="sign-p">Don't have an account? <a href="signup.php">Create one</a></p>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         Forgot Password Modal
         ═══════════════════════════════════════════ -->
    <div class="modal-overlay" id="forgotModalOverlay" onclick="if(event.target===this) closeForgotModal()">
        <div class="modal-card">
            <button type="button" class="modal-close" onclick="closeForgotModal()" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
            </button>

            <div id="modalFormPanel">
                <div class="modal-title">Forgot your password?</div>
                <p class="modal-sub">Enter the email address on your account and we'll send you a reset link.</p>
                <form id="forgotForm" onsubmit="return false;" novalidate>
                    <label class="f-label">Email Address</label>
                    <div class="f-input-wrap" style="margin-bottom:16px">
                        <svg class="f-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                        <input type="email" id="forgotEmail" class="f-input" placeholder="Enter your email" required>
                    </div>
                    <div class="modal-error" id="forgotErrorText"></div>
                    <button type="submit" class="btn" id="forgotSubmitBtn" onclick="handleForgotSubmit()">Send Reset Link</button>
                </form>
            </div>

            <div id="modalResultPanel">
                <div class="modal-result-icon">📧</div>
                <div class="modal-result-title">Check your email</div>
                <p class="modal-sub" style="text-align:center" id="forgotResultSub"></p>
                <div class="modal-result-note">Click the reset link we just emailed you to choose a new password.</div>
                <div class="modal-expiry-note">The link expires in 15 minutes and can only be used once.</div>
                <button type="button" class="modal-back-btn" onclick="closeForgotModal()">← Back to Sign In</button>
            </div>
        </div>
    </div>

    <script>
        /* ── EmailJS config (shared with the forgot-password flow) ── */
        const EMAILJS_PUBLIC_KEY = 'KWNzZSrV9nmHlluvL';
        const EMAILJS_SERVICE_ID = 'service_x4b0a8r';
        const EMAILJS_TEMPLATE_ID = 'template_z22xlgo';
        emailjs.init({ publicKey: EMAILJS_PUBLIC_KEY });

        /* ── Alert helpers ── */
        function showAlert(msg) {
            hideBlockedAlert();
            const box = document.getElementById('auth-alert');
            document.getElementById('alert-msg').textContent = msg;
            box.style.display = 'flex';
            ['loginEmail', 'loginPassword'].forEach(id => {
                document.getElementById(id).classList.add('error');
            });
        }

        function hideAlert() {
            document.getElementById('auth-alert').style.display = 'none';
            ['loginEmail', 'loginPassword'].forEach(id => {
                document.getElementById(id).classList.remove('error');
            });
        }

        function showBlockedAlert() {
            hideAlert();
            document.getElementById('blocked-alert').style.display = 'flex';
        }

        function hideBlockedAlert() {
            document.getElementById('blocked-alert').style.display = 'none';
        }

        function showLoader(role) {
            const loader = document.getElementById('loader');
            const bar = document.getElementById('loaderBar');
            document.getElementById('loader-text').textContent =
                role === 'admin' ? 'Loading admin dashboard…' : 'Signing you in…';
            loader.classList.add('show');
            setTimeout(() => bar.classList.add('go'), 60);
        }
            
        function togglePassword() {
            const pw = document.getElementById('loginPassword');
            const eye = document.getElementById('eyeIcon');
            const isHidden = pw.type === 'password';
            pw.type = isHidden ? 'text' : 'password';
            eye.innerHTML = isHidden
                ? '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.6 21.6 0 0 1 5.06-6.06M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a21.6 21.6 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
                : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
        }

        /* ── Login handler — sends AJAX to login_process.php ── */
        function handleLogin() {
            const email = document.getElementById('loginEmail').value.trim();
            const password = document.getElementById('loginPassword').value;
            const btn = document.getElementById('login-btn');

            hideAlert();
            hideBlockedAlert();

            if (!email || !password) {
                showAlert('Please fill in all fields.');
                return;
            }
            btn.disabled = true;

            const body = new URLSearchParams({
                email,
                password
            });

            fetch('login_process.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showLoader(data.role);
                        setTimeout(() => window.location.href = data.redirect, 1500);
                    } else if (data.blocked) {
                        showBlockedAlert();
                        btn.disabled = false;
                    } else {
                        showAlert(data.message || 'Login failed. Please try again.');
                        btn.disabled = false;
                    }
                })
                .catch(() => {
                    showAlert('Server error. Please try again.');
                    btn.disabled = false;
                });
        }

        /* Clear alerts while typing; submit on Enter */
        document.querySelectorAll('#loginEmail, #loginPassword').forEach(inp => {
            inp.addEventListener('keydown', e => {
                if (e.key === 'Enter') handleLogin();
            });
            inp.addEventListener('input', () => {
                hideAlert();
                if (inp.id === 'loginEmail') hideBlockedAlert();
            });
        });

        /* ═══════════════════════════════════════════
           Forgot Password modal logic
           ═══════════════════════════════════════════ */
        const forgotOverlay = document.getElementById('forgotModalOverlay');
        const forgotFormPanel = document.getElementById('modalFormPanel');
        const forgotResultPanel = document.getElementById('modalResultPanel');
        const forgotErrorText = document.getElementById('forgotErrorText');
        const forgotSubmitBtn = document.getElementById('forgotSubmitBtn');

        function openForgotModal() {
            forgotFormPanel.classList.remove('hidden');
            forgotResultPanel.classList.remove('show');
            forgotErrorText.classList.remove('show');
            forgotErrorText.textContent = '';
            document.getElementById('forgotEmail').classList.remove('error');
            document.getElementById('forgotEmail').value = '';
            forgotSubmitBtn.disabled = false;
            forgotSubmitBtn.textContent = 'Send Reset Link';

            forgotOverlay.classList.add('show');
            document.body.style.overflow = 'hidden';
            setTimeout(() => document.getElementById('forgotEmail').focus(), 200);
        }

        function closeForgotModal() {
            forgotOverlay.classList.remove('show');
            document.body.style.overflow = '';
        }

        // Escape key closes the modal
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && forgotOverlay.classList.contains('show')) {
                closeForgotModal();
            }
        });

        function handleForgotSubmit() {
            forgotErrorText.classList.remove('show');
            const email = document.getElementById('forgotEmail').value.trim();

            if (!email) {
                    forgotErrorText.textContent = 'Email is required.';
                    forgotErrorText.classList.add('show');
                    document.getElementById('forgotEmail').classList.add('error');
                    return;
                }

            forgotSubmitBtn.disabled = true;
            forgotSubmitBtn.textContent = 'Sending…';

            fetch('forgot_password_process.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email })
            }).then(res => res.json()).then(json => {
                if (!json.success) {
                    forgotSubmitBtn.disabled = false;
                    forgotSubmitBtn.textContent = 'Send Reset Link';
                    forgotErrorText.textContent = json.message || 'Something went wrong.';
                    forgotErrorText.classList.add('show');
                    return;
                }

                // Server generated the token/link — send the actual email
                // from the browser via EmailJS, same as before.
                const templateParams = {
                    to_email: json.email,
                    to_name: json.user_name || 'there',
                    reset_link: json.reset_link
                };

                emailjs.send(EMAILJS_SERVICE_ID, EMAILJS_TEMPLATE_ID, templateParams)
                    .then(() => {
                        forgotSubmitBtn.disabled = false;
                        forgotSubmitBtn.textContent = 'Send Reset Link';
                        forgotFormPanel.classList.add('hidden');
                        forgotResultPanel.classList.add('show');
                        document.getElementById('forgotResultSub').textContent = `We've sent a reset link to ${email}.`;
                    })
                    .catch((err) => {
                        forgotSubmitBtn.disabled = false;
                        forgotSubmitBtn.textContent = 'Send Reset Link';
                        forgotErrorText.textContent = 'Could not send the email. Please try again.';
                        forgotErrorText.classList.add('show');
                        console.error('EmailJS error:', err);
                    });
            }).catch(() => {
                forgotSubmitBtn.disabled = false;
                forgotSubmitBtn.textContent = 'Send Reset Link';
                forgotErrorText.textContent = 'Network error — please try again.';
                forgotErrorText.classList.add('show');
            });
        }

        // Clear the forgot-password error while typing
        document.getElementById('forgotEmail').addEventListener('input', () => {
            forgotErrorText.classList.remove('show');
            document.getElementById('forgotEmail').classList.remove('error');
        });
    </script>
</body>

</html>