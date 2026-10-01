<?php
require_once 'config.php';
if (!isset($_SESSION['rw_user_id'])) {
    header('Location: index.php');
    exit;
}
if ($_SESSION['rw_user_role'] === 'admin') {
    header('Location: admin_dashboard.php');
    exit;
}

$uid = (int)$_SESSION['rw_user_id'];

// Fetch user (for header name/email)
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

// Fetch all notifications for this user
$nf = $conn->prepare(
    "SELECT id, title, description, status, starred, type, notif_date, created_at
     FROM notifications WHERE user_id=? ORDER BY created_at DESC"
);
$nf->bind_param('i', $uid);
$nf->execute();
$notifications = $nf->get_result()->fetch_all(MYSQLI_ASSOC);
$nf->close();

$jsNotifications = json_encode($notifications);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RiceWise - Notifications</title>
	<link rel="icon" type="image/png" href="pictures/RiceWise_Logo.png">
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
        html.sidebar-pre-collapse body {
            overflow: hidden;
        }
        html.sidebar-pre-collapse .sidebar {
            width: 68px !important;
            transition: none !important
        }
        html.sidebar-pre-collapse .logo-wrap,
        html.sidebar-pre-collapse .nav-label,
        html.sidebar-pre-collapse .nav-text,
        html.sidebar-pre-collapse .signout-text {
            display: none !important
        }
        html.sidebar-pre-collapse .sidebar-top {
            padding: 16px 0 !important;
            justify-content: center !important
        }
        html.sidebar-pre-collapse .nav-section {
            padding: 10px 0 !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important
        }
        html.sidebar-pre-collapse .nav-item {
            width: 44px !important;
            height: 44px !important;
            justify-content: center !important;
            padding: 0 !important
        }
        html.sidebar-pre-collapse .sidebar-bottom {
            padding: 10px 0 16px !important;
            display: flex !important;
            justify-content: center !important
        }
        html.sidebar-pre-collapse .signout-btn {
            width: 44px !important;
            height: 44px !important;
            justify-content: center !important;
            padding: 0 !important
        }
    </style>
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
            --red: #c0392b;
            --red-pale: #fdecea;
            --amber: #d68910;
            --amber-pale: #fef9ec;
            --star: #e6a817;
            --star-pale: #fef8e7;
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
            --gap: 16px;
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
            background: var(--cream);
            color: var(--text-primary);
            font-size: 15px;
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* ===== SIDEBAR OVERLAY ===== */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .38);
            z-index: 299;
            backdrop-filter: blur(3px);
        }

        .sidebar-overlay.show {
            display: block;
        }

        /* ===== SIDEBAR ===== */
        .sidebar {
            width: var(--sidebar-w);
            background: var(--white);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            z-index: 300;
            overflow: hidden;
            transition: width .3s cubic-bezier(.4, 0, .2, 1), transform .3s cubic-bezier(.4, 0, .2, 1);
        }

        body.sidebar-collapsed .sidebar {
            width: 68px;
        }

        body.sidebar-collapsed .expand-btn {
            display: none;
        }

        body.sidebar-collapsed .sidebar-top {
            padding: 16px 0;
            justify-content: center;
        }

        body.sidebar-collapsed .nav-section {
            padding: 10px 0;
            align-items: center;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        body.sidebar-collapsed .nav-item {
            width: 44px !important;
            height: 44px !important;
            justify-content: center !important;
            padding: 0 !important;
            gap: 0 !important;   /* ADD — kills the invisible gap that skews the icon left */
        }

        body.sidebar-collapsed .nav-item.active::before {
            display: none;
        }

        body.sidebar-collapsed .nav-item svg {
            width: 20px;
            height: 20px;
        }

        body.sidebar-collapsed .sidebar-bottom {
            padding: 10px 0 16px;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        body.sidebar-collapsed .signout-btn {
            width: 44px !important;
            height: 44px !important;
            justify-content: center !important;
            padding: 0 !important;
            gap: 0 !important;   /* ADD */
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
            z-index: 9999;
        }

        body.sidebar-collapsed .nav-item:hover::after {
            opacity: 1;
        }

        
		.sidebar-top {
            height: var(--header-h);        /* ADD — locks to the same 64px as .header */
            padding: 0 16px 0 8px; /* CHANGED — was 18px 16px 14px, vertical centering now handled by height + flex */
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            flex-shrink: 0;
            transition: padding .3s cubic-bezier(.4,0,.2,1);
        }

        .logo-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            min-width: 0;
            flex: 1;
            text-decoration: none;
            overflow: hidden;
        }

        .logo-icon {
            width: 48px;       /* CHANGED — was 36px */
            height: 48px;       /* CHANGED — was 36px */
            flex-shrink: 0;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            transition: transform .2s, box-shadow .2s;
            overflow: hidden
        }

        .logo-icon img {
            width: 34px;       /* CHANGED — was 22px */
            height: 34px;       /* CHANGED — was 22px */
            object-fit: contain
        }
            
        .logo-text {
            font-family: 'Lora', serif;
            font-size: 19px;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -.4px;
            white-space: nowrap;
            overflow: hidden;
            flex-shrink: 0;
            transition: none;
        }

        .toggle-btn {
            width: 30px;
            height: 30px;
            flex-shrink: 0;
            background: none;
            border: none;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background .2s;
            margin-left: auto;
        }
        .toggle-btn:hover {
            background: var(--sage-pale);
        }
        .toggle-btn svg {
            width: 18px;
            height: 18px;
            stroke: var(--text-muted);
        }
            
        .logo-wrap,
        .nav-text,
        .signout-text {
            opacity: 1;
            transform: translateX(0);
            max-width: 300px;
            overflow: hidden;
            transition: opacity .22s ease, transform .22s ease, max-width .22s ease;
        }

        .nav-label {
            opacity: 1;
            transform: translateX(0);
            max-height: 20px;
            transition: opacity .22s ease, transform .22s ease, max-height .22s ease, margin .22s ease;
        }

        body.sidebar-collapsed .logo-wrap,
        body.sidebar-collapsed .nav-label,
        body.sidebar-collapsed .nav-text,
        body.sidebar-collapsed .signout-text {
            opacity: 0;
            transform: translateX(10px);
            max-width: 0;
            pointer-events: none;
            transition: opacity .18s ease, transform .18s ease, max-width .18s ease;
        }

        body.sidebar-collapsed .nav-label {
            margin-top: 0;
            margin-bottom: 0;
            max-height: 0;
            transition: opacity .18s ease, transform .18s ease, max-width .18s ease, margin .18s ease, max-height .18s ease;
        }

        body.sidebar-collapsed .toggle-btn {
            margin-left: 0;
        }

        .nav-item svg .icon-filled {
            display: none;
            
        }
        .nav-item.active svg .icon-outline {
            display: none;
        }
        .nav-item.active svg .icon-filled {
            display: block;
        }

        .expand-btn {
            display: none;
            width: 48px;
            height: 48px;
            flex-shrink: 0;
            background: none;
            border: none;
            border-radius: 12px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: none;
            transition: transform .2s;
            overflow: hidden
        }

        .expand-btn:hover {
            transform: scale(1.08)
        }

        .expand-btn img {
            width: 34px;
            height: 34px;
            object-fit: contain;
            display: block
        }

        .nav-section {
            padding: 8px 12px;
            flex: 1;
            overflow: hidden;
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
            overflow: hidden;
        }

        .nav-label:first-child {
            margin-top: 6px;
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
            overflow: hidden;
        }

        .nav-item:hover {
            background: #fcfcfb;
            box-shadow: inset 0 0 0 1px var(--sage-pale);
            color: var(--sage-dark);
            font-weight: 600;
        }

        .nav-item.active {
            background: var(--sage-pale);
            color: var(--sage-dark);
            font-weight: 600;
        }
            
        .nav-item.active:hover {
            background: var(--sage-pale);
            box-shadow: inset 0 0 0 1px var(--sage-light);
            color: var(--sage-dark);
        }

        .nav-item svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        .sidebar-bottom {
            padding: 10px 12px 16px;
            border-top: 1px solid var(--border);
            flex-shrink: 0;
            display: flex;
            transition: padding .3s cubic-bezier(.4,0,.2,1);
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
            justify-content: flex-start;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all .18s;
            font-family: inherit;
            white-space: nowrap;
            overflow: hidden;
        }

        .signout-btn:hover {
            background: #fcfcfb;
            box-shadow: inset 0 0 0 1px var(--sage-pale);
            color: var(--sage-dark);
            font-weight: 600;
        }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            height: 100vh;
        }

        /* ===== HEADER ===== */
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
            gap: 14px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: 'Lora', serif;
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .hamburger-btn {
            display: none;
            width: 38px;
            height: 38px;
            background: none;
            border: none;
            border-radius: var(--radius-sm);
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            transition: background .18s;
        }

        .hamburger-btn:hover {
            background: var(--sage-pale);
        }

        .hamburger-btn svg {
            width: 18px;
            height: 18px;
            stroke: var(--text-secondary);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 6px;
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
            transition: background .2s;
        }

        .notif-btn:hover {
            background: var(--sage-pale);
        }

        .notif-btn svg {
            stroke: var(--text-secondary);
            fill: none;
        }

        .notif-btn.active svg {
            fill: var(--sage);
            stroke: var(--sage);
        }

        .header-sep {
            width: 1px;
            height: 24px;
            background: var(--border);
            margin: 0 4px;
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
            transition: border-color .2s;
        }

        .profile-pill:hover {
            border-color: var(--sage-light);
        }

        .profile-pill-name {
            font-size: 14px;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .profile-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--sage-pale);
            border: 1.5px solid var(--sage-light);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .profile-avatar svg {
            width: 15px;
            height: 15px;
            stroke: var(--sage);
        }

        .down-arrow {
            width: 12px;
            height: 12px;
            stroke: var(--text-muted);
            transition: transform .3s;
        }

        .profile-pill.open .down-arrow {
            transform: rotate(180deg);
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
            max-height: 320px;
            overflow-y: auto
        }

        .nd-list::-webkit-scrollbar {
            width: 4px
        }
        .nd-list::-webkit-scrollbar-thumb {
            background: var(--cream-3);
            border-radius: 2px
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
            color: var(--text-secondary)
        }

        .nd-item-date {
            font-size: 10.5px;
            color: var(--text-muted);
            font-family: 'DM Mono', monospace;
            margin-top: 1px
        }

        /* ===== PROFILE DROPDOWN ===== */
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
            display: none;
        }

        .profile-drop.show {
            display: block;
        }

        .profile-drop h3 {
            font-family: 'Lora', serif;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 2px;
        }

        .profile-drop p {
            font-size: 11.5px;
            color: var(--text-muted);
        }

        .profile-drop hr {
            border: none;
            border-top: 1px solid var(--border-light);
            margin: 8px 0;
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
            font-family: inherit;
        }

        .pd-btn:hover {
            background: var(--sage-pale);
            color: var(--sage-dark);
        }

        /* ===== PAGE SCROLL ===== */
        .page-scroll {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 20px 24px 28px;
            display: flex;
            flex-direction: column;
            gap: var(--gap);
        }

        .page-scroll::-webkit-scrollbar {
            width: 4px;
        }

        .page-scroll::-webkit-scrollbar-thumb {
            background: var(--cream-3);
            border-radius: 2px;
        }

        /* ===== NOTIFICATIONS PAGE ===== */
        .notif-page-header {
            display: flex;
            justify-content: space-between;   /* CHANGED — back to space-between since search is now on the left */
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .notif-page-title {
            font-family: 'Lora', serif;
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .notif-page-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .notif-action-btn {
            padding: 8px 14px;
            border: 1px solid var(--border);
            background: var(--white);
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--text-secondary);
            transition: all .2s;
            font-family: 'DM Sans', sans-serif;
        }

        .notif-action-btn:hover {
            border-color: var(--sage-light);
            color: var(--sage-dark);
            background: var(--sage-pale);
        }

        .notif-action-btn:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .notif-action-btn.danger:hover {
            border-color: #e8b4b0;
            color: var(--red);
            background: var(--red-pale);
        }

        .notif-action-btn svg {
            width: 14px;
            height: 14px;
        }

        .notif-controls {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: space-between;   /* ADD — pushes filter tabs away from select-all */
        }

        .notif-search-wrap {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .notif-search-wrap svg {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 15px;
            height: 15px;
            stroke: var(--text-muted);
            pointer-events: none;
        }

        .notif-search {
            width: 100%;
            padding: 9px 14px 9px 38px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--white);
            font-size: 13.5px;
            color: var(--text-primary);
            font-family: 'DM Sans', sans-serif;
            transition: border .2s;
        }

        .notif-search:focus {
            outline: none;
            border-color: var(--sage-light);
        }

        .notif-search::placeholder {
            color: var(--text-muted);
        }

        .filter-tabs {
            display: flex;
            gap: 4px;
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            padding: 4px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 7px 14px;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            color: var(--text-muted);
            background: transparent;
            transition: all .2s;
            font-family: 'DM Sans', sans-serif;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .filter-tab:hover {
            color: var(--text-secondary);
            background: var(--cream);
        }

        .filter-tab.active {
            background: var(--sage-pale);
            color: var(--sage-dark);
            font-weight: 600;
        }

        .filter-tab .tab-count {
            font-size: 11px;
            background: var(--cream-3);
            color: var(--text-muted);
            padding: 1px 6px;
            border-radius: 10px;
            font-family: 'DM Mono', monospace;
        }

        .filter-tab.active .tab-count {
            background: var(--sage);
            color: var(--white);
        }

        .notif-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .notif-card {
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 16px 18px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
            transition: all .2s;
            position: relative;
            animation: cardIn .25s ease both;
        }

        @keyframes cardIn {
            from {
                opacity: 0;
                transform: translateY(8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .notif-card:hover {
            box-shadow: var(--shadow-sm);
            border-color: var(--border);
        }

        .notif-card.unread {
            border-left: 3px solid var(--sage);
            background: linear-gradient(to right, #f5f9f3, var(--white) 40%);
        }

        .notif-card.starred {
            border-left: 3px solid var(--star);
        }

        .notif-card.unread.starred {
            border-left: 3px solid var(--star);
        }

        .notif-type-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .notif-type-icon svg {
            width: 18px;
            height: 18px;
        }

        .icon-plant {
            background: #e8f5e2;
        }

        .icon-plant svg {
            stroke: #3d7a32;
        }

        .icon-weather {
            background: #e3eef9;
        }

        .icon-weather svg {
            stroke: #2471a3;
        }

        .icon-reminder {
            background: var(--amber-pale);
        }

        .icon-reminder svg {
            stroke: var(--amber);
        }

        .icon-harvest {
            background: #fdf0e0;
        }

        .icon-harvest svg {
            stroke: #c47d1a;
        }

        .icon-pest {
            background: var(--red-pale);
        }

        .icon-pest svg {
            stroke: var(--red);
        }

        .icon-water {
            background: #e8f5fd;
        }

        .icon-water svg {
            stroke: #1a8ab5;
        }

        .icon-default {
            background: var(--cream-2);
        }

        .icon-default svg {
            stroke: var(--text-muted);
        }

        .notif-card-body {
            flex: 1;
            min-width: 0;
        }

        .notif-card-top {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
            flex-wrap: wrap;
        }

        .notif-card-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .notif-card-title.unread-title {
            color: var(--sage-dark);
        }

        .notif-badge {
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 10px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .badge-unread {
            background: var(--sage-pale);
            color: var(--sage-dark);
        }

        .badge-read {
            background: var(--cream-2);
            color: var(--text-muted);
        }

        .badge-type {
            font-size: 10.5px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            letter-spacing: .3px;
        }

        .badge-weather {
            background: #e3eef9;
            color: #1a6fa3;
        }

        .badge-plant {
            background: #e8f5e2;
            color: #2d6e24;
        }

        .badge-reminder {
            background: var(--amber-pale);
            color: var(--amber);
        }

        .badge-harvest {
            background: #fdf0e0;
            color: #b86e10;
        }

        .badge-pest {
            background: var(--red-pale);
            color: var(--red);
        }

        .badge-water {
            background: #e8f5fd;
            color: #1580a8;
        }

        .badge-system {
            background: var(--cream-2);
            color: var(--text-secondary);
        }

        .notif-card-msg {
            font-size: 13.5px;
            color: var(--text-secondary);
            line-height: 1.55;
            margin-bottom: 8px;
        }

        .notif-card-msg.collapsed {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }

        .notif-card-footer {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .notif-card-date {
            font-size: 11.5px;
            color: var(--text-muted);
            font-family: 'DM Mono', monospace;
        }

        .expand-msg-btn {
            font-size: 12px;
            color: var(--sage);
            background: none;
            border: none;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            font-weight: 600;
            padding: 0;
            transition: color .2s;
        }

        .expand-msg-btn:hover {
            color: var(--sage-dark);
        }

        .notif-card-actions {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex-shrink: 0;
        }

        .card-action-btn {
            width: 30px;
            height: 30px;
            border: none;
            border-radius: 8px;
            background: var(--cream);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all .2s;
            color: var(--text-muted);
        }

        .card-action-btn svg {
            width: 14px;
            height: 14px;
        }

        .card-action-btn:hover {
            background: var(--cream-3);
            color: var(--text-primary);
        }

        .card-action-btn.star-btn:hover,
        .card-action-btn.star-btn.starred-active {
            background: var(--star-pale);
            color: var(--star);
        }

        .card-action-btn.star-btn.starred-active svg {
            fill: var(--star);
            stroke: var(--star);
        }

        .card-action-btn.delete-btn:hover {
            background: var(--red-pale);
            color: var(--red);
        }

        .card-action-btn.read-btn:hover {
            background: var(--sage-pale);
            color: var(--sage);
        }

        .unread-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--sage);
            flex-shrink: 0;
            margin-top: 2px;
        }

        .notif-section-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .8px;
            text-transform: uppercase;
            color: var(--text-muted);
            padding: 4px 2px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .notif-section-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border-light);
        }

        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 14px;
            color: var(--text-muted);
            text-align: center;
            padding: 60px 40px;
        }

        .empty-state-icon {
            font-size: 52px;
            opacity: .4;
        }

        .empty-state-title {
            font-family: 'Lora', serif;
            font-size: 18px;
            color: var(--text-secondary);
            font-weight: 600;
        }

        .empty-state-text {
            font-size: 13.5px;
            max-width: 280px;
            line-height: 1.6;
        }

        .toast {
            position: fixed;
            bottom: 28px;
            left: 50%;
            transform: translateX(-50%) translateY(20px);
            background: var(--text-primary);
            color: var(--white);
            padding: 12px 20px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 500;
            opacity: 0;
            pointer-events: none;
            z-index: 9999;
            transition: all .3s;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
            pointer-events: auto;
        }

        .toast-undo {
            background: none;
            border: none;
            color: var(--sage-light);
            font-weight: 700;
            cursor: pointer;
            font-size: 13px;
            font-family: 'DM Sans', sans-serif;
        }

        .select-all-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 6px 2px;
            user-select: none;
        }

        .selected-count {
            font-size: 13px;
            color: var(--sage-dark);
            font-weight: 600;
            visibility: hidden;   /* CHANGED — was display:none */
            min-width: 80px;      /* ADD — reserves consistent space */
            text-align: right;
        }

        .selected-count.show {
            visibility: visible;  /* CHANGED — was display:block */
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {

            html,
            body {
                height: auto !important;
                min-height: 100%;
                overflow: auto !important;
            }

            body {
                display: block !important;
                overflow-x: hidden !important;
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
                transition: transform .3s cubic-bezier(.4, 0, .2, 1) !important;
            }

            .sidebar.open {
                transform: translateX(0) !important;
            }

            body.sidebar-collapsed .sidebar {
                width: var(--sidebar-w) !important;
            }

            body.sidebar-collapsed .logo-wrap {
                display: flex !important;
            }

            body.sidebar-collapsed .logo-text,
            body.sidebar-collapsed .nav-label,
            body.sidebar-collapsed .nav-text,
            body.sidebar-collapsed .signout-text {
                display: inline !important;
            }

            body.sidebar-collapsed .toggle-btn {
                display: flex !important;
            }

            body.sidebar-collapsed .expand-btn {
                display: none !important;
            }

            body.sidebar-collapsed .sidebar-top {
                padding: 18px 16px 14px !important;
                justify-content: flex-start !important;
            }

            body.sidebar-collapsed .nav-section {
                padding: 8px 12px !important;
                align-items: stretch !important;
                display: flex !important;
                flex-direction: column !important;
                gap: 0 !important;
            }

            body.sidebar-collapsed .nav-item {
                width: auto !important;
                height: auto !important;
                gap: 0;
                justify-content: flex-start !important;
                padding: 10px !important;
            }

            body.sidebar-collapsed .nav-item.active::before {
                display: block !important;
            }

            body.sidebar-collapsed .nav-item::after {
                display: none !important;
            }

            body.sidebar-collapsed .sidebar-bottom {
                padding: 10px 12px 16px !important;
                display: flex !important;
            }

            body.sidebar-collapsed .signout-btn {
                width: 100% !important;
                height: auto !important;
                padding: 10px 13px !important;
                gap: 9px !important;
            }

            .main-content {
                display: block !important;
                width: 100% !important;
                height: auto !important;
                overflow: visible !important;
            }

            .header {
                padding: 0 16px;
            }

            .hamburger-btn {
                display: flex !important;
            }

            .profile-pill-name {
                display: none;
            }

            .page-scroll {
                overflow: visible !important;
                height: auto !important;
                padding: 14px 16px 24px;
            }

            .notif-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .notif-search-wrap {
                min-width: unset;
            }

            .filter-tabs {
                width: 100%;
                overflow-x: auto;
            }

            .notif-page-actions {
                width: 100%;
            }

            .notif-action-btn {
                font-size: 12px;
                padding: 7px 10px;
            }

            .profile-drop {
                right: 10px;
            }

            body.nav-open {
                overflow: hidden !important;
            }
        }

        @media (max-width: 480px) {
            .notif-card {
                padding: 12px 14px;
                gap: 10px;
            }

            .notif-type-icon {
                width: 34px;
                height: 34px;
            }

            .notif-card-actions {
                flex-direction: row;
            }
        }
    </style>
</head>

<body>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-top">
            <div class="logo-wrap" onclick="toggleSidebar()">
                <div class="logo-icon"><img src="pictures/RiceWise_Logo.png" alt="RiceWise" onerror="this.style.display='none';this.parentElement.textContent='🌾'"></div>
                <span class="logo-text">RiceWise</span>
            </div>
            <button class="toggle-btn" onclick="toggleSidebar()" title="Collapse">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <rect x="3" y="3" width="18" height="18" rx="2" />
                    <path d="M9 3v18" />
                </svg>
            </button>
            <button class="expand-btn" onclick="toggleSidebar()"><img src="pictures/RiceWise_Logo.png" alt="RiceWise" onerror="this.style.display='none';this.parentElement.textContent='🌾'"></button>
        </div>
        <nav class="nav-section">
            <div class="nav-label">Navigation</div>

            <a href="dashboard.php" class="nav-item" data-tooltip="Dashboard"><svg viewBox="0 0 24 24">
                    <g class="icon-outline" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" />
                    </g>
                    <g class="icon-filled" fill="currentColor">
                        <rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" />
                    </g>
                </svg><span class="nav-text">Dashboard</span></a>

            <a href="records.php" class="nav-item" data-tooltip="Records"><svg viewBox="0 0 24 24">
                    <g class="icon-outline" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" /><line x1="3" y1="9" x2="21" y2="9" /><line x1="9" y1="21" x2="9" y2="9" />
                    </g>
                    <g class="icon-filled">
                        <rect x="3" y="3" width="18" height="18" rx="3" fill="currentColor" /><rect x="7" y="7.2" width="10" height="1.8" rx="0.9" fill="var(--white)" /><rect x="7" y="11.2" width="10" height="1.8" rx="0.9" fill="var(--white)" /><rect x="7" y="15.2" width="6" height="1.8" rx="0.9" fill="var(--white)" />
                    </g>
                </svg><span class="nav-text">Records</span></a>

            <a href="calendar.php" class="nav-item" data-tooltip="Calendar"><svg viewBox="0 0 24 24">
                    <g class="icon-outline" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" /><line x1="16" y1="2" x2="16" y2="6" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="3" y1="10" x2="21" y2="10" />
                    </g>
                    <g class="icon-filled" fill="currentColor">
                        <rect x="3" y="4" width="18" height="18" rx="3" /><rect x="7" y="2" width="2" height="5" rx="1" /><rect x="15" y="2" width="2" height="5" rx="1" /><rect x="3" y="9.3" width="18" height="1.6" fill="var(--white)" />
                    </g>
                </svg><span class="nav-text">Calendar</span></a>

            <a href="process.php" class="nav-item" data-tooltip="Planting Process"><svg viewBox="0 0 24 24">
                    <g class="icon-outline" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 22V12" /><path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8" /><path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8" />
                    </g>
                    <g class="icon-filled" fill="currentColor">
                        <path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8 3 0 8-2 8-8 0 0-5 0-8 5z" /><rect x="11" y="12" width="2" height="10" rx="1" />
                    </g>
                </svg><span class="nav-text">Planting Process</span></a>

            <div class="nav-label">Insights</div>

            <a href="reports.php" class="nav-item" data-tooltip="Reports"><svg viewBox="0 0 24 24">
                    <g class="icon-outline" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="16" y1="13" x2="8" y2="13" /><line x1="16" y1="17" x2="8" y2="17" />
                    </g>
                    <g class="icon-filled">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="currentColor" /><path d="M14 2v6h6" fill="var(--sage-pale)" /><rect x="8" y="12.2" width="8" height="1.6" rx="0.8" fill="var(--white)" /><rect x="8" y="16.2" width="8" height="1.6" rx="0.8" fill="var(--white)" />
                    </g>
                </svg><span class="nav-text">Reports</span></a>

            <div class="nav-label">Account</div>

            <a href="notifications.php" class="nav-item active" data-tooltip="Notifications"><svg viewBox="0 0 24 24">
                    <g class="icon-outline" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M13.73 21a2 2 0 0 1-3.46 0" />
                    </g>
                    <g class="icon-filled">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9z" fill="currentColor" />
                        <path d="M13.73 21a2 2 0 0 1-3.46 0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                    </g>
                </svg><span class="nav-text">Notifications</span></a>
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
                <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                	<rect x="3" y="3" width="18" height="18" rx="2" />
                	<path d="M9 3v18" />
            	</svg></button>
                Notifications
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
                    onclick="markAllReadPage()">Mark all read</button></div>
            <div class="nd-list" id="ndList"></div>
        </div>

        <div class="profile-drop" id="profileDrop">
            <h3 id="profileName"><?= htmlspecialchars($user['name']) ?></h3>
            <p id="profileEmail"><?= htmlspecialchars($user['email']) ?></p>
            <hr>
            <a href="profile.php" class="pd-btn">My Profile</a>
            <a href="logout.php" class="pd-btn">Sign out</a>
        </div>

        <div class="page-scroll">
            <div class="notif-page-header">
                    <div class="notif-search-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.35-4.35" />
                        </svg>
                        <input type="text" class="notif-search" placeholder="Search notifications…" id="notifSearch">
                    </div>
                    <div class="notif-page-actions">
                        <button class="notif-action-btn" id="markAllBtn" onclick="markAllReadPage()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="20 6 9 17 4 12" />
                            </svg>
                            Mark all read
                        </button>
                        <button class="notif-action-btn danger" id="deleteSelectedBtn" onclick="deleteSelected()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="3 6 5 6 21 6" />
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                            </svg>
                            <span id="deleteSelectedLabel">Delete selected</span>
                        </button>
                    </div>
                </div>

                <div class="notif-controls">
                    <label class="select-all-wrap">
                        <input type="checkbox" id="selectAll"
                            style="width:16px;height:16px;cursor:pointer;accent-color:var(--sage);">
                        <span>Select all</span>
                    </label>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <span class="selected-count" id="selectedCount">0 selected</span>
                        <div class="filter-tabs" id="filterTabs">
                            <button class="filter-tab active" data-filter="all">All <span class="tab-count"
                                    id="count-all">0</span></button>
                            <button class="filter-tab" data-filter="unread">Unread <span class="tab-count"
                                    id="count-unread">0</span></button>
                            <button class="filter-tab" data-filter="read">Read <span class="tab-count"
                                    id="count-read">0</span></button>
                            <button class="filter-tab" data-filter="starred">
                            	<svg viewBox="0 0 24 24" fill="var(--star)" stroke="var(--star)" stroke-width="1.5" style="width:13px;height:13px;flex-shrink:0">
                                		<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
                            	</svg>
                            	Starred <span class="tab-count" id="count-starred">0</span>
                        	</button>
                        </div>
                    </div>
                </div>

            <div class="notif-list" id="notifList"></div>
        </div>
    </div>

    <div class="toast" id="toast">
        <span id="toastMsg">Notification deleted</span>
        <button class="toast-undo" id="toastUndo">Undo</button>
    </div>

    <script>
        /* ===== SIDEBAR ===== */
        const isMobile = () => window.innerWidth <= 768;
        (function() {
            if (!isMobile() && localStorage.getItem('sidebarCollapsed') === '1') document.body.classList.add('sidebar-collapsed');
            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    document.documentElement.classList.remove('sidebar-pre-collapse');
                });
            });
        })();

        function toggleSidebar() {
            if (isMobile()) {
                const sidebar = document.getElementById('sidebar');
                if (sidebar.classList.contains('open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            } else {
                document.body.classList.toggle('sidebar-collapsed');
                localStorage.setItem('sidebarCollapsed', document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
                if (typeof rebuildCharts === 'function') setTimeout(rebuildCharts, 340);
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
            if (e.key === '[' && !e.target.closest('input,textarea')) toggleSidebar();
        });

        /* ===== PROFILE DROPDOWN ===== */
        const profileBtn = document.getElementById('profileBtn'),
            profileDrop = document.getElementById('profileDrop');
        profileBtn.addEventListener('click', e => {
    		e.stopPropagation();
            const o = profileDrop.classList.toggle('show');
            profileBtn.classList.toggle('open', o);
            notifDrop.classList.remove('show');   // ADD
            notifBtn.classList.remove('active');  // ADD
        });
        document.addEventListener('click', () => {
            profileDrop.classList.remove('show');
            profileBtn.classList.remove('open');
            notifDrop.classList.remove('show');   // ADD
            notifBtn.classList.remove('active');  // ADD
        });
            
       	/* ===== BELL DROPDOWN — reuses page's own `notifications` array, no fetch needed ===== */
        const notifBtn = document.getElementById('notifBtn'),
            notifDrop = document.getElementById('notifDrop');

        const ND_STAR_SVG = '<svg viewBox="0 0 24 24" width="11" height="11" fill="var(--star,#e6a817)" stroke="var(--star,#e6a817)" stroke-width="1.5" style="vertical-align:-1px;margin-right:3px;flex-shrink:0"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';

        function renderBellDrop() {
            const list = document.getElementById('ndList');
            const recent = notifications.slice(0, 8);
            if (!recent.length) {
                list.innerHTML = `<div style="padding:24px 16px;text-align:center;font-size:12.5px;color:var(--text-muted);">No notifications yet.</div>`;
                return;
            }
            list.innerHTML = recent.map(n => `
                <div class="nd-item ${n.status}${n.starred ? ' starred' : ''}">
                    ${getIconHTML(n.type)}
                    <div>
                        <div class="nd-item-title">${n.starred ? ND_STAR_SVG : ''}${escapeHtml(n.title)}</div>
                        <div class="nd-item-desc">${escapeHtml(n.msg)}</div>
                        <div class="nd-item-date">${formatDisplayDate(n.date)}</div>
                    </div>
                </div>
            `).join('');
        }

        notifBtn.addEventListener('click', e => {
            e.stopPropagation();
            const o = notifDrop.classList.toggle('show');
            notifBtn.classList.toggle('active', o);
            if (o) renderBellDrop();
            profileDrop.classList.remove('show');
            profileBtn.classList.remove('open');
        });
            
        /* ===== SERVER DATA ===== */
        const PHP_NOTIFICATIONS = <?= $jsNotifications ?>;

        // Normalize DB rows into the shape the UI expects.
        // notif_date is the display date; created_at gives us a time-of-day and a stable sort fallback.
        let notifications = PHP_NOTIFICATIONS.map(n => ({
            id: n.id,
            type: n.type || 'system',
            title: n.title,
            msg: n.description || '',
            date: n.notif_date || (n.created_at ? n.created_at.slice(0, 10) : ''),
            time: n.created_at ? new Date(n.created_at.replace(' ', 'T')).toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            }) : '',
            status: n.status,
            starred: !!parseInt(n.starred, 10),
            created_at: n.created_at
        }));

        let currentFilter = 'all',
            searchTerm = '',
            lastDeleted = null,
            toastTimer = null;

        function getIconHTML(type) {
            const icons = {
                plant: `<div class="notif-type-icon icon-plant"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22V12"/><path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8"/><path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8"/></svg></div>`,
                weather: `<div class="notif-type-icon icon-weather"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 17.58A5 5 0 0 0 18 7h-1.26A8 8 0 1 0 4 16.25"/><line x1="8" y1="19" x2="8" y2="21"/><line x1="12" y1="19" x2="12" y2="21"/><line x1="16" y1="19" x2="16" y2="21"/></svg></div>`,
                reminder: `<div class="notif-type-icon icon-reminder"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="8"/><polyline points="12 6 12 12 16 14"/></svg></div>`,
                harvest: `<div class="notif-type-icon icon-harvest"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 22 16 8"/><path d="M3.47 12.53 5 11l1.53 1.53a3.5 3.5 0 0 1 0 4.94L5 19l-1.53-1.53a3.5 3.5 0 0 1 0-4.94z"/></svg></div>`,
                pest: `<div class="notif-type-icon icon-pest"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="M8.5 8.5 16 16"/></svg></div>`,
                water: `<div class="notif-type-icon icon-water"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg></div>`,
            };
            return icons[type] || `<div class="notif-type-icon icon-default"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18"/></svg></div>`;
        }

        function getBadgeHTML(type) {
            const map = {
                plant: 'badge-plant',
                weather: 'badge-weather',
                reminder: 'badge-reminder',
                harvest: 'badge-harvest',
                pest: 'badge-pest',
                water: 'badge-water'
            };
            const labels = {
                plant: 'Planting',
                weather: 'Weather',
                reminder: 'Reminder',
                harvest: 'Harvest',
                pest: 'Pest Control',
                water: 'Irrigation'
            };
            return `<span class="notif-badge badge-type ${map[type] || 'badge-system'}">${labels[type] || 'System'}</span>`;
        }

        function getFiltered() {
            return notifications.filter(n => {
                const matchFilter = currentFilter === 'all' || (currentFilter === 'unread' && n.status === 'unread') || (currentFilter === 'read' && n.status === 'read') || (currentFilter === 'starred' && n.starred);
                const term = searchTerm.toLowerCase();
                const matchSearch = !term || n.title.toLowerCase().includes(term) || n.msg.toLowerCase().includes(term);
                return matchFilter && matchSearch;
            });
        }

        function updateCounts() {
            document.getElementById('count-all').textContent = notifications.length;
            document.getElementById('count-unread').textContent = notifications.filter(n => n.status === 'unread').length;
            document.getElementById('count-read').textContent = notifications.filter(n => n.status === 'read').length;
            document.getElementById('count-starred').textContent = notifications.filter(n => n.starred).length;
        }

        function escapeHtml(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c]));
        }

        function renderList() {
            const list = document.getElementById('notifList');
            const filtered = getFiltered();
            updateCounts();
            if (filtered.length === 0) {
                list.innerHTML = `<div class="empty-state"><div class="empty-state-icon">🔔</div><div class="empty-state-title">No notifications here</div><div class="empty-state-text">Nothing to show for the current filter.</div></div>`;
                return;
            }
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const weekAgo = new Date(today);
            weekAgo.setDate(weekAgo.getDate() - 7);
            const groups = {
                today: [],
                week: [],
                older: []
            };
            filtered.forEach(n => {
                const d = n.date ? new Date(n.date + 'T00:00:00') : new Date(0);
                if (d.toDateString() === today.toDateString()) groups.today.push(n);
                else if (d >= weekAgo) groups.week.push(n);
                else groups.older.push(n);
            });
            let html = '';
            if (groups.today.length) html += renderGroup('Today', groups.today);
            if (groups.week.length) html += renderGroup('This Week', groups.week);
            if (groups.older.length) html += renderGroup('Older', groups.older);
            list.innerHTML = html;
            bindCardEvents();
            updateSelectedCount();
        }

        function formatDisplayDate(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr + 'T00:00:00');
            return d.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
        }

        function renderGroup(label, items) {
            let html = `<div class="notif-section-label">${label}</div>`;
            items.forEach(n => {
                const isUnread = n.status === 'unread',
                    isStarred = n.starred;
                const truncated = n.msg.length > 120;
                const shortMsg = truncated ? n.msg.slice(0, 120) + '…' : n.msg;
                const badgeCls = isUnread ? 'badge-unread' : 'badge-read';
                html += `
                <div class="notif-card ${isUnread ? 'unread' : ''} ${isStarred ? 'starred' : ''}" data-id="${n.id}">
                    <input type="checkbox" class="notif-card-check" data-id="${n.id}" style="width:16px;height:16px;margin-top:4px;cursor:pointer;accent-color:var(--sage);flex-shrink:0;">
                    ${getIconHTML(n.type)}
                    <div class="notif-card-body">
                        <div class="notif-card-top">
                            ${isUnread ? '<div class="unread-dot"></div>' : ''}
                            <span class="notif-card-title ${isUnread ? 'unread-title' : ''}">${escapeHtml(n.title)}</span>
                            ${getBadgeHTML(n.type)}
                            <span class="notif-badge ${badgeCls}">${isUnread ? 'Unread' : 'Read'}</span>
                        </div>
                        <p class="notif-card-msg collapsed" data-full="${encodeURIComponent(n.msg)}" data-short="${encodeURIComponent(shortMsg)}">${escapeHtml(shortMsg)}</p>
                        <div class="notif-card-footer">
                            <span class="notif-card-date">${formatDisplayDate(n.date)}${n.time ? ' · ' + n.time : ''}</span>
                            ${truncated ? `<button class="expand-msg-btn" data-id="${n.id}">Show more</button>` : ''}
                        </div>
                    </div>
                    <div class="notif-card-actions">
                        <button class="card-action-btn star-btn ${isStarred ? 'starred-active' : ''}" data-id="${n.id}" title="Star">
                            <svg viewBox="0 0 24 24" fill="${isStarred ? 'var(--star)' : 'none'}" stroke="${isStarred ? 'var(--star)' : 'currentColor'}" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        </button>
                        <button class="card-action-btn read-btn" data-id="${n.id}" title="${isUnread ? 'Mark read' : 'Mark unread'}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${isUnread ? '<polyline points="20 6 9 17 4 12"/>' : '<circle cx="12" cy="12" r="8"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'}</svg>
                        </button>
                        <button class="card-action-btn delete-btn" data-id="${n.id}" title="Delete">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                </div>`;
            });
            return html;
        }

        function bindCardEvents() {
            document.querySelectorAll('.star-btn').forEach(btn => btn.addEventListener('click', () => toggleStar(+btn.dataset.id)));
            document.querySelectorAll('.read-btn').forEach(btn => btn.addEventListener('click', () => toggleRead(+btn.dataset.id)));
            document.querySelectorAll('.delete-btn').forEach(btn => btn.addEventListener('click', () => deleteOne(+btn.dataset.id)));
            document.querySelectorAll('.expand-msg-btn').forEach(btn => btn.addEventListener('click', () => {
                const msgEl = btn.closest('.notif-card').querySelector('.notif-card-msg');
                const full = decodeURIComponent(msgEl.dataset.full),
                    short = decodeURIComponent(msgEl.dataset.short);
                if (msgEl.classList.contains('collapsed')) {
                    msgEl.textContent = full;
                    msgEl.classList.remove('collapsed');
                    btn.textContent = 'Show less';
                } else {
                    msgEl.textContent = short;
                    msgEl.classList.add('collapsed');
                    btn.textContent = 'Show more';
                }
            }));
            document.querySelectorAll('.notif-card-check').forEach(cb => cb.addEventListener('change', updateSelectedCount));
            document.querySelectorAll('.notif-card-body').forEach(body => body.addEventListener('click', e => {
                if (e.target.classList.contains('expand-msg-btn')) return;
                const id = +body.closest('.notif-card').dataset.id;
                const n = notifications.find(x => x.id === id);
                if (n && n.status === 'unread') markReadSilently(n);
            }));
        }

        /* ===== SERVER-BACKED ACTIONS ===== */

        function toggleStar(id) {
            const n = notifications.find(x => x.id === id);
            if (!n) return;
            const newVal = !n.starred;
            n.starred = newVal; // optimistic update
            renderList();
            fetch('notif_toggle.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    id,
                    field: 'starred',
                    value: newVal
                })
            }).then(res => res.json()).then(json => {
                if (!json.success) {
                    n.starred = !newVal;
                    renderList();
                    showToast(json.message || 'Failed to update.', false);
                }
            }).catch(() => {
                n.starred = !newVal;
                renderList();
                showToast('Network error — please try again.', false);
            });
        }

        function toggleRead(id) {
            const n = notifications.find(x => x.id === id);
            if (!n) return;
            const newStatus = n.status === 'unread' ? 'read' : 'unread';
            const oldStatus = n.status;
            n.status = newStatus; // optimistic update
            renderList();
            fetch('notif_toggle.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    id,
                    field: 'status',
                    value: newStatus
                })
            }).then(res => res.json()).then(json => {
                if (!json.success) {
                    n.status = oldStatus;
                    renderList();
                    showToast(json.message || 'Failed to update.', false);
                }
            }).catch(() => {
                n.status = oldStatus;
                renderList();
                showToast('Network error — please try again.', false);
            });
        }

        function markReadSilently(n) {
            n.status = 'read';
            renderList();
            fetch('notif_toggle.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    id: n.id,
                    field: 'status',
                    value: 'read'
                })
            }).catch(() => {});
        }

        function deleteOne(id) {
            const idx = notifications.findIndex(x => x.id === id);
            if (idx === -1) return;
            const removed = notifications[idx];
            notifications.splice(idx, 1);
            renderList();
            fetch('notif_delete.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    ids: [id]
                })
            }).then(res => res.json()).then(json => {
                if (!json.success) {
                    notifications.splice(idx, 0, removed);
                    renderList();
                    showToast(json.message || 'Failed to delete.', false);
                    return;
                }
                lastDeleted = {
                    items: [toRestorePayload(removed)]
                };
                showToast('Notification deleted', true);
            }).catch(() => {
                notifications.splice(idx, 0, removed);
                renderList();
                showToast('Network error — please try again.', false);
            });
        }

        function deleteSelected() {
            const checked = document.querySelectorAll('.notif-card-check:checked');
            if (checked.length === 0) {
                showToast('No notifications selected', false);
                return;
            }
            const ids = Array.from(checked).map(cb => +cb.dataset.id);
            const removedItems = notifications.filter(n => ids.includes(n.id));
            notifications = notifications.filter(n => !ids.includes(n.id));
            renderList();
            document.getElementById('selectAll').checked = false;
            fetch('notif_delete.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    ids
                })
            }).then(res => res.json()).then(json => {
                if (!json.success) {
                    notifications = [...notifications, ...removedItems];
                    renderList();
                    showToast(json.message || 'Failed to delete.', false);
                    return;
                }
                lastDeleted = {
                    items: removedItems.map(toRestorePayload)
                };
                showToast(`${ids.length} notification${ids.length > 1 ? 's' : ''} deleted`, true);
            }).catch(() => {
                notifications = [...notifications, ...removedItems];
                renderList();
                showToast('Network error — please try again.', false);
            });
        }

        function toRestorePayload(n) {
            return {
                title: n.title,
                description: n.msg,
                status: n.status,
                starred: n.starred,
                type: n.type,
                notif_date: n.date || null
            };
        }

        function markAllReadPage() {
            const btn = document.getElementById('markAllBtn');
            btn.disabled = true;
            const previousStatuses = notifications.map(n => ({
                id: n.id,
                status: n.status
            }));
            notifications.forEach(n => n.status = 'read');
            renderList();
            fetch('notif_mark_all_read.php', {
                method: 'POST'
            }).then(res => res.json()).then(json => {
                btn.disabled = false;
                if (!json.success) {
                    previousStatuses.forEach(p => {
                        const n = notifications.find(x => x.id === p.id);
                        if (n) n.status = p.status;
                    });
                    renderList();
                    showToast(json.message || 'Failed to update.', false);
                    return;
                }
                showToast('All notifications marked as read', false);
            }).catch(() => {
                btn.disabled = false;
                previousStatuses.forEach(p => {
                    const n = notifications.find(x => x.id === p.id);
                    if (n) n.status = p.status;
                });
                renderList();
                showToast('Network error — please try again.', false);
            });
        }

        function updateSelectedCount() {
            const checked = document.querySelectorAll('.notif-card-check:checked');
            const countEl = document.getElementById('selectedCount'),
                label = document.getElementById('deleteSelectedLabel');
            if (checked.length > 0) {
                countEl.textContent = `${checked.length} selected`;
                countEl.classList.add('show');
                label.textContent = `Delete (${checked.length})`;
            } else {
                countEl.classList.remove('show');
                label.textContent = 'Delete selected';
            }
        }

        document.getElementById('selectAll').addEventListener('change', function() {
            document.querySelectorAll('.notif-card-check').forEach(cb => {
                cb.checked = this.checked;
            });
            updateSelectedCount();
        });
        document.getElementById('filterTabs').addEventListener('click', e => {
            const tab = e.target.closest('.filter-tab');
            if (!tab) return;
            document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            currentFilter = tab.dataset.filter;
            document.getElementById('selectAll').checked = false;
            renderList();
        });
        document.getElementById('notifSearch').addEventListener('input', function() {
            searchTerm = this.value;
            renderList();
        });

        function showToast(msg, showUndo) {
            const toast = document.getElementById('toast'),
                msgEl = document.getElementById('toastMsg'),
                undoBtn = document.getElementById('toastUndo');
            msgEl.textContent = msg;
            undoBtn.style.display = showUndo ? 'inline' : 'none';
            toast.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => {
                toast.classList.remove('show');
                if (showUndo) lastDeleted = null;
            }, 5000);
        }

        document.getElementById('toastUndo').addEventListener('click', () => {
            if (!lastDeleted) return;
            const payload = lastDeleted;
            lastDeleted = null;
            document.getElementById('toast').classList.remove('show');
            fetch('notif_restore.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    items: payload.items
                })
            }).then(res => res.json()).then(json => {
                if (!json.success || !json.ids || !json.ids.length) {
                    showToast('Could not undo — please refresh.', false);
                    return;
                }
                // Re-fetch fresh copies of the restored rows isn't available without another endpoint,
                // so rebuild them locally using the new ids and the payload we already have.
                json.ids.forEach((newId, i) => {
                    const item = payload.items[i];
                    if (!item) return;
                    notifications.unshift({
                        id: newId,
                        type: item.type,
                        title: item.title,
                        msg: item.description,
                        date: item.notif_date,
                        time: '',
                        status: item.status,
                        starred: !!item.starred,
                        created_at: null
                    });
                });
                renderList();
                showToast('Restored.', false);
            }).catch(() => showToast('Could not undo — please refresh.', false));
        });

        renderList();
    </script>
</body>

</html>