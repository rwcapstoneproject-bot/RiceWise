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
$stmt = $conn->prepare("SELECT id, name, email, location, hectares, is_blocked, created_at FROM users WHERE id=? LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) {
    header('Location: logout.php');
    exit;
}
$_SESSION['rw_user_name'] = $user['name'];
// ── Real stats (was hf.nav-item:hover {ardcoded 24 / 2,540 kg in profile.html) ──
$cnt = $conn->prepare("SELECT COUNT(*) AS c FROM farm_records WHERE user_id=?");
$cnt->bind_param('i', $uid);
$cnt->execute();
$totalRecords = (int)$cnt->get_result()->fetch_assoc()['c'];
$cnt->close();
// "Last Harvest" = total kg across all fields on the most recent date that
// had a completed Harvesting record (Completed-only, same convention used
// in reports.php's aggregation).
$lh = $conn->prepare(
    "SELECT record_date, SUM(quantity) AS total_kg
     FROM farm_records
     WHERE user_id=? AND activity='Harvesting' AND status='Completed'
     GROUP BY record_date ORDER BY record_date DESC LIMIT 1"
);
$lh->bind_param('i', $uid);
$lh->execute();
$lastHarvest = $lh->get_result()->fetch_assoc();
$lh->close();
$memberSince = (new DateTime($user['created_at']))->format('M Y');
$accountStatus = ((int)$user['is_blocked'] === 1) ? 'Blocked' : 'Active';
$jsUser = json_encode([
    'name' => $user['name'],
    'email' => $user['email'],
    'location' => $user['location'] ?? '',
    'hectares' => isset($user['hectares']) && $user['hectares'] !== null ? (float)$user['hectares'] : null,
]);
$jsStats = json_encode([
    'totalRecords' => $totalRecords,
    'lastHarvestKg' => $lastHarvest ? round((float)$lastHarvest['total_kg']) : null,
    'lastHarvestDate' => $lastHarvest ? $lastHarvest['record_date'] : null,
    'memberSince' => $memberSince,
    'accountStatus' => $accountStatus,
]);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RiceWise - Profile</title>
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
            --gold-light: #f0d9b5;
            --gold-pale: #fdf6ec;
            --red: #c0392b;
            --red-pale: #fdecea;
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
            --radius-xs: 8px;
            --sidebar-w: 252px;
            --header-h: 64px;
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
            display: flex;
            height: 100vh;
            overflow: hidden;
            font-size: 15px;
        }

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
            gap: 0 !important;
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
            gap: 0 !important;
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
            height: var(--header-h);
            padding: 0 16px 0 8px;
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
            width: 48px;
            height: 48px;
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
            width: 34px;
            height: 34px;
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
            transform-origin: 12px 12px;
            transform: scale(1.15);
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
            font-weight: 600
        }

        .nav-item.active {
            background: var(--sage-pale);
            color: var(--sage-dark);
            font-weight: 600;
        }
            
        .nav-item.active:hover {
            background: var(--sage-pale);
            box-shadow: inset 0 0 0 1px var(--sage-light);
            color: var(--sage-dark)
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
            font-weight: 600
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
        .main-content {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            height: 100vh;
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
            overflow: hidden;
        }

        .notif-drop.show {
            display: block;
        }

        .nd-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-light);
        }

        .nd-title {
            font-family: 'Lora', serif;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .mark-read-btn {
            background: none;
            border: none;
            color: var(--sage);
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            font-family: inherit;
        }

        .nd-list {
            max-height: 270px;
            overflow-y: auto;
        }

        .nd-item {
            display: flex;
            gap: 10px;
            padding: 10px 14px;
            border-bottom: 1px solid var(--border-light);
            cursor: pointer;
            transition: background .15s;
        }

        .nd-item.unread {
            background: var(--sage-pale);
        }

        .nd-item.starred {
            border-left: 3px solid #e6a817;
        }

        .nd-item:hover {
            background: var(--cream-2);
        }

        .nd-icon {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: var(--cream-2);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .nd-icon svg {
            width: 14px;
            height: 14px;
            stroke: var(--sage);
        }

        .nd-item-title {
            font-weight: 600;
            font-size: 12.5px;
            color: var(--text-primary);
        }

        .nd-item-desc {
            font-size: 11.5px;
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }

        .nd-item-date {
            font-size: 10.5px;
            color: var(--text-muted);
            font-family: 'DM Mono', monospace;
        }

        .nd-foot {
            text-align: center;
            padding: 10px;
            font-size: 12px;
            border-top: 1px solid var(--border-light);
        }

        .nd-foot a {
            color: var(--sage);
            text-decoration: none;
            font-weight: 600;
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

        .content-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
        }

        .content-scroll::-webkit-scrollbar {
            width: 3px;
        }

        .content-scroll::-webkit-scrollbar-thumb {
            background: var(--cream-3);
            border-radius: 2px;
        }

        .profile-hero {
            background: linear-gradient(135deg, var(--sage-dark) 0%, #3d6a35 45%, #5a9050 100%);
            border-radius: var(--radius);
            padding: 30px 34px;
            display: flex;
            align-items: center;
            gap: 26px;
            margin-bottom: 22px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(45, 82, 40, .28);
        }

        .profile-hero::before {
            content: '';
            position: absolute;
            right: -10px;
            top: 50%;
            transform: translateY(-50%);
            width: 180px;
            height: 180px;
            opacity: .09;
            pointer-events: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='1.2'%3E%3Cpath d='M12 22V12'/%3E%3Cpath d='M12 12c-3-5-8-5-8-5 0 6 5 8 8 8'/%3E%3Cpath d='M12 12c3-5 8-5 8-5 0 6-5 8-8 8'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-size: contain;
        }

        .hero-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .16);
            border: 3px solid rgba(255, 255, 255, .38);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .hero-info {
            flex: 1;
            min-width: 0;
        }

        .hero-name {
            font-family: 'Lora', serif;
            font-size: 24px;
            font-weight: 700;
            color: white;
            margin-bottom: 5px;
        }

        .hero-email {
            font-size: 14px;
            color: rgba(255, 255, 255, .72);
            margin-bottom: 3px;
        }

        .hero-location {
            font-size: 13px;
            color: rgba(255, 255, 255, .55);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .hero-location svg {
            width: 13px;
            height: 13px;
            stroke: rgba(255, 255, 255, .68);
            flex-shrink: 0;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-card {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--border-light);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .card-tabs {
            display: flex;
            border-bottom: 1px solid var(--border-light);
            background: var(--cream);
        }

        .tab-btn {
            flex: 1;
            padding: 15px 18px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-muted);
            transition: all .2s;
            font-family: inherit;
            border-bottom: 2.5px solid transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .tab-btn:hover {
            color: var(--sage);
            background: var(--sage-pale);
        }

        .tab-btn.active {
            color: var(--sage-dark);
            background: var(--white);
            border-bottom-color: var(--sage);
            font-weight: 600;
        }

        .tab-pane {
            display: none;
            padding: 26px;
        }

        .tab-pane.active {
            display: block;
            animation: fadeUp .22s;
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

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: .6px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 7px;
        }

        .form-group label svg {
            width: 13px;
            height: 13px;
            stroke: var(--sage);
            flex-shrink: 0;
        }

        .form-input {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-xs);
            font-size: 15px;
            font-family: inherit;
            color: var(--text-primary);
            background: var(--cream);
            transition: all .2s;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--sage-light);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(90, 122, 82, .1);
        }

        .form-input::placeholder {
            color: var(--text-muted);
        }

        .form-select {
            width: 100%;
            padding: 11px 36px 11px 14px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-xs);
            font-size: 15px;
            font-family: inherit;
            color: var(--text-primary);
            background: var(--cream);
            transition: all .2s;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a18e' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            cursor: pointer;
        }

        .form-select:focus {
            outline: none;
            border-color: var(--sage-light);
            background-color: var(--white);
            box-shadow: 0 0 0 3px rgba(90, 122, 82, .1);
        }

        .form-select:disabled {
            color: var(--text-muted);
            cursor: not-allowed;
            opacity: .6;
        }

        .location-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
        }

        .location-sub-label {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 4px;
            text-align: center;
        }

        .hectares-wrap {
            position: relative;
        }

        .hectares-wrap .form-input {
            padding-right: 68px;
            -moz-appearance: textfield;
        }

        .hectares-wrap .form-input::-webkit-outer-spin-button,
        .hectares-wrap .form-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .hectares-suffix {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-muted);
            pointer-events: none;
        }

        .btn-save {
            width: 100%;
            padding: 13px;
            background: var(--sage);
            color: white;
            border: none;
            border-radius: var(--radius-xs);
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
            font-family: inherit;
            margin-top: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-save:hover {
            background: var(--sage-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(61, 92, 56, .25);
        }

        .btn-save:disabled {
            opacity: .6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-save svg {
            width: 15px;
            height: 15px;
            stroke: currentColor;
        }

        .stats-side {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--border-light);
            padding: 20px 22px;
            box-shadow: var(--shadow-sm);
            transition: all .2s;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .stat-card:hover {
            border-color: var(--sage-light);
            box-shadow: var(--shadow-md);
            transform: translateY(-1px);
        }

        .stat-card-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--sage-pale);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-card-icon svg {
            width: 21px;
            height: 21px;
            stroke: var(--sage-dark);
        }

        .stat-card-body {
            min-width: 0;
            flex: 1;
        }

        .stat-card-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.3px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .stat-card-val {
            font-family: 'Lora', serif;
            font-size: 26px;
            font-weight: 700;
            color: var(--sage-dark);
        }

        .stat-card-sub {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .toast {
            position: fixed;
            top: 22px;
            right: 22px;
            padding: 13px 20px;
            border-radius: var(--radius-sm);
            font-size: 13.5px;
            font-weight: 600;
            box-shadow: var(--shadow-lg);
            z-index: 99999;
            display: flex;
            align-items: center;
            gap: 9px;
            transform: translateX(400px);
            opacity: 0;
            transition: transform .35s cubic-bezier(.34, 1.56, .64, 1), opacity .3s;
        }

        .toast.show {
            transform: translateX(0);
            opacity: 1;
        }

        .toast.success {
            background: var(--sage);
            color: var(--white);
        }

        .toast.error {
            background: var(--red);
            color: var(--white);
        }

        .toast svg {
            width: 16px;
            height: 16px;
            stroke: currentColor;
            flex-shrink: 0;
        }

        @media (max-width: 768px) {

            html,
            body {
                height: auto !important;
                overflow-x: hidden !important;
                overflow-y: auto !important;
            }

            body {
                display: block !important;
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

            .content-scroll {
                overflow: visible !important;
                height: auto !important;
                padding: 16px;
            }

            .profile-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .profile-hero {
                padding: 22px 24px;
            }

            .hero-name {
                font-size: 20px;
            }

            .hero-avatar {
                width: 68px;
                height: 68px;
            }

            .notif-drop {
                right: 10px;
                left: 10px;
                width: auto;
            }

            .profile-drop {
                right: 10px;
            }

            .location-row {
                grid-template-columns: 1fr;
            }

            body.nav-open {
                overflow: hidden !important;
            }
        }

        @media (max-width: 480px) {
            .header {
                padding: 0 14px;
            }

            .content-scroll {
                padding: 12px;
            }

            .profile-hero {
                padding: 18px 20px;
                gap: 16px;
            }

            .hero-name {
                font-size: 18px;
            }

            .hero-avatar {
                width: 58px;
                height: 58px;
            }

            .tab-btn {
                font-size: 13px;
                padding: 13px 12px;
            }

            .tab-pane {
                padding: 20px 18px;
            }
        }
    </style>
</head>

<body>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
    <aside class="sidebar" id="sidebar">
            <div class="sidebar-top">
                <div class="logo-wrap" onclick="toggleSidebar()">
                    <div class="logo-icon"><img src="pictures/RiceWise_Logo.png" alt="RiceWise" onerror="this.style.display='none';this.parentElement.innerHTML='<svg viewBox=&quot;0 0 24 24&quot; width=&quot;24&quot; height=&quot;24&quot; fill=&quot;none&quot; stroke=&quot;%233d5c38&quot; stroke-width=&quot;2&quot;><path d=&quot;M12 22V12&quot;/><path d=&quot;M12 12c-3-5-8-5-8-5 0 6 5 8 8 8&quot;/><path d=&quot;M12 12c3-5 8-5 8-5 0 6-5 8-8 8&quot;/></svg>'"></div>
                    <span class="logo-text">RiceWise</span>
                </div>
                <button class="toggle-btn" onclick="toggleSidebar()" title="Collapse">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <rect x="3" y="3" width="18" height="18" rx="2" />
                        <path d="M9 3v18" />
                    </svg>
                </button>
                <button class="expand-btn" onclick="toggleSidebar()"><img src="pictures/RiceWise_Logo.png" alt="RiceWise" onerror="this.style.display='none';this.parentElement.innerHTML='<svg viewBox=&quot;0 0 24 24&quot; width=&quot;24&quot; height=&quot;24&quot; fill=&quot;none&quot; stroke=&quot;%233d5c38&quot; stroke-width=&quot;2&quot;><path d=&quot;M12 22V12&quot;/><path d=&quot;M12 12c-3-5-8-5-8-5 0 6 5 8 8 8&quot;/><path d=&quot;M12 12c3-5 8-5 8-5 0 6-5 8-8 8&quot;/></svg>'"></button>
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

                <a href="notifications.php" class="nav-item" data-tooltip="Notifications"><svg viewBox="0 0 24 24">
                        <g class="icon-outline" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M13.73 21a2 2 0 0 1-3.46 0" />
                        </g>
                        <g class="icon-filled" fill="currentColor">
                            <path d="M12 3a5 5 0 0 0-5 5v2.2c0 1.9-.6 3.7-1.8 5.2L4 17h16l-1.2-1.6c-1.2-1.5-1.8-3.3-1.8-5.2V8a5 5 0 0 0-5-5z" /><path d="M9.5 19a2.5 2.5 0 0 0 5 0h-5z" />
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
                My Profile
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
            <div class="nd-foot"><a href="notifications.php">View all →</a></div>
        </div>
        <div class="profile-drop" id="profileDrop">
            <h3 id="profileDropName"><?= htmlspecialchars($user['name']) ?></h3>
            <p id="profileDropEmail"><?= htmlspecialchars($user['email']) ?></p>
            <hr>
            <a href="profile.php" class="pd-btn">My Profile</a>
            <a href="logout.php" class="pd-btn">Sign out</a>
        </div>
        <div class="content-scroll">
            <div class="profile-hero">
                <div class="hero-avatar">
                    <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.88)"
                        stroke-width="1.5">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                        <circle cx="12" cy="7" r="4" />
                    </svg>
                </div>
                <div class="hero-info">
                    <div class="hero-name" id="heroName"><?= htmlspecialchars($user['name']) ?></div>
                    <div class="hero-email" id="heroEmail"><?= htmlspecialchars($user['email']) ?></div>
                    <div class="hero-location" id="heroLocation">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" />
                            <circle cx="12" cy="10" r="3" />
                        </svg>
                        <span id="heroLocationText"><?= htmlspecialchars($user['location'] ?: 'Not set') ?></span>
                    </div>
                </div>
            </div>
            <div class="profile-grid">
                <div class="form-card">
                    <div class="card-tabs">
                        <button class="tab-btn active" onclick="switchTab('profile')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                <circle cx="12" cy="7" r="4" />
                            </svg>
                            Profile
                        </button>
                        <button class="tab-btn" onclick="switchTab('password')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" />
                                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                            </svg>
                            Password
                        </button>
                    </div>
                    <div class="tab-pane active" id="profile-tab">
                        <form onsubmit="saveProfile(event)">
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" id="profileName" class="form-input" placeholder="Your full name"
                                    required>
                            </div>
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" id="profileEmail" class="form-input" placeholder="your@email.com"
                                    required>
                            </div>
                            <div class="form-group">
                                <label>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" />
                                        <circle cx="12" cy="10" r="3" />
                                    </svg>
                                    Location
                                </label>
                                <div class="location-row">
                                    <div>
                                        <select id="locationCountry" class="form-select" onchange="onCountryChange()">
                                            <option value="">Country</option>
                                        </select>
                                        <div class="location-sub-label">Country</div>
                                    </div>
                                    <div>
                                        <select id="locationProvince" class="form-select" onchange="onProvinceChange()"
                                            disabled>
                                            <option value="">Province</option>
                                        </select>
                                        <div class="location-sub-label">Province</div>
                                    </div>
                                    <div>
                                        <select id="locationTown" class="form-select" disabled>
                                            <option value="">Town / City</option>
                                        </select>
                                        <div class="location-sub-label">Town / City</div>
                                    </div>
                                </div>
                                <div id="locationFallbackNote" style="display:none;font-size:11.5px;color:var(--text-muted);margin-top:6px;"></div>
                            </div>
                            <div class="form-group">
                                <label>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 22V12" /><path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8" /><path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8" />
                                    </svg>
                                    Farm Size
                                </label>
                                <div class="hectares-wrap">
                                    <input type="number" id="profileHectares" class="form-input"
                                        placeholder="e.g. 2.5" min="0" step="0.01" inputmode="decimal">
                                    <span class="hectares-suffix">hectares</span>
                                </div>
                            </div>
                            <button type="submit" class="btn-save" id="saveProfileBtn">
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
                                    <polyline points="17 21 17 13 7 13 7 21" />
                                    <polyline points="7 3 7 8 15 8" />
                                </svg>
                                Save Changes
                            </button>
                        </form>
                    </div>
                    <div class="tab-pane" id="password-tab">
                        <form onsubmit="changePassword(event)">
                            <div class="form-group">
                                <label>Current Password</label>
                                <input type="password" id="currentPassword" class="form-input"
                                    placeholder="Enter current password" required minlength="4">
                            </div>
                            <div class="form-group">
                                <label>New Password</label>
                                <input type="password" id="newPassword" class="form-input"
                                    placeholder="Enter new password" required minlength="6"
                                    oninput="checkStrength(this.value)">
                                <div style="margin-top:8px;">
                                    <div
                                        style="height:4px;background:var(--cream-3);border-radius:2px;overflow:hidden;margin-bottom:4px;">
                                        <div id="strengthFill"
                                            style="height:100%;border-radius:2px;transition:width .3s,background .3s;width:0;">
                                        </div>
                                    </div>
                                    <div id="strengthLabel" style="font-size:11px;color:var(--text-muted);"></div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password</label>
                                <input type="password" id="confirmPassword" class="form-input"
                                    placeholder="Confirm new password" required minlength="6">
                            </div>
                            <button type="submit" class="btn-save" id="savePasswordBtn">
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                                    <rect x="3" y="11" width="18" height="11" rx="2" />
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                </svg>
                                Change Password
                            </button>
                        </form>
                    </div>
                </div>
                <div class="stats-side">
                    <div class="stat-card">
                        <div class="stat-card-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                                <polyline points="22 4 12 14.01 9 11.01" />
                            </svg>
                        </div>
                        <div class="stat-card-body">
                            <div class="stat-card-label">Account Status</div>
                            <div class="stat-card-val" id="statAccountStatus" style="color:var(--sage)">Active</div>
                            <div class="stat-card-sub" id="statMemberSince">Member since —</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                <polyline points="14 2 14 8 20 8" />
                                <line x1="16" y1="13" x2="8" y2="13" />
                                <line x1="16" y1="17" x2="8" y2="17" />
                            </svg>
                        </div>
                        <div class="stat-card-body">
                            <div class="stat-card-label">Total Records</div>
                            <div class="stat-card-val" id="statTotalRecords">0</div>
                            <div class="stat-card-sub">Farm activities logged</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 22 16 8" />
                                <path d="M3.47 12.53 5 11l1.53 1.53a3.5 3.5 0 0 1 0 4.94L5 19l-1.53-1.53a3.5 3.5 0 0 1 0-4.94z" />
                                <path d="M13.5 3.5 15 2l1.53 1.53a3.5 3.5 0 0 1 0 4.94L15 10l-1.53-1.53a3.5 3.5 0 0 1 0-4.94z" />
                            </svg>
                        </div>
                        <div class="stat-card-body">
                            <div class="stat-card-label">Last Harvest</div>
                            <div class="stat-card-val" id="statLastHarvest">—</div>
                            <div class="stat-card-sub" id="statLastHarvestSub">Across all fields</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" />
                                <circle cx="12" cy="10" r="3" />
                            </svg>
                        </div>
                        <div class="stat-card-body">
                            <div class="stat-card-label">Farm Location</div>
                            <div class="stat-card-val" id="statLocation"
                                style="font-size:16px;font-family:'DM Sans',sans-serif;font-weight:700;">—</div>
                            <div class="stat-card-sub" id="statProvince">Primary farm area</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="toast" id="toast">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2.5" id="toastIcon">
            <polyline points="20 6 9 17 4 12" />
        </svg>
        <span id="toastMsg"></span>
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

        const STAR_ICON = '<svg viewBox="0 0 24 24" width="11" height="11" fill="#e6a817" stroke="#e6a817" stroke-width="1.5" style="display:inline-block;vertical-align:-1px;margin-right:3px;"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';

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
                        <div class="nd-item-title">${parseInt(n.starred, 10) ? STAR_ICON : ''}${bellEscapeHtml(n.title)}</div>
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
        /* ===== REAL SERVER DATA ===== */
        const PHP_USER = <?= $jsUser ?>;
        const PHP_STATS = <?= $jsStats ?>;
        const LOCATION_DATA = {
            'Philippines': {
                code: 'PH',
                provinces: {
                    // NCR
                    'Metro Manila': {
                        towns: ['Caloocan', 'Las Piñas', 'Makati', 'Malabon', 'Mandaluyong', 'Manila', 'Marikina', 'Muntinlupa', 'Navotas', 'Parañaque', 'Pasay', 'Pasig', 'Pateros', 'Quezon City', 'San Juan', 'Taguig', 'Valenzuela']
                    },

                    // Region I - Ilocos Region
                    'Ilocos Norte': {
                        towns: ['Laoag City', 'Batac City', 'Paoay', 'Pagudpud', 'Bangui', 'Currimao']
                    },
                    'Ilocos Sur': {
                        towns: ['Vigan City', 'Candon City', 'Narvacan', 'Cabugao', 'Santa Maria', 'Tagudin']
                    },
                    'La Union': {
                        towns: ['San Fernando City', 'Agoo', 'Bauang', 'Bacnotan', 'Naguilian', 'Rosario']
                    },
                    'Pangasinan': {
                        towns: ['Dagupan City', 'Alaminos City', 'San Carlos City', 'Urdaneta City', 'Lingayen', 'Binmaley']
                    },

                    // Region II - Cagayan Valley
                    'Batanes': {
                        towns: ['Basco', 'Itbayat', 'Ivana', 'Mahatao', 'Sabtang', 'Uyugan']
                    },
                    'Cagayan': {
                        towns: ['Tuguegarao City', 'Aparri', 'Gonzaga', 'Lal-lo', 'Sanchez-Mira', 'Santa Ana']
                    },
                    'Isabela': {
                        towns: ['Ilagan City', 'Cauayan City', 'Santiago City', 'Roxas', 'Cabatuan', 'Echague']
                    },
                    'Nueva Vizcaya': {
                        towns: ['Bayombong', 'Solano', 'Bambang', 'Bagabag', 'Aritao', 'Kasibu']
                    },
                    'Quirino': {
                        towns: ['Cabarroguis', 'Diffun', 'Maddela', 'Aglipay', 'Nagtipunan', 'Saguday']
                    },

                    // Region III - Central Luzon
                    'Aurora': {
                        towns: ['Baler', 'Casiguran', 'Dinalungan', 'Dingalan', 'Dipaculao', 'Maria Aurora']
                    },
                    'Bataan': {
                        towns: ['Balanga City', 'Mariveles', 'Orion', 'Dinalupihan', 'Hermosa', 'Limay']
                    },
                    'Bulacan': {
                        towns: ['Angat', 'Balagtas', 'Baliuag', 'Bocaue', 'Bulakan', 'Bustos', 'Calumpit', 'Doña Remedios Trinidad', 'Guiguinto', 'Hagonoy', 'Malolos City', 'Marilao', 'Meycauayan City', 'Norzagaray', 'Obando', 'Pandi', 'Paombong', 'Plaridel', 'Pulilan', 'San Ildefonso', 'San Jose del Monte City', 'San Miguel', 'San Rafael', 'Santa Maria']
                    },
                    'Nueva Ecija': {
                        towns: ['Aliaga', 'Bongabon', 'Cabanatuan City', 'Cabiao', 'Carranglan', 'Cuyapo', 'Gabaldon', 'Gapan City', 'Guimba', 'Jaen', 'Laur', 'Licab', 'Llanera', 'Lupao', 'Muñoz City', 'Palayan City', 'San Antonio', 'San Isidro', 'San Jose City', 'San Leonardo', 'Santa Rosa', 'Santo Domingo', 'Talavera', 'Zaragoza']
                    },
                    'Pampanga': {
                        towns: ['Angeles City', 'Apalit', 'Arayat', 'Bacolor', 'Candaba', 'Floridablanca', 'Guagua', 'Lubao', 'Mabalacat City', 'Macabebe', 'Magalang', 'Masantol', 'Mexico', 'Minalin', 'Porac', 'San Fernando City', 'San Luis', 'San Simon', 'Santa Ana', 'Santa Rita', 'Santo Tomas', 'Sasmuan']
                    },
                    'Tarlac': {
                        towns: ['Tarlac City', 'Capas', 'Concepcion', 'Gerona', 'Paniqui', 'Camiling']
                    },
                    'Zambales': {
                        towns: ['Olongapo City', 'Iba', 'Subic', 'Botolan', 'Castillejos', 'San Antonio']
                    },

                    // Region IV-A - CALABARZON
                    'Batangas': {
                        towns: ['Batangas City', 'Bauan', 'Lipa City', 'Nasugbu', 'Tanauan City', 'Santo Tomas', 'Taal']
                    },
                    'Cavite': {
                        towns: ['Alfonso', 'Amadeo', 'Bacoor City', 'Carmona', 'Cavite City', 'Dasmariñas City', 'General Trias City', 'Imus City', 'Indang', 'Kawit', 'Maragondon', 'Mendez', 'Naic', 'Noveleta', 'Rosario', 'Silang', 'Tagaytay City', 'Tanza', 'Ternate', 'Trece Martires City']
                    },
                    'Laguna': {
                        towns: ['Bay', 'Biñan City', 'Cabuyao City', 'Calamba City', 'Calauan', 'Los Baños', 'Nagcarlan', 'Pagsanjan', 'San Pablo City', 'San Pedro City', 'Santa Cruz', 'Santa Rosa City', 'Victoria']
                    },
                    'Quezon': {
                        towns: ['Lucena City', 'Tayabas City', 'Candelaria', 'Sariaya', 'Lucban', 'Infanta']
                    },
                    'Rizal': {
                        towns: ['Antipolo City', 'Cainta', 'Taytay', 'Angono', 'Binangonan', 'Rodriguez']
                    },

                    // Region IV-B - MIMAROPA
                    'Marinduque': {
                        towns: ['Boac', 'Gasan', 'Mogpog', 'Santa Cruz', 'Torrijos', 'Buenavista']
                    },
                    'Occidental Mindoro': {
                        towns: ['Mamburao', 'San Jose', 'Sablayan', 'Looc', 'Magsaysay', 'Abra de Ilog']
                    },
                    'Oriental Mindoro': {
                        towns: ['Calapan City', 'Puerto Galera', 'Pinamalayan', 'Roxas', 'Bongabong', 'Naujan']
                    },
                    'Palawan': {
                        towns: ['Puerto Princesa City', 'Coron', 'El Nido', 'Roxas', 'Brooke\'s Point', 'Taytay']
                    },
                    'Romblon': {
                        towns: ['Romblon', 'Odiongan', 'Cajidiocan', 'Looc', 'San Fernando', 'Santa Fe']
                    },

                    // Region V - Bicol Region
                    'Albay': {
                        towns: ['Legazpi City', 'Daraga', 'Tabaco City', 'Ligao City', 'Guinobatan', 'Polangui']
                    },
                    'Camarines Norte': {
                        towns: ['Daet', 'Labo', 'Jose Panganiban', 'Vinzons', 'Basud', 'Mercedes']
                    },
                    'Camarines Sur': {
                        towns: ['Naga City', 'Iriga City', 'Pili', 'Goa', 'Sipocot', 'Calabanga']
                    },
                    'Catanduanes': {
                        towns: ['Virac', 'Bato', 'San Andres', 'Baras', 'Caramoran', 'Pandan']
                    },
                    'Masbate': {
                        towns: ['Masbate City', 'Cataingan', 'Milagros', 'Mobo', 'Aroroy', 'Placer']
                    },
                    'Sorsogon': {
                        towns: ['Sorsogon City', 'Bulan', 'Gubat', 'Irosin', 'Casiguran', 'Matnog']
                    },

                    // CAR - Cordillera Administrative Region
                    'Abra': {
                        towns: ['Bangued', 'Boliney', 'Bucay', 'Dolores', 'La Paz', 'Tayum']
                    },
                    'Apayao': {
                        towns: ['Kabugao', 'Luna', 'Conner', 'Flora', 'Pudtol', 'Santa Marcela']
                    },
                    'Benguet': {
                        towns: ['Baguio City', 'La Trinidad', 'Itogon', 'Kapangan', 'Kibungan', 'Tuba']
                    },
                    'Ifugao': {
                        towns: ['Lagawe', 'Banaue', 'Kiangan', 'Lamut', 'Hungduan', 'Mayoyao']
                    },
                    'Kalinga': {
                        towns: ['Tabuk City', 'Lubuagan', 'Pinukpuk', 'Rizal', 'Tanudan', 'Tinglayan']
                    },
                    'Mountain Province': {
                        towns: ['Bontoc', 'Sagada', 'Besao', 'Bauko', 'Sabangan', 'Tadian']
                    },

                    // Region VI - Western Visayas
                    'Aklan': {
                        towns: ['Kalibo', 'Boracay (Malay)', 'Ibajay', 'Numancia', 'Banga', 'New Washington']
                    },
                    'Antique': {
                        towns: ['San Jose de Buenavista', 'Culasi', 'Sibalom', 'Hamtic', 'Pandan', 'Tibiao']
                    },
                    'Capiz': {
                        towns: ['Roxas City', 'Panay', 'Ivisan', 'Sigma', 'Dumalag', 'Pontevedra']
                    },
                    'Guimaras': {
                        towns: ['Jordan', 'Buenavista', 'Nueva Valencia', 'San Lorenzo', 'Sibunag']
                    },
                    'Iloilo': {
                        towns: ['Iloilo City', 'Passi City', 'Oton', 'Pavia', 'Santa Barbara', 'Guimbal', 'Miagao', 'Tigbauan']
                    },
                    'Negros Occidental': {
                        towns: ['Bacolod City', 'Silay City', 'Talisay City', 'Bago City', 'Cadiz City', 'San Carlos City']
                    },

                    // Region VII - Central Visayas
                    'Bohol': {
                        towns: ['Tagbilaran City', 'Panglao', 'Loboc', 'Dauis', 'Carmen', 'Jagna']
                    },
                    'Cebu': {
                        towns: ['Cebu City', 'Lapu-Lapu City', 'Mandaue City', 'Talisay City', 'Toledo City', 'Danao City', 'Carcar City', 'Naga City']
                    },
                    'Negros Oriental': {
                        towns: ['Dumaguete', 'Bais City', 'Bayawan City', 'Canlaon City', 'Guihulngan City', 'Tanjay City', 'Siaton', 'Valencia']
                    },
                    'Siquijor': {
                        towns: ['Siquijor', 'Larena', 'Lazi', 'Maria', 'San Juan', 'Enrique Villanueva']
                    },

                    // Region VIII - Eastern Visayas
                    'Biliran': {
                        towns: ['Naval', 'Almeria', 'Biliran', 'Cabucgayan', 'Caibiran', 'Kawayan']
                    },
                    'Eastern Samar': {
                        towns: ['Borongan City', 'Guiuan', 'Balangiga', 'Hernani', 'Salcedo', 'Llorente']
                    },
                    'Leyte': {
                        towns: ['Tacloban City', 'Ormoc City', 'Baybay City', 'Palo', 'Carigara', 'Abuyog']
                    },
                    'Northern Samar': {
                        towns: ['Catarman', 'Laoang', 'Palapag', 'Mondragon', 'Bobon', 'Allen']
                    },
                    'Samar': {
                        towns: ['Catbalogan City', 'Calbayog City', 'Basey', 'Gandara', 'Jiabong', 'Tarangnan']
                    },
                    'Southern Leyte': {
                        towns: ['Maasin City', 'Sogod', 'Liloan', 'Bontoc', 'Hinunangan', 'Malitbog']
                    },

                    // Region IX - Zamboanga Peninsula
                    'Zamboanga del Norte': {
                        towns: ['Dipolog City', 'Dapitan City', 'Sindangan', 'Polanco', 'Liloy', 'Siocon']
                    },
                    'Zamboanga del Sur': {
                        towns: ['Pagadian City', 'Zamboanga City (independent)', 'Molave', 'Aurora', 'Dumingag', 'Tambulig']
                    },
                    'Zamboanga Sibugay': {
                        towns: ['Ipil', 'Kabasalan', 'Naga', 'Buug', 'Diplahan', 'Titay']
                    },

                    // Region X - Northern Mindanao
                    'Bukidnon': {
                        towns: ['Malaybalay City', 'Valencia City', 'Manolo Fortich', 'Maramag', 'Don Carlos', 'Quezon']
                    },
                    'Camiguin': {
                        towns: ['Mambajao', 'Catarman', 'Guinsiliban', 'Mahinog', 'Sagay']
                    },
                    'Lanao del Norte': {
                        towns: ['Tubod', 'Iligan City', 'Kapatagan', 'Baroy', 'Kolambugan', 'Maigo']
                    },
                    'Misamis Occidental': {
                        towns: ['Oroquieta City', 'Ozamiz City', 'Tangub City', 'Aloran', 'Plaridel', 'Sinacaban']
                    },
                    'Misamis Oriental': {
                        towns: ['Cagayan de Oro City', 'Gingoog City', 'Tagoloan', 'Jasaan', 'Balingasag', 'Villanueva']
                    },

                    // Region XI - Davao Region
                    'Davao de Oro': {
                        towns: ['Nabunturan', 'Compostela', 'Mabini', 'Maco', 'Monkayo', 'Pantukan']
                    },
                    'Davao del Norte': {
                        towns: ['Tagum City', 'Panabo City', 'Samal City', 'Carmen', 'Kapalong', 'Santo Tomas']
                    },
                    'Davao del Sur': {
                        towns: ['Digos City', 'Davao City (independent)', 'Bansalan', 'Hagonoy', 'Matanao', 'Santa Cruz']
                    },
                    'Davao Occidental': {
                        towns: ['Malita', 'Santa Maria', 'Don Marcelino', 'Jose Abad Santos', 'Sarangani']
                    },
                    'Davao Oriental': {
                        towns: ['Mati City', 'Baganga', 'Cateel', 'Caraga', 'Manay', 'Lupon']
                    },

                    // Region XII - SOCCSKSARGEN
                    'Cotabato': {
                        towns: ['Kidapawan City', 'Midsayap', 'Kabacan', 'Makilala', 'Magpet', 'Tulunan']
                    },
                    'Sarangani': {
                        towns: ['Alabel', 'Glan', 'Malapatan', 'Maasim', 'Kiamba', 'Maitum']
                    },
                    'South Cotabato': {
                        towns: ['Koronadal City', 'General Santos City', 'Polomolok', 'Tupi', 'Tampakan', 'Surallah']
                    },
                    'Sultan Kudarat': {
                        towns: ['Isulan', 'Tacurong City', 'Lebak', 'Kalamansig', 'Palimbang', 'Bagumbayan']
                    },

                    // Region XIII - Caraga
                    'Agusan del Norte': {
                        towns: ['Butuan City', 'Cabadbaran City', 'Buenavista', 'Nasipit', 'Magallanes', 'Santiago']
                    },
                    'Agusan del Sur': {
                        towns: ['Prosperidad', 'Bayugan City', 'San Francisco', 'Bunawan', 'Rosario', 'Trento']
                    },
                    'Dinagat Islands': {
                        towns: ['San Jose', 'Basilisa', 'Cagdianao', 'Loreto', 'Tubajon', 'Libjo']
                    },
                    'Surigao del Norte': {
                        towns: ['Surigao City', 'Siargao (General Luna)', 'Dapa', 'Del Carmen', 'Pilar', 'Placer']
                    },
                    'Surigao del Sur': {
                        towns: ['Tandag City', 'Bislig City', 'Cantilan', 'Lianga', 'Cortes', 'Barobo']
                    },

                    // BARMM
                    'Basilan': {
                        towns: ['Isabela City', 'Lamitan City', 'Maluso', 'Sumisip', 'Tuburan', 'Akbar']
                    },
                    'Lanao del Sur': {
                        towns: ['Marawi City', 'Bayang', 'Balindong', 'Malabang', 'Tugaya', 'Wao']
                    },
                    'Maguindanao del Norte': {
                        towns: ['Datu Odin Sinsuat', 'Sultan Kudarat', 'Kabuntalan', 'Parang', 'Upi', 'Barira']
                    },
                    'Maguindanao del Sur': {
                        towns: ['Buluan', 'Datu Piang', 'Shariff Aguak', 'Mamasapano', 'Ampatuan', 'Talayan']
                    },
                    'Sulu': {
                        towns: ['Jolo', 'Patikul', 'Indanan', 'Siasi', 'Parang', 'Talipao']
                    },
                    'Tawi-Tawi': {
                        towns: ['Bongao', 'Panglima Sugala', 'Sitangkai', 'Simunul', 'Languyan', 'Mapun']
                    }
                }
            },
            'Indonesia': {
                code: 'ID',
                provinces: {
                    'Java': {
                        towns: ['Jakarta', 'Surabaya', 'Bandung', 'Bekasi', 'Depok', 'Semarang', 'Tangerang', 'Malang', 'Bogor', 'Yogyakarta']
                    },
                    'Bali': {
                        towns: ['Denpasar', 'Kuta', 'Ubud', 'Singaraja', 'Tabanan', 'Gianyar']
                    },
                    'Sumatra': {
                        towns: ['Medan', 'Palembang', 'Pekanbaru', 'Padang', 'Batam']
                    }
                }
            },
            'Vietnam': {
                code: 'VN',
                provinces: {
                    'Hanoi Region': {
                        towns: ['Hanoi', 'Ha Long', 'Ninh Binh', 'Thai Binh', 'Nam Dinh']
                    },
                    'Ho Chi Minh Region': {
                        towns: ['Ho Chi Minh City', 'Bien Hoa', 'Vung Tau', 'My Tho', 'Can Tho']
                    },
                    'Mekong Delta': {
                        towns: ['Can Tho', 'An Giang', 'Kien Giang', 'Vinh Long', 'Ben Tre']
                    }
                }
            },
            'Thailand': {
                code: 'TH',
                provinces: {
                    'Central Thailand': {
                        towns: ['Bangkok', 'Nonthaburi', 'Pathum Thani', 'Ayutthaya']
                    },
                    'Northern Thailand': {
                        towns: ['Chiang Mai', 'Chiang Rai', 'Lampang', 'Lamphun']
                    },
                    'Southern Thailand': {
                        towns: ['Hat Yai', 'Surat Thani', 'Phuket', 'Krabi']
                    }
                }
            },
            'Malaysia': {
                code: 'MY',
                provinces: {
                    'Selangor': {
                        towns: ['Shah Alam', 'Petaling Jaya', 'Klang', 'Subang Jaya', 'Kajang']
                    },
                    'Kuala Lumpur': {
                        towns: ['Kuala Lumpur', 'Bangsar', 'Bukit Bintang', 'Wangsa Maju']
                    },
                    'Penang': {
                        towns: ['Georgetown', 'Butterworth', 'Bayan Lepas']
                    },
                    'Johor': {
                        towns: ['Johor Bahru', 'Muar', 'Batu Pahat', 'Kluang', 'Segamat']
                    }
                }
            }
        };
        // userData mirrors PHP_USER — no more localStorage as source of truth.
        let userData = {
            name: PHP_USER.name,
            email: PHP_USER.email,
            location: PHP_USER.location,
            hectares: (PHP_USER.hectares !== null && PHP_USER.hectares !== undefined) ? PHP_USER.hectares : '',
            locationTown: '',
            locationProvince: '',
            locationCountry: ''
        };

        function init() {
            populateCountryDropdown();
            parseLocationString();
            loadUserData();
            loadStats();
        }

        function populateCountryDropdown() {
            const sel = document.getElementById('locationCountry');
            sel.innerHTML = '<option value="">Select Country</option>';
            Object.keys(LOCATION_DATA).forEach(c => {
                const o = document.createElement('option');
                o.value = c;
                o.textContent = c;
                sel.appendChild(o);
            });
        }
        // Best-effort parse of the single `location` string ("Town, Province, Country")
        // back into the three dropdowns. Not every stored location will match
        // known dropdown data (e.g. free-text entries) — falls back gracefully.
        function parseLocationString() {
            const parts = (userData.location || '').split(',').map(s => s.trim()).filter(Boolean);
            const note = document.getElementById('locationFallbackNote');
            if (!parts.length) {
                note.style.display = 'none';
                return;
            }
            // Try matching from the most specific (town) upward against LOCATION_DATA.
            for (const country in LOCATION_DATA) {
                for (const province in LOCATION_DATA[country].provinces) {
                    const townMatch = LOCATION_DATA[country].provinces[province].towns.find(t => parts.includes(t));
                    if (townMatch) {
                        userData.locationCountry = country;
                        userData.locationProvince = province;
                        userData.locationTown = townMatch;
                        note.style.display = 'none';
                        return;
                    }
                }
            }
            // No exact match — leave dropdowns blank but show what's on file so
            // it isn't silently discarded.
            note.textContent = `Currently on file: "${userData.location}". Pick matching dropdowns below and save to update it.`;
            note.style.display = 'block';
        }

        function onCountryChange() {
            const country = document.getElementById('locationCountry').value;
            const provSel = document.getElementById('locationProvince'),
                townSel = document.getElementById('locationTown');
            provSel.innerHTML = '<option value="">Select Province</option>';
            townSel.innerHTML = '<option value="">Select Town / City</option>';
            townSel.disabled = true;
            if (!country || !LOCATION_DATA[country]) {
                provSel.disabled = true;
                return;
            }
            provSel.disabled = false;
            Object.keys(LOCATION_DATA[country].provinces).sort().forEach(p => {
                const o = document.createElement('option');
                o.value = p;
                o.textContent = p;
                provSel.appendChild(o);
            });
        }

        function onProvinceChange() {
            const country = document.getElementById('locationCountry').value,
                province = document.getElementById('locationProvince').value;
            const townSel = document.getElementById('locationTown');
            townSel.innerHTML = '<option value="">Select Town / City</option>';
            if (!province || !LOCATION_DATA[country]?.provinces[province]) {
                townSel.disabled = true;
                return;
            }
            townSel.disabled = false;
            LOCATION_DATA[country].provinces[province].towns.sort().forEach(t => {
                const o = document.createElement('option');
                o.value = t;
                o.textContent = t;
                townSel.appendChild(o);
            });
        }

        function setDropdownsFromData() {
            document.getElementById('locationCountry').value = userData.locationCountry || '';
            onCountryChange();
            if (userData.locationProvince) {
                document.getElementById('locationProvince').value = userData.locationProvince;
                onProvinceChange();
            }
            if (userData.locationTown) document.getElementById('locationTown').value = userData.locationTown;
        }

        function loadUserData() {
            document.getElementById('profileName').value = userData.name;
            document.getElementById('profileEmail').value = userData.email;
            document.getElementById('profileHectares').value = userData.hectares;
            setDropdownsFromData();
            updateHeroAndSideStats();
        }

        function loadStats() {
            const statusEl = document.getElementById('statAccountStatus');
            statusEl.textContent = PHP_STATS.accountStatus;
            statusEl.style.color = PHP_STATS.accountStatus === 'Active' ? 'var(--sage)' : 'var(--red)';
            document.getElementById('statMemberSince').textContent = 'Member since ' + PHP_STATS.memberSince;
            document.getElementById('statTotalRecords').textContent = PHP_STATS.totalRecords.toLocaleString();
            if (PHP_STATS.lastHarvestKg !== null) {
                document.getElementById('statLastHarvest').textContent = PHP_STATS.lastHarvestKg.toLocaleString() + ' kg';
                document.getElementById('statLastHarvestSub').textContent = 'On ' + new Date(PHP_STATS.lastHarvestDate + 'T00:00:00').toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                });
            } else {
                document.getElementById('statLastHarvest').textContent = '—';
                document.getElementById('statLastHarvestSub').textContent = 'No harvest recorded yet';
            }
        }

        function getLocationString() {
            return [userData.locationTown, userData.locationProvince, userData.locationCountry].filter(Boolean).join(', ') || userData.location || 'Not set';
        }

        function getLocationShort() {
            if (!userData.locationTown && !userData.locationProvince) return userData.location || 'Not set';
            const town = userData.locationTown || userData.locationProvince || 'N/A';
            const code = LOCATION_DATA[userData.locationCountry]?.code || userData.locationCountry || '';
            return code ? `${town}, ${code}` : town;
        }

        function updateHeroAndSideStats() {
            document.getElementById('heroName').textContent = userData.name;
            document.getElementById('heroEmail').textContent = userData.email;
            document.getElementById('heroLocationText').textContent = getLocationString() + (userData.hectares !== '' && userData.hectares !== null && userData.hectares !== undefined ? ` · ${userData.hectares} ha` : '');
            document.getElementById('statLocation').textContent = getLocationShort();
            const provinceParts = [userData.locationProvince, userData.locationCountry].filter(Boolean).join(', ');
            document.getElementById('statProvince').textContent = (userData.hectares !== '' && userData.hectares !== null && userData.hectares !== undefined) ?
                `${userData.hectares} ha${provinceParts ? ' · ' + provinceParts : ''}` :
                (provinceParts || 'Primary farm area');
        }

        function switchTab(tab) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            document.querySelector(`[onclick="switchTab('${tab}')"]`).classList.add('active');
            document.getElementById(`${tab}-tab`).classList.add('active');
        }

        function saveProfile(e) {
            e.preventDefault();
            const name = document.getElementById('profileName').value.trim();
            const email = document.getElementById('profileEmail').value.trim();
            const country = document.getElementById('locationCountry').value;
            const province = document.getElementById('locationProvince').value;
            const town = document.getElementById('locationTown').value;
            const hectaresRaw = document.getElementById('profileHectares').value.trim();
            if (!name || !email) {
                showToast('Please fill in all required fields.', 'error');
                return;
            }
            if (!country) {
                showToast('Please select a country.', 'error');
                return;
            }
            if (!province) {
                showToast('Please select a province.', 'error');
                return;
            }
            if (!town) {
                showToast('Please select a town / city.', 'error');
                return;
            }
            let hectares = null;
            if (hectaresRaw !== '') {
                hectares = parseFloat(hectaresRaw);
                if (isNaN(hectares) || hectares < 0) {
                    showToast('Please enter a valid farm size in hectares.', 'error');
                    return;
                }
            }
            const locationString = [town, province, country].filter(Boolean).join(', ');
            const btn = document.getElementById('saveProfileBtn');
            btn.disabled = true;
            fetch('profile_update.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    name,
                    email,
                    location: locationString,
                    hectares
                })
            }).then(res => res.json()).then(json => {
                btn.disabled = false;
                if (!json.success) {
                    showToast(json.message || 'Failed to update profile.', 'error');
                    return;
                }
                userData.name = name;
                userData.email = email;
                userData.location = locationString;
                userData.locationCountry = country;
                userData.locationProvince = province;
                userData.locationTown = town;
                userData.hectares = hectares !== null ? hectares : '';
                document.getElementById('locationFallbackNote').style.display = 'none';
                updateHeroAndSideStats();
                document.getElementById('headerName').textContent = name;
                document.getElementById('profileDropName').textContent = name;
                document.getElementById('profileDropEmail').textContent = email;
                showToast('Profile updated successfully!', 'success');
            }).catch(() => {
                btn.disabled = false;
                showToast('Network error — please try again.', 'error');
            });
        }

        function checkStrength(val) {
            const fill = document.getElementById('strengthFill'),
                label = document.getElementById('strengthLabel');
            if (!val) {
                fill.style.width = '0';
                label.textContent = '';
                return;
            }
            let score = 0;
            if (val.length >= 8) score++;
            if (/[A-Z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;
            const levels = [{
                w: '20%',
                c: '#e74c3c',
                t: 'Too weak'
            }, {
                w: '40%',
                c: '#e67e22',
                t: 'Weak'
            }, {
                w: '65%',
                c: '#f1c40f',
                t: 'Fair'
            }, {
                w: '85%',
                c: '#27ae60',
                t: 'Strong'
            }, {
                w: '100%',
                c: '#16a085',
                t: 'Very strong'
            }];
            const l = levels[score] || levels[0];
            fill.style.width = l.w;
            fill.style.background = l.c;
            label.textContent = l.t;
            label.style.color = l.c;
        }

        function changePassword(e) {
            e.preventDefault();
            const curr = document.getElementById('currentPassword').value,
                nw = document.getElementById('newPassword').value,
                conf = document.getElementById('confirmPassword').value;
            if (!curr || !nw || !conf) {
                showToast('Please fill in all password fields.', 'error');
                return;
            }
            if (nw !== conf) {
                showToast('New passwords do not match.', 'error');
                return;
            }
            if (nw.length < 6) {
                showToast('Password must be at least 6 characters.', 'error');
                return;
            }
            const btn = document.getElementById('savePasswordBtn');
            btn.disabled = true;
            fetch('profile_change_password.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    current_password: curr,
                    new_password: nw
                })
            }).then(res => res.json()).then(json => {
                btn.disabled = false;
                if (!json.success) {
                    showToast(json.message || 'Failed to change password.', 'error');
                    return;
                }
                ['currentPassword', 'newPassword', 'confirmPassword'].forEach(id => document.getElementById(id).value = '');
                document.getElementById('strengthFill').style.width = '0';
                document.getElementById('strengthLabel').textContent = '';
                showToast('Password changed successfully!', 'success');
            }).catch(() => {
                btn.disabled = false;
                showToast('Network error — please try again.', 'error');
            });
        }
        let toastTimer;

        function showToast(msg, type = 'success') {
            const t = document.getElementById('toast'),
                icon = document.getElementById('toastIcon');
            document.getElementById('toastMsg').textContent = msg;
            t.className = `toast ${type}`;
            icon.innerHTML = type === 'success' ? '<polyline points="20 6 9 17 4 12" stroke-width="2.5"/>' : '<line x1="18" y1="6" x2="6" y2="18" stroke-width="2.5"/><line x1="6" y1="6" x2="18" y2="18" stroke-width="2.5"/>';
            requestAnimationFrame(() => t.classList.add('show'));
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => t.classList.remove('show'), 3200);
        }
        init();
    </script>
</body>

</html>