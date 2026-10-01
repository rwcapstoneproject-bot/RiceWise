<?php
// ============================================================
//  RiceWise — Sign Up Page  (signup.php)
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
    <title>RiceWise — Create Account</title>
	<link rel="icon" type="image/png" href="pictures/RiceWise_Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,600;0,700;1,600&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap"
        rel="stylesheet">
    <!-- EmailJS SDK (client-side email sending — same as forgot_password.php) -->
    <script src="https://cdn.jsdelivr.net/npm/@emailjs/browser@4/dist/email.min.js"></script>
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
            z-index: -2;
            background-image: url('pictures/RiceWise_Background.png');
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            opacity: 0.25;
            pointer-events: none;
        }            
            
        /* ── Background ── */
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
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(74, 122, 66, .13), transparent 70%);
            top: -180px;
            right: -140px;
        }

        .o2 {
            width: 460px;
            height: 460px;
            background: radial-gradient(circle, rgba(200, 150, 62, .09), transparent 70%);
            bottom: -130px;
            left: -100px;
            animation-delay: -6s;
        }

        @keyframes orbFloat {

            0%,
            100% {
                transform: translate(0, 0)
            }

            50% {
                transform: translate(18px, -22px)
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
            height: 200px;
            z-index: 2;
            pointer-events: none;
            opacity: .18;
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

        .left {
            padding: 0 0 0 100px;           /* CHANGED — removed top/bottom padding, was 55px 0 65px 100px */
            display: flex;
            flex-direction: column;
            justify-content: center;        /* CHANGED — was space-between, centers to match right card */
            gap: 36px;                       /* ADD — controls spacing between brand / mid content */
            animation: fadeUp .4s ease both;
        }
            
        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(8px);   /* CHANGED — was 26px, much smaller nudge */
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

        /* ── Left hero ── */
        .mid {
            display: flex;
            flex-direction: column;
        }

        .eyebrow {
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 2.5px;
            text-transform: none;
            color: var(--sage);
            margin-bottom: 16px;              /* CHANGED — was 24px */
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
            font-size: 44px;                  /* CHANGED — was 54px */
            font-weight: 700;
            line-height: 1.12;
            letter-spacing: -.6px;
            margin-bottom: 18px;               /* CHANGED — was 24px */
            color: var(--text-primary);
        }

        h1 em {
            font-style: italic;
            background: linear-gradient(130deg, var(--sage-light) 20%, var(--gold));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .desc {
            font-size: 16px;
            color: var(--text-secondary);
            line-height: 1.65;
            max-width: 460px;                /* CHANGED — was 380px, wider so it wraps in 2 lines as intended */
            margin-bottom: 26px;
        }

        .features {
            display: flex;
            flex-direction: column;
            gap: 12px;                        /* CHANGED — was 15px */
        }

        .feat {
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: 16px;
            color: var(--text-secondary);
        }

        .feat-icon {
    width: 38px;                                    /* CHANGED — was 36px, slightly bigger for presence */
    height: 38px;
    background: linear-gradient(135deg, var(--gold-pale), #f5e2b8);   /* CHANGED — subtle gradient instead of flat pale gold */
    border: 1px solid var(--gold);
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--sage-dark);
    flex-shrink: 0;
    box-shadow: 0 3px 8px rgba(200, 150, 62, .18);   /* ADD — soft gold-tinted shadow for depth */
    transition: transform .2s ease, box-shadow .2s ease;   /* ADD — for hover */
}

.feat:hover .feat-icon {
    transform: translateY(-2px) scale(1.04);          /* ADD — subtle lift on hover */
    box-shadow: 0 6px 14px rgba(200, 150, 62, .28);
}

        .feat-icon svg {
            width: 20px;
            height: 20px;
        }

        /* ── Card ── */
        .right {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 60px 40px 0;
            transform: translateX(-25px);
            animation: fadeUp .4s ease both;
        }

        .card {
            width: 100%;
            max-width: 520px;
            min-height: 600px;              /* ADD — makes the card taller */
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 44px 42px;             /* CHANGED — was 32px 34px, more breathing room */
            box-shadow: var(--shadow-md);
            display: flex;                  /* ADD */
            flex-direction: column;         /* ADD */
            justify-content: center;        /* ADD — vertically centers content in the taller box */
        }
        .card-title {
            font-family: 'Lora', serif;
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--text-primary);
        }

        .card-sub {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 30px;                    /* CHANGED — was 30px */
        }

        .verify-icon {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--gold-pale), #f5e2b8);   /* CHANGED — was var(--sage-pale), now matches feature icons */
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 3px 8px rgba(200, 150, 62, .18);                     /* ADD — same soft gold shadow */
        }

        .verify-icon svg {
            width: 32px;
            height: 32px;
            color: var(--sage-dark);              /* CHANGED — was var(--sage), matches feature icon color */
        }
            
        .modal-close {
            position: absolute;
            top: 18px;
            right: 18px;
            width: 28px;
            height: 28px;
            border: none;
            background: none;
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: background .18s, color .18s;
        }

        .modal-close:hover {
            background: var(--sage-pale);
            color: var(--sage-dark);
        }

        .modal-close svg {
            width: 16px;
            height: 16px;
        }

        .modal-card {
            position: relative;   /* ADD — so .modal-close can anchor to it */
        }

        /* ── Alert ── */
        .alert {
            display: none;
            align-items: center;
            gap: 9px;
            padding: 12px 14px;
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

        .alert.ok {
            background: var(--sage-pale);
            color: var(--sage-dark);
            border-color: var(--sage-mid);
        }

        .alert svg {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
        }

        /* ── Form ── */
        .grid2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .field {
            margin-bottom: 18px;
            position: relative;
        }

        .field.full {
            grid-column: 1/-1;
        }

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

        .field:focus-within .f-label {
            color: var(--sage);
        }

        .f-input {
            height: 42px;
            width: 100%;
            background: var(--white);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-xs);
            padding: 10px 14px 10px 40px;   /* CHANGED — left padding for icon */
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            color: var(--text-primary);
            outline: none;
            transition: all .22s;
        }
            
        .f-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
            
        .f-icon {
            position: absolute;
            left: 15px;
            width: 16px;
            height: 16px;
            color: var(--text-muted);
            pointer-events: none;
            transition: color .2s;
        }

        .field:focus-within .f-icon {
            color: var(--sage);
        }

        .f-input::placeholder {
            color: var(--text-muted);
        }
        
        .f-input:focus {
            border-color: var(--sage-mid);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(74, 122, 66, .1);
        }

        .f-input.err-field {
            border-color: var(--red);
        }
            
        .f-toggle {
            position: absolute;
            right: 15px;
            width: 18px;
            height: 18px;
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

        #password, #confirmPassword {
            padding-right: 42px;
        }

        /* ── Password strength ── */
        .pw-strength {
            margin-top: 5px;
            height: 3px;
            border-radius: 3px;
            background: var(--border);
            overflow: hidden;
        }

        .pw-bar {
            height: 100%;
            width: 0;
            border-radius: 3px;
            transition: width .3s, background .3s;
        }

        .terms {
            font-size: 11px;
            color: var(--text-muted);
            margin: 16px 0 0;                       /* CHANGED — was 16px 0 0 */
            text-align: center;
            line-height: 1.4;
        }

        .terms a {
            color: var(--sage);
            text-decoration: none;
            font-weight: 500;
        }

        .terms a:hover {
            color: var(--sage-dark);
        }

        /* ── Button ── */
        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            margin-top: 16px;
            height: 44px;
            padding: 0 12px;
            background: linear-gradient(135deg, var(--sage), var(--sage-dark));
            border: none;
            border-radius: 7px;
            font-family: 'DM Sans', sans-serif;
            font-size: 12px;
            font-weight: 600;
            color: white;
            cursor: pointer;
            text-align: center;
            transition: all .28s;
            box-shadow: 0 5px 14px rgba(45, 82, 40, .20);
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
		    background: linear-gradient(135deg, var(--sage-dark), #1e3a1b);   /* CHANGED — darker on hover, no lift/shadow change */
        }

        .btn:active {
            transform: none;    /* CHANGED — was translateY(0), no longer needed since hover has no lift */
        }

        .btn:disabled {
            opacity: .65;
            cursor: not-allowed;
            transform: none !important;
        }

        .log-p {
            text-align: center;
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 14px;
        }

        .log-p a {
            color: var(--sage);
            text-decoration: none;
            font-weight: 600;
            transition: color .2s;
        }

        .log-p a:hover {
            color: var(--sage-dark);
        }
            
        .login-link {
            font-size: 12px;
            margin-top: 11px;
        }
            
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(17, 26, 16, .45);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            transition: opacity .2s ease;
        }

        .modal-overlay.show {
            display: flex;
            opacity: 1;
        }

        .modal-card {
            background: var(--white);
            border-radius: 22px;
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 420px;
            padding: 36px 34px;
            transform: translateY(10px);
            transition: transform .2s ease;
        }

        .modal-overlay.show .modal-card {
            transform: translateY(0);
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
            transition: width 1.4s cubic-bezier(.4, 0, .2, 1);
        }

        .loader-bar.go {
            width: 100%;
        }

        /* ── Responsive ── */
        @media (max-width: 960px) {
            h1 {
                font-size: 44px;
            }

            .left {
                padding: 45px 40px 50px 50px;
            }

            .right {
                padding: 40px 36px;
            }

            .card {
                padding: 38px 34px;
            }
        }

        @media (max-width:768px) {
            body {
            	overflow: hidden;
       	 	}

            .page {
                grid-template-columns: 1fr;
                min-height: 100vh
            }

            .left {
                display: none
            }

            .right {
                padding: 40px 20px;
                min-height: 100vh;
                align-items: center
            }

            .card {
                padding: 36px 26px;
                max-width: 100%
            }

            .grid2 {
                grid-template-columns: 1fr
            }

            .field.full {
                grid-column: 1
            }
        }

        @media (max-width:480px) {
            .right {
                padding: 24px 14px
            }

            .card {
                padding: 28px 20px
            }

            .card-title {
                font-size: 26px
            }

            .btn {
                font-size: 16px;
                padding: 15px
            }

            .f-input {
                font-size: 15px;
                padding: 12px 14px 12px 42px
            }
        }
    </style>
</head>

<body>

    <!-- Background photo -->
    <div class="bg-photo"></div>

    <!-- Loader overlay -->
    <div class="loader" id="loader">
        <div class="loader-stalk">🌾</div>
        <div class="loader-text" id="loaderText">Creating your account…</div>
        <div class="loader-track">
            <div class="loader-bar" id="loaderBar"></div>
        </div>
    </div>

    <div class="bg-orbs">
        <div class="orb o1"></div>
        <div class="orb o2"></div>
    </div>
    <div class="grain"></div>

    <!-- Rice stalks decoration -->
    <div class="stalks">
        <svg viewBox="0 0 1440 200" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" fill="none">
            <g stroke="#4a7a42" stroke-width="1.3">
                <line x1="80" y1="200" x2="75" y2="38" />
                <ellipse cx="74" cy="32" rx="5" ry="13" transform="rotate(-18 74 32)" />
                <line x1="165" y1="200" x2="170" y2="24" />
                <ellipse cx="171" cy="18" rx="5" ry="13" transform="rotate(12 171 18)" />
                <line x1="250" y1="200" x2="245" y2="53" />
                <ellipse cx="244" cy="47" rx="5" ry="13" transform="rotate(-10 244 47)" />
                <line x1="335" y1="200" x2="340" y2="34" />
                <ellipse cx="341" cy="28" rx="5" ry="13" transform="rotate(20 341 28)" />
                <line x1="420" y1="200" x2="415" y2="46" />
                <ellipse cx="414" cy="40" rx="5" ry="13" transform="rotate(-14 414 40)" />
                <line x1="510" y1="200" x2="515" y2="30" />
                <ellipse cx="516" cy="24" rx="5" ry="13" transform="rotate(8 516 24)" />
                <line x1="600" y1="200" x2="595" y2="48" />
                <ellipse cx="594" cy="42" rx="5" ry="13" transform="rotate(-22 594 42)" />
                <line x1="685" y1="200" x2="690" y2="36" />
                <ellipse cx="691" cy="30" rx="5" ry="13" transform="rotate(16 691 30)" />
                <line x1="775" y1="200" x2="770" y2="42" />
                <ellipse cx="769" cy="36" rx="5" ry="13" transform="rotate(-8 769 36)" />
                <line x1="860" y1="200" x2="865" y2="26" />
                <ellipse cx="866" cy="20" rx="5" ry="13" transform="rotate(10 866 20)" />
                <line x1="950" y1="200" x2="945" y2="50" />
                <ellipse cx="944" cy="44" rx="5" ry="13" transform="rotate(-16 944 44)" />
                <line x1="1040" y1="200" x2="1045" y2="31" />
                <ellipse cx="1046" cy="25" rx="5" ry="13" transform="rotate(14 1046 25)" />
                <line x1="1130" y1="200" x2="1125" y2="40" />
                <ellipse cx="1124" cy="34" rx="5" ry="13" transform="rotate(-6 1124 34)" />
                <line x1="1220" y1="200" x2="1225" y2="28" />
                <ellipse cx="1226" cy="22" rx="5" ry="13" transform="rotate(18 1226 22)" />
                <line x1="1310" y1="200" x2="1305" y2="46" />
                <ellipse cx="1304" cy="40" rx="5" ry="13" transform="rotate(-12 1304 40)" />
                <line x1="1400" y1="200" x2="1405" y2="36" />
                <ellipse cx="1406" cy="30" rx="5" ry="13" transform="rotate(10 1406 30)" />
            </g>
        </svg>
    </div>

    <div class="page">
        <!-- Left hero panel -->
        <div class="left">
            <div class="brand">
                <div class="brand-gem">
                    <img src="pictures/RiceWise_Logo.png" alt="RiceWise">
                </div>
                <span class="brand-txt">RiceWise</span>
            </div>
            <div class="mid">
                <div class="eyebrow">Join RiceWise</div>
                <h1>Start your <em>smart</em><br> farming journey</h1>
                <p class="desc">Join Filipino rice farmers who use RiceWise to grow more, spend less, and harvest smarter.</p>
                <div class="features">
                    <!-- Analytics -->
                    <div class="feat">
                        <div class="feat-icon">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M4 19V5a1 1 0 0 1 2 0v13h13a1 1 0 0 1 0 2H5a1 1 0 0 1-1-1Z"/>
                                <path d="M7 15.5a1 1 0 0 1 .3-.7l3-3a1 1 0 0 1 1.4 0l2.3 2.3 4.3-5.3a1 1 0 1 1 1.6 1.3l-5 6.2a1 1 0 0 1-1.5.1L11 13.9l-2.3 2.3a1 1 0 0 1-1.7-.7Z"/>
                                <path d="M16 7h4v4h-2V9h-2V7Z"/>
                            </svg>
                        </div>
                        <span>Real-time crop tracking &amp; analytics</span>
                    </div>

                    <!-- Financial -->
                    <div class="feat">
                        <div class="feat-icon">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 3a9 9 0 1 0 9 9 9.01 9.01 0 0 0-9-9Zm0 16a7 7 0 1 1 7-7 7.01 7.01 0 0 1-7 7Z"/>
                                <path d="M12.8 11.1c-1.2-.3-1.7-.5-1.7-1.1 0-.5.5-.9 1.2-.9.8 0 1.3.3 1.7.9l1.5-.8a3.5 3.5 0 0 0-2.3-1.5V6h-1.6v1.7c-1.4.3-2.3 1.2-2.3 2.5 0 1.7 1.4 2.3 3 2.7 1.2.3 1.7.6 1.7 1.2s-.6 1-1.4 1c-.9 0-1.6-.4-2-1.1l-1.5.8c.5 1 1.4 1.7 2.5 2v1.7h1.6v-1.6c1.5-.3 2.5-1.3 2.5-2.7 0-1.7-1.4-2.4-2.9-2.8Z"/>
                            </svg>
                        </div>
                        <span>Financial management &amp; profit insights</span>
                    </div>

                    <!-- Calendar -->
                    <div class="feat">
                        <div class="feat-icon">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M7 3a1 1 0 0 1 1 1v1h8V4a1 1 0 1 1 2 0v1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h1V4a1 1 0 0 1 1-1Zm11 7H5v8h13v-8ZM5 7v1h13V7H5Z"/>
                                <path d="M7 12h2v2H7v-2Zm4 0h2v2h-2v-2Zm4 0h2v2h-2v-2ZM7 15h2v2H7v-2Zm4 0h2v2h-2v-2Z"/>
                            </svg>
                        </div>
                        <span>Smart planting calendar &amp; reminders</span>
                    </div>

                    <!-- Weather -->
                    <div class="feat">
                        <div class="feat-icon">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M7.5 19a5.5 5.5 0 0 1-.6-10.97A6.5 6.5 0 0 1 19.3 10 4.5 4.5 0 0 1 18.5 19h-11Zm0-2h11a2.5 2.5 0 0 0 .4-4.97l-1.2-.2-.1-1.2A4.5 4.5 0 0 0 9 9.4l-.4 1.1-1.1-.2A3.5 3.5 0 0 0 7.5 17Z"/>
                                <path d="M5 5.5V3h2v2.5H5Zm-2 2H.5v-2H3v2Zm12-2V3h2v2.5h-2Z"/>
                            </svg>
                        </div>
                        <span>Weather impact analysis</span>
                    </div>
                </div>
            </div>
            <div></div>
        </div>

        <!-- Right signup card -->
        <div class="right">
            <div class="card">
                <div class="card-title">Create account</div>
                <div class="card-sub">Start your free farm management journey</div>

                <div class="alert err" id="signup-alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="15" y1="9" x2="9" y2="15" />
                        <line x1="9" y1="9" x2="15" y2="15" />
                    </svg>
                    <span id="alert-msg"></span>
                </div>

                <div class="grid2">
                    <div class="field">
                        <label class="f-label">Full Name</label>
                        <div class="f-input-wrap">
                            <svg class="f-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                <circle cx="12" cy="7" r="4" />
                            </svg>
                            <input class="f-input" type="text" id="fullName" placeholder="Juan dela Cruz" autocomplete="name">
                        </div>
                    </div>
                    <div class="field">
                        <label class="f-label">Username</label>
                        <div class="f-input-wrap">
                            <svg class="f-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="4" />
                                <path d="M16 12v1.5a2.5 2.5 0 0 0 5 0V12a9 9 0 1 0-5.5 8.28" />
                            </svg>
                            <input class="f-input" type="text" id="username" placeholder="farmer101" autocomplete="username">
                        </div>
                    </div>
                    <div class="field full">
                        <label class="f-label">Email Address</label>
                        <div class="f-input-wrap">
                            <svg class="f-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="4" width="20" height="16" rx="2" />
                                <path d="m22 7-10 6L2 7" />
                            </svg>
                            <input class="f-input" type="email" id="email" placeholder="juan@example.com" autocomplete="email">
                        </div>
                    </div>
                    <div class="field">
                        <label class="f-label">Password</label>
                        <div class="f-input-wrap">
                            <svg class="f-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                            </svg>
                            <input class="f-input" type="password" id="password" placeholder="••••••••" autocomplete="new-password">
                            <button type="button" class="f-toggle" id="pwToggle1" onclick="togglePw('password','eyeIcon1')">
                                <svg id="eyeIcon1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                            </button>
                        </div>
                        <div class="pw-strength">
                            <div class="pw-bar" id="pwBar"></div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="f-label">Confirm Password</label>
                        <div class="f-input-wrap">
                            <svg class="f-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                            </svg>
                            <input class="f-input" type="password" id="confirmPassword" placeholder="••••••••" autocomplete="new-password">
                            <button type="button" class="f-toggle" id="pwToggle2" onclick="togglePw('confirmPassword','eyeIcon2')">
                                <svg id="eyeIcon2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <p class="terms">By signing up you agree to our <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a></p>

                <button class="btn" id="signup-btn" onclick="handleSignup()">
                    Create Account
                </button>

                <p class="log-p">Already have an account? <a href="index.php">Sign in</a></p>
            </div>
        </div>
    </div>

    <!-- Email Verification Modal — moved outside .right so position:fixed isn't trapped by its transform -->
    <div class="modal-overlay" id="verifyOverlay">
    	<div class="modal-card">
        	<button type="button" class="modal-close" onclick="document.getElementById('verifyOverlay').classList.remove('show')">
            	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                	<line x1="18" y1="6" x2="6" y2="18" />
                	<line x1="6" y1="6" x2="18" y2="18" />
            	</svg>
        	</button>

        	<div class="verify-icon">
            	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                	<rect x="2" y="4" width="20" height="16" rx="2" />
                	<path d="m22 7-10 6L2 7" />
            	</svg>
        	</div>

        	<div class="card-title" style="text-align:center">Check your email</div>
        	<p class="card-sub" id="verify-sub" style="text-align:center; margin-bottom: 24px">
            We sent a link to your email to verify it.
        	</p>

        	<a href="index.php" class="btn" style="text-decoration:none; margin-top:0;">Already verified? Sign in</a>

        	<p class="terms" style="margin-top: 18px;">
            Didn't get it? Check your Spam or Promotions folder — the link expires in 24 hours.
        	</p>
    	</div>
	</div>

    <script>
        /* ── EmailJS config ──
           Same EmailJS account as forgot_password.php.
           You can reuse the same SERVICE_ID, and either reuse the same
           TEMPLATE_ID (using different variables) or create a second
           template dedicated to verification emails — either works,
           just make sure the {{...}} names below match your template. */
        (function() {
            emailjs.init({ publicKey: 'KWNzZSrV9nmHlluvL' });
        })();

        const EMAILJS_SERVICE_ID   = 'service_x4b0a8r';
        const EMAILJS_VERIFY_TEMPLATE_ID = 'template_is7bv58';

        /* ── Alert helpers ── */
        function showAlert(msg, fieldId, type) {
            const box = document.getElementById('signup-alert');
            document.getElementById('alert-msg').textContent = msg;
            box.className = 'alert ' + (type === 'ok' ? 'ok' : 'err');
            box.style.display = 'flex';
            if (fieldId) {
                const el = document.getElementById(fieldId);
                if (el) el.classList.add('err-field');
            }
        }

        function hideAlert() {
            document.getElementById('signup-alert').style.display = 'none';
        }
            
        function togglePw(inputId, eyeId) {
            const pw = document.getElementById(inputId);
            const eye = document.getElementById(eyeId);
            const isHidden = pw.type === 'password';
            pw.type = isHidden ? 'text' : 'password';
            eye.innerHTML = isHidden
                ? '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.6 21.6 0 0 1 5.06-6.06M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a21.6 21.6 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
                : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
        }

        /* ── Password strength indicator ── */
        document.getElementById('password').addEventListener('input', function() {
            const v = this.value;
            const bar = document.getElementById('pwBar');
            let score = 0;
            if (v.length >= 6) score++;
            if (v.length >= 10) score++;
            if (/[A-Z]/.test(v) && /[0-9]/.test(v)) score++;
            if (/[^A-Za-z0-9]/.test(v)) score++;
            const colours = ['#c0392b', '#e67e22', '#f1c40f', '#27ae60'];
            const widths = ['25%', '50%', '75%', '100%'];
            bar.style.width = v.length ? widths[score > 0 ? score - 1 : 0] : '0';
            bar.style.background = v.length ? colours[score > 0 ? score - 1 : 0] : 'transparent';
        });

        /* ── Clear field error on input ── */
        document.querySelectorAll('.f-input').forEach(inp => {
            inp.addEventListener('input', () => {
                inp.classList.remove('err-field');
                hideAlert();
            });
        });

        /* ── Signup handler ──
           1) POST to signup_process.php -> creates unverified user, returns verify_link
           2) Send the verification email via EmailJS (client-side)
           3) Show loader, then redirect to login with a "check your email" notice   */
        function handleSignup() {
            const fullName = document.getElementById('fullName').value.trim();
            const username = document.getElementById('username').value.trim();
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            const btn = document.getElementById('signup-btn');

            hideAlert();

            // Client-side validation
                if (!fullName || !username || !email || !password || !confirmPassword) {
                    showAlert('Please fill in all fields.');
                    // highlight whichever required fields are actually empty
                    [['fullName', fullName], ['username', username], ['email', email], ['password', password], ['confirmPassword', confirmPassword]]
                        .forEach(([id, val]) => {
                            if (!val) document.getElementById(id).classList.add('err-field');
                        });
                    return;
                }
                if (password !== confirmPassword) {
                    showAlert('Passwords do not match.');
                    document.getElementById('password').classList.add('err-field');
                    document.getElementById('confirmPassword').classList.add('err-field');
                    return;
                }
                if (password.length < 6) {
                    showAlert('Password must be at least 6 characters.');
                    document.getElementById('password').classList.add('err-field');
                    return;
                }

            btn.disabled = true;

            const body = new URLSearchParams({
                full_name: fullName,
                username,
                email,
                password,
                confirm_password: confirmPassword
            });

            fetch('signup_process.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body
                })
                .then(async response => {
                    const raw = await response.text();
                    let data;

                    try {
                        data = JSON.parse(raw);
                    } catch (parseError) {
                        console.error('Invalid JSON from signup_process.php:', raw);
                        throw new Error(
                            'The server returned an invalid response. Please check signup_process.php and your PHP error log.'
                        );
                    }

                    if (!response.ok) {
                        throw new Error(data.message || 'Registration request failed.');
                    }

                    return data;
                })
                .then(data => {
                    if (!data.success) {
                        showAlert(data.message || 'Registration failed.', data.field || null);
                        btn.disabled = false;
                        return;
                    }

                    // Account created — now send the verification email via EmailJS
                    const loader = document.getElementById('loader');
                    const loaderText = document.getElementById('loaderText');
                    const bar = document.getElementById('loaderBar');
                    loaderText.textContent = 'Sending verification email…';
                    loader.classList.add('show');

                    emailjs.send(EMAILJS_SERVICE_ID, EMAILJS_VERIFY_TEMPLATE_ID, {
                            to_email: data.email,
                            to_name: data.full_name,
                            verify_link: data.verify_link
                        })
                        .then(() => {
                            loader.classList.remove('show');
                            bar.classList.remove('go');

                            // Swap the signup form for the "check your email" view — no page navigation needed
                            // NEW — pops up the modal instead
                                const verifySub = document.getElementById('verify-sub');
                                verifySub.textContent = 'We sent a link to ';
                                const emailStrong = document.createElement('strong');
                                emailStrong.textContent = data.email;
                                verifySub.appendChild(emailStrong);
                                verifySub.appendChild(document.createTextNode(' to verify your email.'));
                                document.getElementById('verifyOverlay').classList.add('show');
                        })
                        .catch((err) => {
                            // Account exists in DB but the email failed to send.
                            console.error('EmailJS error:', err);
                            loader.classList.remove('show');
                            bar.classList.remove('go');
                            const detail = (err && (err.text || err.message)) ? ' (' + (err.text || err.message) + ')' : '';
                            showAlert('Account created, but we could not send the verification email' + detail + '.', null, 'err');
                            btn.disabled = false;
                        });
                })
                .catch((err) => {
                    console.error('Signup error:', err);
                    const message = err && err.message
                        ? err.message
                        : 'Server error. Please try again.';
                    showAlert(message);
                    btn.disabled = false;
                });
        }
    </script>
</body>

</html>