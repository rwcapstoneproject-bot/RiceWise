<?php
require_once 'config.php';
if (!isset($_SESSION['rw_user_id'])) {
    header('Location: login.php');
    exit;
}
if ($_SESSION['rw_user_role'] === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}

$uid = (int)$_SESSION['rw_user_id'];

$stmt = $conn->prepare("SELECT id,name,email FROM users WHERE id=? LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) {
    header('Location: logout.php');
    exit;
}
$_SESSION['rw_user_name'] = $user['name'];

$rs = $conn->prepare(
    "SELECT pc.id, pc.field_location, pc.crop_type, pc.start_date, pc.status, pc.completed_at,
            (SELECT COUNT(*) FROM planting_process_completed ppc WHERE ppc.cycle_id = pc.id) AS steps_done
     FROM planting_cycles pc
     WHERE pc.user_id = ?
     ORDER BY pc.start_date DESC, pc.id DESC"
);
$rs->bind_param('i', $uid);
$rs->execute();
$cycles = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
$rs->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RiceWise - Process History</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Lora:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap"
        rel="stylesheet">
    <script>
        if (window.innerWidth > 768 && localStorage.getItem('sidebarCollapsed') === '1') {
            document.documentElement.classList.add('sidebar-pre-collapse');
        }
    </script>
    <style>
        :root {
            --cream: #faf7f2;
            --cream-2: #f3ede3;
            --cream-3: #ebe3d5;
            --sage: #5a7a52;
            --sage-dark: #3d5c38;
            --sage-light: #8aab82;
            --sage-pale: #e8f0e4;
            --gold: #c8963e;
            --gold-pale: #fdf6ec;
            --text-primary: #1e2a1a;
            --text-secondary: #5a6655;
            --text-muted: #94a18e;
            --border: #ddd8cf;
            --border-light: #ede8e0;
            --white: #ffffff;
            --shadow-xs: 0 1px 4px rgba(0, 0, 0, .05);
            --shadow-sm: 0 2px 8px rgba(90, 122, 82, .08);
            --shadow-md: 0 6px 24px rgba(90, 122, 82, .12);
            --shadow-lg: 0 12px 40px rgba(90, 122, 82, .18);
            --radius: 16px;
            --radius-sm: 10px;
            --sidebar-w: 252px;
            --header-h: 64px;
        }

        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box
        }

        html,
        body {
            height: 100%
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--cream);
            color: var(--text-primary);
            display: flex;
            height: 100vh;
            overflow: hidden;
            font-size: 15px
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .38);
            z-index: 299;
            backdrop-filter: blur(3px)
        }

        .sidebar-overlay.show {
            display: block
        }

        .sidebar {
            width: var(--sidebar-w);
            background: var(--white);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            z-index: 300;
            overflow: hidden;
            transition: width .3s cubic-bezier(.4, 0, .2, 1)
        }

        body.sidebar-collapsed .sidebar {
            width: 68px
        }

        body.sidebar-collapsed .logo-wrap,
        body.sidebar-collapsed .logo-text,
        body.sidebar-collapsed .nav-label,
        body.sidebar-collapsed .nav-text,
        body.sidebar-collapsed .signout-text,
        body.sidebar-collapsed .toggle-btn {
            display: none
        }

        body.sidebar-collapsed .expand-btn {
            display: flex
        }

        body.sidebar-collapsed .sidebar-top {
            padding: 16px 0;
            justify-content: center
        }

        body.sidebar-collapsed .nav-section {
            padding: 10px 0;
            align-items: center;
            display: flex;
            flex-direction: column;
            gap: 2px
        }

        body.sidebar-collapsed .nav-item {
            width: 44px;
            height: 44px;
            justify-content: center;
            padding: 0
        }

        body.sidebar-collapsed .nav-item.active::before {
            display: none
        }

        body.sidebar-collapsed .nav-item svg {
            width: 20px;
            height: 20px
        }

        body.sidebar-collapsed .sidebar-bottom {
            padding: 10px 0 16px;
            display: flex;
            justify-content: center;
            align-items: center
        }

        body.sidebar-collapsed .signout-btn {
            width: 44px;
            height: 44px;
            justify-content: center;
            padding: 0;
            gap: 0
        }

        body.sidebar-collapsed .nav-item::after {
            content: attr(data-tooltip);
            position: absolute;
            left: calc(100% + 12px);
            top: 50%;
            transform: translateY(-50%);
            background: var(--text-primary);
            color: white;
            font-size: 12px;
            font-weight: 500;
            padding: 5px 10px;
            border-radius: 6px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity .15s;
            z-index: 9999
        }

        body.sidebar-collapsed .nav-item:hover::after {
            opacity: 1
        }

        .sidebar-top {
            padding: 18px 16px 14px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            flex-shrink: 0
        }

        .logo-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            min-width: 0;
            flex: 1;
            text-decoration: none
        }

        .logo-icon {
            width: 36px;
            height: 36px;
            flex-shrink: 0;
            background: linear-gradient(135deg, var(--sage), var(--sage-dark));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            box-shadow: 0 3px 10px rgba(40, 80, 30, .28);
            transition: transform .2s;
            overflow: hidden
        }

        .logo-wrap:hover .logo-icon {
            transform: scale(1.08)
        }

        .logo-icon img {
            width: 22px;
            height: 22px;
            object-fit: contain
        }

        .logo-text {
            font-family: 'Lora', serif;
            font-size: 19px;
            font-weight: 700;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden
        }

        .toggle-btn {
            width: 30px;
            height: 30px;
            flex-shrink: 0;
            background: var(--cream-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            margin-left: auto;
            transition: background .2s
        }

        .toggle-btn:hover {
            background: var(--sage-pale)
        }

        .toggle-btn svg {
            width: 14px;
            height: 14px;
            stroke: var(--text-muted)
        }

        .expand-btn {
            display: none;
            width: 44px;
            height: 44px;
            flex-shrink: 0;
            background: linear-gradient(135deg, var(--sage), var(--sage-dark));
            border: none;
            border-radius: 12px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 3px 10px rgba(40, 80, 30, .28);
            transition: transform .2s;
            overflow: hidden
        }

        .expand-btn:hover {
            transform: scale(1.08)
        }

        .expand-btn img {
            width: 22px;
            height: 22px;
            object-fit: contain;
            display: block
        }

        .nav-section {
            padding: 8px 12px;
            flex: 1;
            overflow: hidden
        }

        .nav-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: var(--text-muted);
            padding: 0 8px;
            margin-bottom: 3px;
            margin-top: 14px;
            white-space: nowrap;
            overflow: hidden
        }

        .nav-label:first-child {
            margin-top: 6px
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 10px;
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: var(--radius-sm);
            transition: all .18s;
            font-size: 14px;
            font-weight: 500;
            position: relative;
            white-space: nowrap;
            overflow: hidden
        }

        .nav-item:hover {
            background: var(--sage-pale);
            color: var(--sage-dark)
        }

        .nav-item.active {
            background: var(--sage-pale);
            color: var(--sage-dark);
            font-weight: 600
        }

        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 20px;
            background: var(--sage);
            border-radius: 0 3px 3px 0
        }

        .nav-item svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0
        }

        .sidebar-bottom {
            padding: 10px 12px 16px;
            border-top: 1px solid var(--border);
            flex-shrink: 0;
            display: flex
        }

        .signout-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            width: 100%;
            padding: 10px 13px;
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all .18s;
            font-family: inherit;
            white-space: nowrap;
            overflow: hidden
        }

        .signout-btn:hover {
            background: var(--sage-pale);
            color: var(--sage-dark);
            border-color: var(--sage-light)
        }

        .main-content {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            height: 100vh
        }

        .header {
            height: var(--header-h);
            flex-shrink: 0;
            background: rgba(250, 247, 242, .96);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border-light);
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 100;
            gap: 14px
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: 'Lora', serif;
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .hamburger-btn {
            display: none;
            width: 38px;
            height: 38px;
            background: var(--cream-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            transition: background .18s
        }

        .hamburger-btn:hover {
            background: var(--sage-pale)
        }

        .hamburger-btn svg {
            width: 18px;
            height: 18px;
            stroke: var(--text-secondary)
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 6px
        }

        .notif-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            border: none;
            background: transparent;
            transition: background .2s
        }

        .notif-btn:hover {
            background: var(--sage-pale)
        }

        .notif-btn svg {
            stroke: var(--text-secondary);
            fill: none
        }

        .notif-btn.active svg {
            fill: var(--sage);
            stroke: var(--sage)
        }

        .header-sep {
            width: 1px;
            height: 24px;
            background: var(--border);
            margin: 0 4px
        }

        .profile-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 8px 5px 12px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 50px;
            cursor: pointer;
            transition: border-color .2s
        }

        .profile-pill:hover {
            border-color: var(--sage-light)
        }

        .profile-pill-name {
            font-size: 14px;
            color: var(--text-secondary);
            font-weight: 500
        }

        .profile-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--sage-pale);
            border: 1.5px solid var(--sage-light);
            display: flex;
            align-items: center;
            justify-content: center
        }

        .profile-avatar svg {
            width: 15px;
            height: 15px;
            stroke: var(--sage)
        }

        .down-arrow {
            width: 12px;
            height: 12px;
            stroke: var(--text-muted);
            transition: transform .3s
        }

        .profile-pill.open .down-arrow {
            transform: rotate(180deg)
        }

        .notif-drop {
            position: fixed;
            top: calc(var(--header-h) + 6px);
            right: 180px;
            width: 310px;
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            z-index: 50000;
            display: none;
            overflow: hidden
        }

        .notif-drop.show {
            display: block
        }

        .nd-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-light)
        }

        .nd-title {
            font-family: 'Lora', serif;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .mark-read-btn {
            background: none;
            border: none;
            color: var(--sage);
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            font-family: inherit
        }

        .nd-list {
            max-height: 270px;
            overflow-y: auto
        }

        .nd-item {
            display: flex;
            gap: 10px;
            padding: 10px 14px;
            border-bottom: 1px solid var(--border-light);
            cursor: pointer;
            transition: background .15s
        }

        .nd-item.unread {
            background: var(--sage-pale)
        }

        .nd-item.starred {
            border-left: 3px solid #e6a817
        }

        .nd-item:hover {
            background: var(--cream-2)
        }

        .nd-icon {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: var(--cream-2);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0
        }

        .nd-icon svg {
            width: 14px;
            height: 14px;
            stroke: var(--sage)
        }

        .nd-item-title {
            font-weight: 600;
            font-size: 12.5px;
            color: var(--text-primary)
        }

        .nd-item-desc {
            font-size: 11.5px;
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px
        }

        .nd-item-date {
            font-size: 10.5px;
            color: var(--text-muted);
            font-family: 'DM Mono', monospace
        }

        .nd-foot {
            text-align: center;
            padding: 10px;
            font-size: 12px;
            border-top: 1px solid var(--border-light)
        }

        .nd-foot a {
            color: var(--sage);
            text-decoration: none;
            font-weight: 600
        }

        .profile-drop {
            position: fixed;
            top: calc(var(--header-h) + 6px);
            right: 20px;
            width: 200px;
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            z-index: 50000;
            padding: 14px;
            display: none
        }

        .profile-drop.show {
            display: block
        }

        .profile-drop h3 {
            font-family: 'Lora', serif;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 2px
        }

        .profile-drop p {
            font-size: 11.5px;
            color: var(--text-muted)
        }

        .profile-drop hr {
            border: none;
            border-top: 1px solid var(--border-light);
            margin: 8px 0
        }

        .pd-btn {
            display: block;
            width: 100%;
            text-align: left;
            background: none;
            border: none;
            padding: 7px 10px;
            font-size: 13px;
            color: var(--text-secondary);
            text-decoration: none;
            cursor: pointer;
            border-radius: 8px;
            transition: all .18s;
            font-family: inherit
        }

        .pd-btn:hover {
            background: var(--sage-pale);
            color: var(--sage-dark)
        }

        .history-scroll {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 22px 24px
        }

        .history-scroll::-webkit-scrollbar {
            width: 3px
        }

        .history-scroll::-webkit-scrollbar-thumb {
            background: var(--cream-3);
            border-radius: 2px
        }

        .history-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            flex-wrap: wrap;
            gap: 10px
        }

        .history-title {
            font-family: 'Lora', serif;
            font-size: 20px;
            font-weight: 700
        }

        .history-back-link {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--sage-dark);
            text-decoration: none
        }

        .history-back-link:hover {
            color: var(--sage)
        }

        .cycle-card {
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 18px 20px;
            margin-bottom: 12px;
            box-shadow: var(--shadow-xs);
            cursor: pointer;
            transition: all .18s;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap
        }

        .cycle-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-1px);
            border-color: var(--sage-light)
        }

        .cycle-card-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0
        }

        .cycle-card-icon.active {
            background: var(--gold-pale)
        }

        .cycle-card-icon.completed {
            background: var(--sage-pale)
        }

        .cycle-card-body {
            flex: 1;
            min-width: 180px
        }

        .cycle-card-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .cycle-card-meta {
            font-size: 12.5px;
            color: var(--text-muted);
            margin-top: 3px;
            font-family: 'DM Mono', monospace
        }

        .cycle-card-badge {
            font-size: 11.5px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            white-space: nowrap
        }

        .cycle-card-badge.active {
            background: var(--gold-pale);
            color: var(--gold)
        }

        .cycle-card-badge.completed {
            background: var(--sage-pale);
            color: var(--sage-dark)
        }

        .cycle-card-progress {
            font-size: 12.5px;
            color: var(--text-secondary);
            font-weight: 600;
            font-family: 'DM Mono', monospace;
            white-space: nowrap
        }

        .no-cycles {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted)
        }

        .no-cycles-icon {
            font-size: 42px;
            opacity: .5;
            margin-bottom: 12px
        }

        @media(max-width:768px) {

            html,
            body {
                height: auto !important;
                overflow-x: hidden !important;
                overflow-y: auto !important
            }

            body {
                display: block !important
            }

            .sidebar {
                position: fixed !important;
                top: 0;
                left: 0;
                bottom: 0;
                height: 100% !important;
                width: var(--sidebar-w) !important;
                transform: translateX(-100%);
                box-shadow: var(--shadow-lg);
                z-index: 300;
                overflow-y: auto;
                transition: transform .3s cubic-bezier(.4, 0, .2, 1) !important
            }

            .sidebar.open {
                transform: translateX(0) !important
            }

            .main-content {
                display: block !important;
                width: 100% !important;
                height: auto !important;
                overflow: visible !important
            }

            .header {
                padding: 0 16px
            }

            .hamburger-btn {
                display: flex !important
            }

            .profile-pill-name {
                display: none
            }

            .history-scroll {
                overflow: visible !important;
                height: auto !important;
                padding: 16px
            }

            .notif-drop {
                right: 10px;
                left: 10px;
                width: auto
            }

            .profile-drop {
                right: 10px
            }

            body.nav-open {
                overflow: hidden !important
            }
        }
    </style>
</head>

<body>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-top">
            <div class="logo-wrap" onclick="toggleSidebar()" title="Toggle sidebar">
                <div class="logo-icon"><img src="pictures/RiceWise_Logo.png" alt=""
                        onerror="this.style.display='none';this.parentElement.textContent='🌾'"></div>
                <span class="logo-text">RiceWise</span>
            </div>
            <button class="toggle-btn" onclick="toggleSidebar()" title="Collapse">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <rect x="3" y="3" width="18" height="18" rx="2" />
                    <path d="M9 3v18" />
                </svg>
            </button>
            <button class="expand-btn" onclick="toggleSidebar()" title="Expand">
                <img src="pictures/RiceWise_Logo.png" alt="RiceWise"
                    onerror="this.style.display='none';this.parentElement.textContent='🌾'">
            </button>
        </div>
        <nav class="nav-section">
            <div class="nav-label">Navigation</div>
            <a href="dashboard.php" class="nav-item" data-tooltip="Dashboard"><svg viewBox="0 0 24 24"
                    fill="currentColor">
                    <rect x="3" y="3" width="7" height="7" rx="1.5" />
                    <rect x="14" y="3" width="7" height="7" rx="1.5" />
                    <rect x="3" y="14" width="7" height="7" rx="1.5" />
                    <rect x="14" y="14" width="7" height="7" rx="1.5" />
                </svg><span class="nav-text">Dashboard</span></a>
            <a href="records.php" class="nav-item" data-tooltip="Records"><svg viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="18" height="18" rx="2" />
                    <line x1="3" y1="9" x2="21" y2="9" />
                    <line x1="9" y1="21" x2="9" y2="9" />
                </svg><span class="nav-text">Records</span></a>
            <a href="notifications.php" class="nav-item" data-tooltip="Notifications"><svg viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                    <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                </svg><span class="nav-text">Notifications</span></a>
            <div class="nav-label">Insights</div>
            <a href="reports.php" class="nav-item" data-tooltip="Reports"><svg viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                    <polyline points="14 2 14 8 20 8" />
                    <line x1="16" y1="13" x2="8" y2="13" />
                    <line x1="16" y1="17" x2="8" y2="17" />
                </svg><span class="nav-text">Reports</span></a>
            <a href="calendar.php" class="nav-item" data-tooltip="Calendar"><svg viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2">
                    <rect x="3" y="4" width="18" height="18" rx="2" />
                    <line x1="16" y1="2" x2="16" y2="6" />
                    <line x1="8" y1="2" x2="8" y2="6" />
                    <line x1="3" y1="10" x2="21" y2="10" />
                </svg><span class="nav-text">Calendar</span></a>
            <a href="process.php" class="nav-item active" data-tooltip="Planting Process"><svg viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 22V12" />
                    <path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8" />
                    <path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8" />
                </svg><span class="nav-text">Planting Process</span></a>
        </nav>
        <div class="sidebar-bottom">
            <a href="logout.php" class="signout-btn">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                    <polyline points="16 17 21 12 16 7" />
                    <line x1="21" y1="12" x2="9" y2="12" />
                </svg>
                <span class="signout-text">Sign out</span>
            </a>
        </div>
    </aside>

    <div class="main-content">
        <header class="header">
            <div class="header-left">
                <button class="hamburger-btn" onclick="openSidebar()" aria-label="Open menu">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                        stroke-linecap="round">
                        <line x1="3" y1="6" x2="21" y2="6" />
                        <line x1="3" y1="12" x2="21" y2="12" />
                        <line x1="3" y1="18" x2="21" y2="18" />
                    </svg>
                </button>
                Process History
            </div>
            <div class="header-right">
                <button class="notif-btn" id="notifBtn" aria-label="Notifications">
                    <svg width="21" height="21" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
                        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                    </svg>
                </button>
                <div class="header-sep"></div>
                <div class="profile-pill" id="profileBtn">
                    <span class="profile-pill-name" id="headerName"><?= htmlspecialchars($user['name']) ?></span>
                    <div class="profile-avatar"><svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg></div>
                    <svg class="down-arrow" viewBox="0 0 24 24" fill="none" stroke-width="2">
                        <polyline points="6 9 12 15 18 9" />
                    </svg>
                </div>
            </div>
        </header>

        <div class="notif-drop" id="notifDrop">
            <div class="nd-head"><span class="nd-title">Notifications</span><button class="mark-read-btn"
                    onclick="markAllRead()">Mark all read</button></div>
            <div class="nd-list" id="ndList"></div>
            <div class="nd-foot"><a href="notifications.php">View all &rarr;</a></div>
        </div>
        <div class="profile-drop" id="profileDrop">
            <h3 id="profileName"><?= htmlspecialchars($user['name']) ?></h3>
            <p id="profileEmail"><?= htmlspecialchars($user['email']) ?></p>
            <hr>
            <a href="profile.php" class="pd-btn">My Profile</a>
            <a href="logout.php" class="pd-btn">Sign out</a>
        </div>

        <div class="history-scroll">
            <div class="history-head">
                <div class="history-title">All Planting Cycles</div>
                <a href="process.php" class="history-back-link">← Back to Planting Process</a>
            </div>

            <?php if (!$cycles): ?>
                <div class="no-cycles">
                    <div class="no-cycles-icon">🌾</div>
                    <div>No planting cycles yet. Start one from the Planting Process page.</div>
                </div>
                <?php else: foreach ($cycles as $c):
                    $isActive = $c['status'] === 'active';
                    $start = new DateTime($c['start_date']);
                    $stepsDone = (int)$c['steps_done'];
                    if ($isActive) {
                        $today = new DateTime();
                        $daysIn = $today->diff($start)->days;
                        $meta = 'Started ' . $start->format('M j, Y') . " · Day $daysIn";
                    } else {
                        $completedAt = $c['completed_at'] ? new DateTime($c['completed_at']) : null;
                        $duration = $completedAt ? $start->diff($completedAt)->days : null;
                        $meta = 'Started ' . $start->format('M j, Y');
                        if ($completedAt) $meta .= ' · Completed ' . $completedAt->format('M j, Y') . ($duration !== null ? " · {$duration} days" : '');
                    }
                ?>
                    <div class="cycle-card" onclick="window.location.href='process.php?cycle=<?= (int)$c['id'] ?>'">
                        <div class="cycle-card-icon <?= $isActive ? 'active' : 'completed' ?>"><?= $isActive ? '🌱' : '✅' ?></div>
                        <div class="cycle-card-body">
                            <div class="cycle-card-title"><?= htmlspecialchars($c['field_location']) ?><?= $c['crop_type'] ? ' · ' . htmlspecialchars($c['crop_type']) : '' ?></div>
                            <div class="cycle-card-meta"><?= htmlspecialchars($meta) ?></div>
                        </div>
                        <div class="cycle-card-progress"><?= $stepsDone ?>/9 steps</div>
                        <div class="cycle-card-badge <?= $isActive ? 'active' : 'completed' ?>"><?= $isActive ? 'Active' : 'Completed' ?></div>
                    </div>
            <?php endforeach;
            endif; ?>
        </div>
    </div>

    <script>
        const isMobile = () => window.innerWidth <= 768;
        (function() {
            if (!isMobile() && localStorage.getItem('sidebarCollapsed') === '1') document.body.classList.add('sidebar-collapsed');
            document.documentElement.classList.remove('sidebar-pre-collapse');
        })();

        function toggleSidebar() {
            if (isMobile()) openSidebar();
            else {
                document.body.classList.toggle('sidebar-collapsed');
                localStorage.setItem('sidebarCollapsed', document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
            }
        }

        function openSidebar() {
            document.getElementById('sidebar').classList.add('open');
            document.getElementById('sidebarOverlay').classList.add('show');
            document.body.classList.add('nav-open');
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('show');
            document.body.classList.remove('nav-open');
        }
        document.querySelectorAll('.nav-item').forEach(el => el.addEventListener('click', () => {
            if (isMobile()) closeSidebar();
        }));
        window.addEventListener('resize', () => {
            if (!isMobile()) closeSidebar();
        });
        document.addEventListener('keydown', e => {
            if (e.key === '[' && !e.target.closest('input,textarea,select')) toggleSidebar();
        });

        /* ===== BELL DROPDOWN — live data, same pattern as every other page ===== */
        let _bellNotifs = [];
        let _bellLoaded = false;

        function bellNotifIcon(type) {
            const icons = {
                plant: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22V12"/><path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8"/><path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8"/></svg>',
                weather: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 17.58A5 5 0 0 0 18 7h-1.26A8 8 0 1 0 4 16.25"/></svg>',
                reminder: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="8"/><polyline points="12 6 12 12 16 14"/></svg>',
                harvest: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 22 16 8"/><path d="M3.47 12.53 5 11l1.53 1.53a3.5 3.5 0 0 1 0 4.94L5 19l-1.53-1.53a3.5 3.5 0 0 1 0-4.94z"/></svg>',
                pest: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="M8.5 8.5 16 16"/></svg>',
                water: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>',
            };
            return icons[type] || '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="8"/><polyline points="12 6 12 12 16 14"/></svg>';
        }

        function bellEscapeHtml(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c]));
        }

        function renderBellNotifs() {
            const list = document.getElementById('ndList');
            if (!list) return;
            if (!_bellNotifs.length) {
                list.innerHTML = '<div style="padding:24px 16px;text-align:center;font-size:12.5px;color:var(--text-muted);">No notifications yet.</div>';
                return;
            }
            list.innerHTML = _bellNotifs.map(n => `
                <div class="nd-item ${n.status}${parseInt(n.starred, 10) ? ' starred' : ''}">
                    <div class="nd-icon">${bellNotifIcon(n.type)}</div>
                    <div>
                        <div class="nd-item-title">${parseInt(n.starred, 10) ? '⭐ ' : ''}${bellEscapeHtml(n.title)}</div>
                        <div class="nd-item-desc">${bellEscapeHtml(n.description || '')}</div>
                        <div class="nd-item-date">${n.notif_date || ''}</div>
                    </div>
                </div>`).join('');
        }

        function fetchBellNotifs() {
            return fetch('notif_list.php').then(res => res.json()).then(json => {
                if (json.success) {
                    _bellNotifs = json.notifications;
                    _bellLoaded = true;
                    renderBellNotifs();
                }
            }).catch(() => {
                const list = document.getElementById('ndList');
                if (list && !_bellLoaded) list.innerHTML = '<div style="padding:24px 16px;text-align:center;font-size:12.5px;color:var(--text-muted);">Couldn\'t load notifications.</div>';
            });
        }

        function markAllRead() {
            fetch('notif_mark_all_read.php', {
                method: 'POST'
            }).then(res => res.json()).then(json => {
                if (json.success) {
                    _bellNotifs.forEach(n => n.status = 'read');
                    renderBellNotifs();
                }
            }).catch(() => {});
        }
        const notifBtn = document.getElementById('notifBtn'),
            notifDrop = document.getElementById('notifDrop');
        const profileBtn = document.getElementById('profileBtn'),
            profileDrop = document.getElementById('profileDrop');
        notifBtn.addEventListener('click', e => {
            e.stopPropagation();
            const o = notifDrop.classList.toggle('show');
            notifBtn.classList.toggle('active', o);
            if (o) fetchBellNotifs();
            profileDrop.classList.remove('show');
            profileBtn.classList.remove('open');
        });
        profileBtn.addEventListener('click', e => {
            e.stopPropagation();
            const o = profileDrop.classList.toggle('show');
            profileBtn.classList.toggle('open', o);
            notifDrop.classList.remove('show');
            notifBtn.classList.remove('active');
        });
        document.addEventListener('click', () => {
            notifDrop.classList.remove('show');
            notifBtn.classList.remove('active');
            profileDrop.classList.remove('show');
            profileBtn.classList.remove('open');
        });
    </script>
</body>

</html>