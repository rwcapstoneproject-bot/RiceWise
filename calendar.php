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

// Fetch user (for header name/email — same pattern as dashboard.php / records.php)
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

// Fetch all calendar events for this user.
// NOTE: category / event_time / reminder_type require a schema addition —
// see the ALTER TABLE statement shared alongside this file. Existing rows
// without these values will just default to 'other' / 08:00 / same-day.
$rs = $conn->prepare(
    "SELECT id, title, description, event_date, event_end_date, event_time, category, reminder_type, color, cycle_id, step_index
     FROM calendar_events WHERE user_id=? ORDER BY event_date ASC, event_time ASC"
);
$rs->bind_param('i', $uid);
$rs->execute();
$events = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
$rs->close();

$jsEvents = json_encode($events);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RiceWise - Calendar</title>
	<link rel="icon" type="image/png" href="pictures/RiceWise_Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Lora:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap"
        rel="stylesheet">
    <script>
        if (window.innerWidth > 768 && localStorage.getItem('sidebarCollapsed') === '1') {
            document.documentElement.classList.add('sidebar-pre-collapse');
            document.addEventListener('DOMContentLoaded', function() {
                document.body.classList.add('sidebar-collapsed');
            });
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
            --blue: #2980b9;
            --blue-pale: #eaf4fc;
            --amber: #d68910;
            --amber-pale: #fef9ec;
            --text-primary: #1e2a1a;
            --text-secondary: #5a6655;
            --text-muted: #94a18e;
            --border: #ddd8cf;
            --border-light: #ede8e0;
            --white: #ffffff;
            --shadow-sm: 0 2px 8px rgba(90, 122, 82, .08);
            --shadow-md: 0 6px 24px rgba(90, 122, 82, .12);
            --shadow-lg: 0 12px 40px rgba(90, 122, 82, .18);
            --radius: 16px;
            --radius-sm: 10px;
            --sidebar-w: 252px;
            --header-h: 64px;
            --cat-planting: #4a9b5f;
            --cat-planting-pale: #e6f5ea;
            --cat-harvest: #c8963e;
            --cat-harvest-pale: #fdf3e3;
            --cat-irrigation: #2980b9;
            --cat-irrigation-pale: #e3f2fc;
            --cat-pest: #c0392b;
            --cat-pest-pale: #fdecea;
            --cat-fertilize: #7b5ea7;
            --cat-fertilize-pale: #f0ecf9;
            --cat-other: #5a7a52;
            --cat-other-pale: #e8f0e4;
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

        body.sidebar-collapsed .logo-wrap {
            display: none;
        }

        body.sidebar-collapsed .logo-text,
        body.sidebar-collapsed .nav-label,
        body.sidebar-collapsed .nav-text,
        body.sidebar-collapsed .signout-text {
            display: none;
        }

        body.sidebar-collapsed .expand-btn {
            display: none;
        }

        body.sidebar-collapsed .nav-section {
            padding: 10px 0;
            align-items: center;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        body.sidebar-collapsed .nav-item {
            width: 44px;
            height: 44px;
            justify-content: center;
            padding: 0;
            border-radius: var(--radius-sm);
        }

        body.sidebar-collapsed .nav-item.active::before {
            display: none;
        }

        body.sidebar-collapsed .nav-item svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        body.sidebar-collapsed .sidebar-bottom {
            padding: 10px 0 16px;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        body.sidebar-collapsed .signout-btn {
            width: 44px;
            height: 44px;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0;
            border-radius: var(--radius-sm);
            gap: 0;
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
            overflow: hidden;
        }

        .logo-icon img {
            width: 34px;
            height: 34px;
            object-fit: contain;
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
            transition: background .2s;
            overflow: hidden;
            position: relative; /* for icon overlay */
        }

        .expand-btn:hover {
            background: var(--sage-pale);
        }

        .expand-btn-logo {
            width: 34px;
            height: 34px;
            object-fit: contain;
            display: block;
            transition: opacity .18s;
        }

        .expand-btn:hover .expand-btn-logo {
            opacity: 0;
        }

        .expand-btn-icon {
            position: absolute;
            width: 20px;
            height: 20px;
            stroke: var(--sage-dark);
            opacity: 0;
            transition: opacity .18s;
            pointer-events: none;
        }

        .expand-btn:hover .expand-btn-icon {
            opacity: 1;
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
            
        .nav-item svg .icon-filled {
            display: none;
        }

        .nav-item.active svg .icon-outline {
            display: none;
        }

        .nav-item.active svg .icon-filled {
            display: block;
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

        .sidebar-bottom {
            padding: 10px 12px 16px;
            border-top: 1px solid var(--border);
            flex-shrink: 0;
            display: flex;
            align-items: center;
        }

        .signout-btn {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 9px;
            width: 100%;
            padding: 10px 13px;
            background: transparent;
            color: var(--text-secondary);
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
            position: sticky;
            top: 0;
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
            text-decoration: none;
            transition: background .2s;
            cursor: pointer;
            border: none;
            background: transparent;
        }

        .notif-btn:hover {
            background: var(--sage-pale);
        }

        .notif-btn svg {
            stroke: var(--text-secondary);
            fill: none;
            transition: stroke .2s, fill .2s;
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

        /* ===== NOTIFICATION DROPDOWN ===== */
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
            margin-top: 1px;
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

        /* ===== CALENDAR CONTENT ===== */
        .calendar-content {
            flex: 1;
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 16px;
            padding: 16px 24px 20px;
            overflow: hidden;
            min-height: 0;
        }

        .calendar-container {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            padding: 22px 24px 16px;
            min-height: 0;
        }

        .calendar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-shrink: 0;
            flex-wrap: wrap;
            gap: 10px;
        }

        .cal-title-group {
            display: flex;
            flex-direction: column;
        }

        .cal-month-title {
            font-family: 'Lora', serif;
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .cal-sub {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
            font-family: 'DM Mono', monospace;
        }

        .cal-nav-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .nav-btn {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            border: 1.5px solid var(--border);
            background: var(--white);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            font-size: 16px;
            transition: all .2s;
            flex-shrink: 0;
        }

        .nav-btn:hover {
            background: var(--sage);
            border-color: var(--sage);
            color: var(--white);
        }

        .month-year-selects {
            display: flex;
            gap: 6px;
        }

        .month-select,
        .year-select {
            padding: 6px 10px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            cursor: pointer;
            background: var(--white);
            font-family: 'DM Sans', sans-serif;
            transition: border .2s;
            appearance: none;
            -webkit-appearance: none;
        }

        .month-select {
            min-width: 108px;
        }

        .year-select {
            min-width: 70px;
        }

        .month-select:focus,
        .year-select:focus {
            outline: none;
            border-color: var(--sage-light);
        }

        .today-btn {
            padding: 6px 14px;
            border: 1.5px solid var(--border);
            background: var(--white);
            border-radius: var(--radius-sm);
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all .2s;
            font-family: 'DM Sans', sans-serif;
            border-radius: 50px;
        }

        .today-btn:hover {
            background: var(--sage-pale);
            border-color: var(--sage-light);
            color: var(--sage-dark);
        }

        .day-headers {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            margin-bottom: 8px;
            flex-shrink: 0;
			min-width: 0;
        }

        .day-header {
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            padding: 6px 0;
            text-transform: uppercase;
            letter-spacing: .8px;
        }

        .day-header.sun {
            color: #d9534f;
        }

        .day-header.sat {
            color: var(--blue);
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 3px;
            flex: 1;
            min-height: 0;
			min-width: 0; 
        }

        .day-cell {
            display: flex;
            flex-direction: column;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all .18s;
            position: relative;
            padding: 6px 6px 4px;
            min-height: 0;
			min-width: 0;                /* ADD */
    		overflow: hidden;
            border: 1.5px solid transparent;
        }

        .day-cell:hover {
            background: var(--sage-pale);
        }

        .day-cell.other-month .day-num {
            color: var(--cream-3);
        }

        .day-cell.other-month {
            pointer-events: none;
        }

        .day-cell.sun .day-num {
            color: #d9534f;
        }

        .day-cell.sat .day-num {
            color: var(--blue);
        }

        .day-num {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-primary);
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            flex-shrink: 0;
            font-family: 'DM Mono', monospace;
        }

        .day-cell.today .day-num {
            background: var(--sage);
            color: var(--white);
            font-weight: 700;
        }

        .day-cell.selected {
            border-color: var(--sage-light);
            background: var(--sage-pale);
            box-shadow: 0 2px 8px rgba(90, 122, 82, .12);
        }

        .day-cell.selected.today {
            border-color: var(--sage-dark);
        }

        .cell-events {
            display: flex;
            flex-direction: column;
            gap: 2px;
            margin-top: 3px;
            flex: 1;
            overflow: hidden;
            min-width: 0;
        }

        .cell-event-pill {
            font-size: 10px;
            padding: 1px 5px;
            border-radius: 4px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.5;
			min-width: 0;                /* ADD */
    		max-width: 100%;
        }

        .cell-event-pill.planting {
            background: var(--cat-planting-pale);
            color: var(--cat-planting);
        }

        .cell-event-pill.harvest {
            background: var(--cat-harvest-pale);
            color: var(--cat-harvest);
        }

        .cell-event-pill.irrigation {
            background: var(--cat-irrigation-pale);
            color: var(--cat-irrigation);
        }

        .cell-event-pill.pest {
            background: var(--cat-pest-pale);
            color: var(--cat-pest);
        }

        .cell-event-pill.fertilize {
            background: var(--cat-fertilize-pale);
            color: var(--cat-fertilize);
        }

        .cell-event-pill.other {
            background: var(--cat-other-pale);
            color: var(--cat-other);
        }

        .cell-more {
            font-size: 10px;
            color: var(--text-muted);
            padding-left: 5px;
            font-weight: 600;
        }

        /* ===== SIDE PANEL ===== */
        .side-panel {
            display: flex;
            flex-direction: column;
            gap: 12px;
            overflow: hidden;
            min-height: 0;
            overflow-y: auto;
        }

        .side-panel::-webkit-scrollbar {
            width: 3px;
        }

        .side-panel::-webkit-scrollbar-thumb {
            background: var(--cream-3);
        }

        .date-hero {
            background: linear-gradient(135deg, var(--sage-dark) 0%, var(--sage) 60%, var(--sage-light) 100%);
            border-radius: var(--radius);
            padding: 18px 20px;
            color: var(--white);
            flex-shrink: 0;
            position: relative;
            overflow: hidden;
        }

        .date-hero::before {
            content: '';
            position: absolute;
            top: -20px;
            right: -20px;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .07);
        }

        .hero-day {
            font-size: 12px;
            opacity: .8;
            font-weight: 600;
            letter-spacing: .5px;
            text-transform: uppercase;
        }

        .hero-date {
            font-family: 'Lora', serif;
            font-size: 20px;
            font-weight: 700;
            margin: 3px 0;
        }

        .hero-time {
            font-family: 'DM Mono', monospace;
            font-size: 13px;
            opacity: .85;
        }

        .hero-selected {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(255, 255, 255, .2);
            font-size: 12px;
            opacity: .9;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .hero-selected svg {
            width: 13px;
            height: 13px;
        }

        .event-form-card {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            padding: 16px 18px;
            flex-shrink: 0;
        }

        .form-card-title {
            font-family: 'Lora', serif;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-card-icon {
            width: 28px;
            height: 28px;
            background: var(--sage-pale);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 8px;
        }

        .form-field {
            margin-bottom: 8px;
        }

        .field-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-family: 'DM Sans', sans-serif;
            color: var(--text-primary);
            background: var(--cream);
            transition: border .2s, background .2s;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--sage-light);
            background: var(--white);
        }

        .form-input.error {
            border-color: var(--red);
        }

        .form-textarea {
            height: 52px;
            resize: none;
        }

        .cat-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 5px;
            margin-bottom: 10px;
			min-width: 0;
        }

        .cat-opt {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            padding: 9px 4px 7px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 10.5px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            transition: all .15s;
            color: var(--text-muted);
            background: var(--cream);
            font-family: 'DM Sans', sans-serif;
            min-width: 0;
            overflow: hidden;
        }

        .cat-opt svg {
            width: 17px;
            height: 17px;
            flex-shrink: 0;
            stroke: currentColor;
        }

        .cat-opt span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 100%;
        }

        .cat-opt:hover {
            border-color: var(--sage-light);
            transform: translateY(-1px);
        }

        .cat-opt.active-planting {
            background: var(--cat-planting-pale);
            border-color: var(--cat-planting);
            color: var(--cat-planting);
        }

        .cat-opt.active-harvest {
            background: var(--cat-harvest-pale);
            border-color: var(--cat-harvest);
            color: var(--cat-harvest);
        }

        .cat-opt.active-irrigation {
            background: var(--cat-irrigation-pale);
            border-color: var(--cat-irrigation);
            color: var(--cat-irrigation);
        }

        .cat-opt.active-pest {
            background: var(--cat-pest-pale);
            border-color: var(--cat-pest);
            color: var(--cat-pest);
        }

        .cat-opt.active-fertilize {
            background: var(--cat-fertilize-pale);
            border-color: var(--cat-fertilize);
            color: var(--cat-fertilize);
        }

        .cat-opt.active-other {
            background: var(--cat-other-pale);
            border-color: var(--cat-other);
            color: var(--cat-other);
        }
            
                .custom-select,
        .custom-time {
            position: relative;
        }

        .custom-select-btn {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 9px 12px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-family: 'DM Sans', sans-serif;
            color: var(--text-primary);
            background: var(--cream);
            cursor: pointer;
            transition: border .2s, background .2s;
            text-align: left;
        }

        .custom-select-btn:hover {
            border-color: var(--sage-light);
        }

        .custom-select.open .custom-select-btn,
        .custom-time.open .custom-select-btn {
            border-color: var(--sage-light);
            background: var(--white);
        }

                .cs-arrow,
        .cs-clock {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
            stroke: var(--text-muted);
            transition: transform .2s;
        }

        .custom-select.open .cs-arrow {
            transform: rotate(180deg);
        }

        .custom-select-drop {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            min-width: 100%;
            width: max-content;
            max-width: 220px;
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-md);
            z-index: 60;
            overflow: hidden;
            padding: 4px;
        }

        .custom-select.open .custom-select-drop {
            display: block;
        }

        .cs-drop-scroll {
            max-height: 220px;
            overflow-y: auto;
        }

        .cs-drop-scroll::-webkit-scrollbar {
            width: 4px;
        }

        .cs-drop-scroll::-webkit-scrollbar-thumb {
            background: var(--cream-3);
            border-radius: 2px;
        }

        .cs-opt {
            padding: 8px 10px;
            font-size: 12.5px;
            font-weight: 500;
            color: var(--text-secondary);
            border-radius: 7px;
            cursor: pointer;
            transition: background .15s, color .15s;
            white-space: nowrap;
        }

        .cs-opt:hover {
            background: var(--sage-pale);
            color: var(--sage-dark);
        }

        .cs-opt.active {
            background: var(--sage);
            color: var(--white);
            font-weight: 600;
        }

        .custom-time-drop {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            min-width: 100%;
            width: max-content;
            max-width: 160px;
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-md);
            z-index: 60;
            overflow: hidden;
            padding: 4px;
        }
        .custom-time.open .custom-time-drop {
            display: block;
        }
        .time-drop-scroll {
            max-height: 220px;
            overflow-y: auto;
        }
        .time-drop-scroll::-webkit-scrollbar { width: 4px; }
        .time-drop-scroll::-webkit-scrollbar-thumb { background: var(--cream-3); border-radius: 2px; }

        .form-actions {
            display: flex;
            gap: 8px;
        }


        .btn-save {
            flex: 1;
            padding: 10px;
            background: var(--sage);
            background: linear-gradient(135deg, var(--sage) 0%, var(--sage-dark) 100%);
   	 		box-shadow: 0 3px 10px rgba(90, 122, 82, .25);
            color: var(--white);
            border: none;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all .2s;
            font-family: 'DM Sans', sans-serif;
        }

        .btn-save:hover {
            background: var(--sage-dark);
        }

        .btn-save:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        .btn-save svg {
            width: 14px;
            height: 14px;
        }

        .btn-new-event {
            padding: 10px 12px;
            background: var(--cream-2);
            color: var(--text-secondary);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
            font-family: 'DM Sans', sans-serif;
            display: none;
        }

        .btn-new-event:hover {
            background: var(--sage-pale);
            border-color: var(--sage-light);
            color: var(--sage-dark);
        }

        .btn-del-event {
            padding: 10px 14px;
            background: var(--red-pale);
            color: var(--red);
            border: 1.5px solid #e8b4b0;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
            font-family: 'DM Sans', sans-serif;
            display: none;
        }

        .btn-del-event:hover {
            background: var(--red);
            color: var(--white);
            border-color: var(--red);
        }

        .events-card {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            padding: 16px 18px;
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-height: 120px;
        }

        .events-card-title {
            font-family: 'Lora', serif;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .events-count {
            font-size: 11px;
            background: var(--sage);
            color: var(--white);
            padding: 2px 8px;
            border-radius: 10px;
            font-family: 'DM Mono', monospace;
            font-weight: 500;
        }

        .events-list {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .events-list::-webkit-scrollbar {
            width: 3px;
        }

        .events-list::-webkit-scrollbar-thumb {
            background: var(--cream-3);
            border-radius: 2px;
        }

        .event-item {
            padding: 10px 12px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all .2s;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            border: 1px solid var(--border-light);
        }

        .event-item:hover {
            box-shadow: var(--shadow-sm);
            transform: translateX(3px);
            border-color: var(--sage-light);
        }

        .event-item.editing {
            border-color: var(--sage-light);
            background: var(--sage-pale);
        }

        .event-cat-bar {
            width: 3px;
            border-radius: 2px;
            align-self: stretch;
            flex-shrink: 0;
            min-height: 32px;
        }

        .event-cat-bar.planting {
            background: var(--cat-planting);
        }

        .event-cat-bar.harvest {
            background: var(--cat-harvest);
        }

        .event-cat-bar.irrigation {
            background: var(--cat-irrigation);
        }

        .event-cat-bar.pest {
            background: var(--cat-pest);
        }

        .event-cat-bar.fertilize {
            background: var(--cat-fertilize);
        }

        .event-cat-bar.other {
            background: var(--cat-other);
        }

        .event-info {
            flex: 1;
            min-width: 0;
        }

        .event-item-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .event-item-meta {
            font-size: 11.5px;
            color: var(--text-muted);
            margin-top: 2px;
            font-family: 'DM Mono', monospace;
        }

        .event-cat-pill {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 8px;
            flex-shrink: 0;
        }

        .no-events {
            font-size: 13px;
            color: var(--text-muted);
            text-align: center;
            padding: 20px;
            font-style: italic;
        }

        /* ===== MODALS ===== */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            inset: 0;
            background: rgba(30, 42, 26, .4);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .modal.show {
            display: flex;
            animation: fadeIn .2s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0
            }

            to {
                opacity: 1
            }
        }

        .modal-box {
            background: var(--white);
            border-radius: var(--radius);
            width: 100%;
            max-width: 420px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-lg);
            animation: slideUp .22s ease;
            overflow: hidden;
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0
            }

            to {
                transform: translateY(0);
                opacity: 1
            }
        }

        .modal-head {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-head-title {
            font-family: 'Lora', serif;
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .modal-close {
            width: 28px;
            height: 28px;
            background: var(--cream-2);
            border: none;
            border-radius: 7px;
            font-size: 16px;
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all .2s;
        }

        .modal-close:hover {
            background: var(--cream-3);
        }

        .modal-bod {
            padding: 20px 22px;
        }

        .modal-icon-big {
            display: flex;
            justify-content: center;
            margin-bottom: 10px;
        }

        .modal-text {
            font-size: 13.5px;
            color: var(--text-secondary);
            text-align: center;
            line-height: 1.6;
            margin-bottom: 14px;
        }

        .modal-details-box {
            background: var(--cream);
            border-radius: var(--radius-sm);
            padding: 12px 14px;
            border: 1px solid var(--border-light);
        }

        .modal-detail-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 4px 0;
            border-bottom: 1px solid var(--border-light);
        }

        .modal-detail-row:last-child {
            border-bottom: none;
        }

        .modal-detail-label {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .modal-detail-value {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            text-align: right;
            max-width: 220px;
        }

        .modal-foot {
            padding: 14px 22px 18px;
            border-top: 1px solid var(--border-light);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }

        .mbtn {
            padding: 9px 20px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
            font-family: 'DM Sans', sans-serif;
        }

        .mbtn-cancel {
            background: var(--cream-2);
            color: var(--text-secondary);
        }

        .mbtn-cancel:hover {
            background: var(--cream-3);
        }

        .mbtn-confirm {
            background: var(--sage);
            color: var(--white);
        }

        .mbtn-confirm:hover {
            background: var(--sage-dark);
        }

        .mbtn-delete {
            background: var(--red);
            color: var(--white);
        }

        .mbtn-delete:hover {
            background: #a93226;
        }

        /* ===== TOAST ===== */
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
            box-shadow: var(--shadow-lg);
        }
            
        .toast-icon svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }

        .toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0)
            }

            20%,
            60% {
                transform: translateX(-4px)
            }

            40%,
            80% {
                transform: translateX(4px)
            }
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 900px) {
            .calendar-content {
                grid-template-columns: 1fr;
                grid-template-rows: auto auto;
                overflow: visible;
                height: auto;
            }

            .side-panel {
                overflow: visible;
                min-height: unset;
            }

            .events-card {
                min-height: unset;
            }
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

            body.sidebar-collapsed .logo-wrap,
                body.sidebar-collapsed .logo-text,
                body.sidebar-collapsed .nav-label,
                body.sidebar-collapsed .nav-text,
                body.sidebar-collapsed .signout-text {
                    display: none;
                }

                body.sidebar-collapsed .expand-btn {
                    display: none;
                }

                body.sidebar-collapsed .sidebar-top {
                    padding: 16px 0;
                    justify-content: center;
                }

                body.sidebar-collapsed .toggle-btn {
                    margin-left: 0;
                }

                body.sidebar-collapsed .nav-section {
                    padding: 10px 0;
                    align-items: center;
                    display: flex;
                    flex-direction: column;
                    gap: 2px;
                }

                body.sidebar-collapsed .nav-item {
                    width: 44px;
                    height: 44px;
                    justify-content: center;
                    padding: 0;
                    border-radius: var(--radius-sm);
                    gap: 0;
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
                position: sticky;
                top: 0;
                z-index: 100;
                padding: 0 16px;
            }

            .hamburger-btn {
                display: flex !important;
            }

            .profile-pill-name {
                display: none;
            }

            .calendar-content {
                padding: 12px 14px 20px;
                gap: 12px;
                overflow: visible !important;
                height: auto !important;
            }

            .calendar-container {
                overflow: visible;
                padding: 16px 14px 12px;
            }

            .calendar-grid {
                gap: 2px;
            }

            .day-cell {
                padding: 4px 3px;
            }

            .day-num {
                width: 22px;
                height: 22px;
                font-size: 11px;
            }

            .cell-event-pill {
                font-size: 9px;
            }

            .cal-month-title {
                font-size: 18px;
            }

            .month-select {
                min-width: 90px;
                font-size: 12px;
            }

            .year-select {
                min-width: 60px;
                font-size: 12px;
            }

            .notif-drop {
                right: 10px;
                left: 10px;
                width: auto;
            }

            .profile-drop {
                right: 10px;
            }

            body.nav-open {
                overflow: hidden !important;
            }
        }

        @media (max-width: 480px) {
            .cal-nav-group {
                gap: 5px;
            }

            .today-btn {
                padding: 5px 10px;
                font-size: 11.5px;
            }

            .day-header {
                font-size: 9px;
                letter-spacing: 0;
            }

            .day-num {
                width: 20px;
                height: 20px;
                font-size: 10px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .cat-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-top">
            <div class="logo-wrap" onclick="toggleSidebar()" title="Toggle sidebar">
                <div class="logo-icon">
                    <img src="pictures/RiceWise_Logo.png" alt=""
                        onerror="this.style.display='none';this.parentElement.innerHTML='<svg viewBox=&quot;0 0 24 24&quot; fill=&quot;none&quot; stroke=&quot;currentColor&quot; stroke-width=&quot;2&quot; stroke-linecap=&quot;round&quot; stroke-linejoin=&quot;round&quot; style=&quot;width:20px;height:20px;color:var(--sage)&quot;><path d=&quot;M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z&quot;/><path d=&quot;M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 11 13 11 11&quot;/></svg>'">
                </div>
                <span class="logo-text">RiceWise</span>
            </div>
            <button class="toggle-btn" onclick="toggleSidebar()" title="Collapse">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <rect x="3" y="3" width="18" height="18" rx="2" />
                    <path d="M9 3v18" />
                </svg>
            </button>
            <button class="expand-btn" onclick="toggleSidebar()" title="Expand">
            	<img src="pictures/RiceWise_Logo.png" alt="RiceWise" class="expand-btn-logo"
                	onerror="this.style.display='none';this.parentElement.textContent='🌾'">
            	<svg class="expand-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                	stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                	<polyline points="9 18 15 12 9 6"></polyline>
            	</svg>
        	</button>
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

                    <a href="calendar.php" class="nav-item active" data-tooltip="Calendar"><svg viewBox="0 0 24 24">
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

    <!-- MAIN -->
    <div class="main-content">
        <header class="header">
            <div class="header-left">
                                <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="Open menu">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <rect x="3" y="3" width="18" height="18" rx="2" />
                        <path d="M9 3v18" />
                    </svg>
                </button>
                Calendar
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
                    <div class="profile-avatar">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                    </div>
                    <svg class="down-arrow" viewBox="0 0 24 24" fill="none" stroke-width="2">
                        <polyline points="6 9 12 15 18 9" />
                    </svg>
                </div>
            </div>
        </header>

        <!-- Notification dropdown -->
        <div class="notif-drop" id="notifDrop">
            <div class="nd-head"><span class="nd-title">Notifications</span><button class="mark-read-btn"
                    onclick="markAllRead()">Mark all read</button></div>
            <div class="nd-list" id="ndList"></div>
            <div class="nd-foot"><a href="notifications.php">View all →</a></div>
        </div>

        <!-- Profile dropdown -->
        <div class="profile-drop" id="profileDrop">
            <h3 id="profileName"><?= htmlspecialchars($user['name']) ?></h3>
            <p id="profileEmail"><?= htmlspecialchars($user['email']) ?></p>
            <hr>
            <a href="profile.php" class="pd-btn">My Profile</a>
            <a href="logout.php" class="pd-btn">Sign out</a>
        </div>

        <div class="calendar-content">
            <div class="calendar-container">
                <div class="calendar-header">
                    <div class="cal-title-group">
                        <div class="cal-month-title" id="calMonthTitle">February 2026</div>
                        <div class="cal-sub" id="calSub">0 events this month</div>
                    </div>
                    <div class="cal-nav-group">
                        <button class="nav-btn" id="prevBtn">&#8249;</button>
                        <div class="month-year-selects">
                            <select class="month-select" id="monthSelect">
                                <option value="0">January</option>
                                <option value="1">February</option>
                                <option value="2">March</option>
                                <option value="3">April</option>
                                <option value="4">May</option>
                                <option value="5">June</option>
                                <option value="6">July</option>
                                <option value="7">August</option>
                                <option value="8">September</option>
                                <option value="9">October</option>
                                <option value="10">November</option>
                                <option value="11">December</option>
                            </select>
                            <select class="year-select" id="yearSelect"></select>
                        </div>
                        <button class="nav-btn" id="nextBtn">&#8250;</button>
                        <button class="today-btn" id="todayBtn">Today</button>
                    </div>
                </div>
                <div class="day-headers">
                    <div class="day-header sun">Sun</div>
                    <div class="day-header">Mon</div>
                    <div class="day-header">Tue</div>
                    <div class="day-header">Wed</div>
                    <div class="day-header">Thu</div>
                    <div class="day-header">Fri</div>
                    <div class="day-header sat">Sat</div>
                </div>
                <div class="calendar-grid" id="calendarGrid"></div>
            </div>

            <div class="side-panel">
                <div class="date-hero">
                    <div class="hero-day" id="heroDay">Thursday</div>
                    <div class="hero-date" id="heroDate">February 26, 2026</div>
                    <div class="hero-time" id="heroTime">12:00 AM</div>
                    <div class="hero-selected" id="heroSelected">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" />
                            <line x1="16" y1="2" x2="16" y2="6" />
                            <line x1="8" y1="2" x2="8" y2="6" />
                            <line x1="3" y1="10" x2="21" y2="10" />
                        </svg>
                        <span>Viewing: February 26, 2026</span>
                    </div>
                </div>

                <div class="event-form-card">
                    <div class="form-card-title">
                        <div class="form-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="var(--sage-dark)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></div>Add / Edit Event
                    </div>
                    <div class="form-field">
                        <div class="field-label">Event Title *</div>
                        <input type="text" class="form-input" id="eventTitle"
                            placeholder="e.g., Start Planting Field A-1">
                    </div>
                    <div class="form-field">
                        <div class="field-label">Category</div>
                        <div class="cat-grid" id="catGrid">
                            <button class="cat-opt" data-cat="planting">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 11 13 11 11"/></svg>
                                <span>Planting</span>
                            </button>
                            <button class="cat-opt" data-cat="harvest">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 22 16 8"/><path d="M3.47 12.53 5 11l1.53 1.53a3.5 3.5 0 0 1 0 4.94L5 19l-1.53-1.53a3.5 3.5 0 0 1 0-4.94Z"/><path d="M7.47 8.53 9 7l1.53 1.53a3.5 3.5 0 0 1 0 4.94L9 15l-1.53-1.53a3.5 3.5 0 0 1 0-4.94Z"/></svg>
                                <span>Harvest</span>
                            </button>
                            <button class="cat-opt" data-cat="irrigation">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>
                                <span>Irrigation</span>
                            </button>
                            <button class="cat-opt" data-cat="pest">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="6" width="8" height="14" rx="4"/><path d="M8 12H2"/><path d="M22 12h-6"/><path d="M8 8 4 4"/><path d="M16 8l4-4"/><path d="M8 16l-4 4"/><path d="M16 16l4 4"/><path d="M12 6V2"/></svg>
                                <span>Pest Control</span>
                            </button>
                            <button class="cat-opt" data-cat="fertilize">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2v6.5L4.5 18a2 2 0 0 0 1.8 3h11.4a2 2 0 0 0 1.8-3L15 8.5V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/></svg>
                                <span>Fertilize</span>
                            </button>
                            <button class="cat-opt" data-cat="other">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7Z"/><circle cx="12" cy="9" r="2.5"/></svg>
                                <span>Other</span>
                            </button>
                        </div>
                    </div>
                    <div class="form-field">
                        <div class="field-label">Note (optional)</div>
                        <textarea class="form-textarea" id="eventDesc"
                            placeholder="Add a description or reminder note..."></textarea>
                    </div>
                    <div class="form-row">
                        <div>
                        <div>
                            <div class="field-label">Time</div>
                            <div class="custom-time" id="timeWrap">
                                <input type="hidden" id="eventTime" value="08:00">
                                <button type="button" class="custom-select-btn" id="timeBtn">
                                    <span id="timeBtnText">08:00 AM</span>
                                    <svg class="cs-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/></svg>
                                </button>
                                <div class="custom-time-drop" id="timeDrop">
                                    <div class="time-drop-scroll" id="timeDropScroll"></div>
                                </div>
                                </div>
                        </div>
                        </div>
                        <div>
                            <div class="field-label">Remind</div>
                            <div class="custom-select" id="reminderSelectWrap">
                                <input type="hidden" id="reminderType" value="same-day">
                                <button type="button" class="custom-select-btn" id="reminderBtn">
                                    <span id="reminderBtnText">Same Day</span>
                                    <svg class="cs-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                                </button>
                                <div class="custom-select-drop" id="reminderDrop">
                                    <div class="cs-opt active" data-value="same-day">Same Day</div>
                                    <div class="cs-opt" data-value="1-day">1 Day Before</div>
                                    <div class="cs-opt" data-value="1-week">1 Week Before</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn-save" id="saveBtn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <polyline points="20 6 9 17 4 12" />
                            </svg>Save Event
                        </button>
                        <button class="btn-new-event" id="newEventBtn" title="Add another event on this date">+
                            New</button>
                        <button class="btn-del-event" id="delEventBtn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                        </button>
                    </div>
                </div>

                <div class="events-card">
                    <div class="events-card-title">Upcoming Events<span class="events-count" id="eventsCount">0</span>
                    </div>
                    <div class="events-list" id="upcomingList"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODALS -->
    <div id="saveModal" class="modal">
        <div class="modal-box">
            <div class="modal-head"><span class="modal-head-title">Confirm Event</span><button class="modal-close"
                    onclick="closeModal('saveModal')">×</button></div>
            <div class="modal-bod">
                <div class="modal-icon-big"><svg viewBox="0 0 24 24" fill="none" stroke="var(--sage)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="width:38px;height:38px"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 11 13 11 11"/></svg></div>
                <p class="modal-text">Save this event to your farm calendar?</p>
                <div class="modal-details-box" id="saveDetails"></div>
            </div>
            <div class="modal-foot"><button class="mbtn mbtn-cancel"
                    onclick="closeModal('saveModal')">Cancel</button><button class="mbtn mbtn-confirm"
                    id="confirmSaveBtn" onclick="confirmSave()">Save Event</button></div>
        </div>
    </div>

    <div id="deleteModal" class="modal">
        <div class="modal-box">
            <div class="modal-head"><span class="modal-head-title">Delete Event</span><button class="modal-close"
                    onclick="closeModal('deleteModal')">×</button></div>
            <div class="modal-bod">
                <div class="modal-icon-big"><svg viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="width:38px;height:38px"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div> be undone. The event will be permanently removed.</p>
                <div class="modal-details-box" id="deleteDetails"></div>
            </div>
            <div class="modal-foot"><button class="mbtn mbtn-cancel"
                    onclick="closeModal('deleteModal')">Cancel</button><button class="mbtn mbtn-delete"
                    id="confirmDeleteBtn" onclick="confirmDelete()">Delete Event</button></div>
        </div>
    </div>

    <div class="toast" id="toast"><span class="toast-icon" id="toastIcon"></span><span id="toastMsg">Event saved</span>
    </div>

    <script>
        const isMobile = () => window.innerWidth <= 768;

        // Restore collapsed state on load
        (function() {
            if (!isMobile() && localStorage.getItem('sidebarCollapsed') === '1') {
                document.body.classList.add('sidebar-collapsed');
            }
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

        /* ===== BELL DROPDOWN — live data, same pattern as dashboard.php / records.php / reports.php ===== */
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
                        <div class="nd-item-title">${parseInt(n.starred, 10) ? '<svg viewBox="0 0 24 24" fill="#e6a817" stroke="none" style="width:11px;height:11px;vertical-align:-1px;margin-right:3px"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>' : ''}${bellEscapeHtml(n.title)}</div>
                        <div class="nd-item-desc">${bellEscapeHtml(n.description || '')}</div>
                        <div class="nd-item-date">${n.notif_date || ''}</div>
                    </div>
                </div>
            `).join('');
        }

        function fetchBellNotifs() {
            return fetch('notif_list.php')
                .then(res => res.json())
                .then(json => {
                    if (json.success) {
                        _bellNotifs = json.notifications;
                        _bellLoaded = true;
                        renderBellNotifs();
                    }
                })
                .catch(() => {
                    const list = document.getElementById('ndList');
                    if (list && !_bellLoaded) {
                        list.innerHTML = '<div style="padding:24px 16px;text-align:center;font-size:12.5px;color:var(--text-muted);">Couldn\'t load notifications.</div>';
                    }
                });
        }

        function markAllRead() {
            fetch('notif_mark_all_read.php', {
                    method: 'POST'
                })
                .then(res => res.json())
                .then(json => {
                    if (json.success) {
                        _bellNotifs.forEach(n => n.status = 'read');
                        renderBellNotifs();
                    }
                })
                .catch(() => {});
        }

        const notifBtn = document.getElementById('notifBtn'),
            notifDrop = document.getElementById('notifDrop');
        const profileBtn = document.getElementById('profileBtn'),
            profileDrop = document.getElementById('profileDrop');

        notifBtn.addEventListener('click', e => {
            e.stopPropagation();
            const open = notifDrop.classList.toggle('show');
            notifBtn.classList.toggle('active', open);
            if (open) fetchBellNotifs();
            profileDrop.classList.remove('show');
            profileBtn.classList.remove('open');
        });
        profileBtn.addEventListener('click', e => {
            e.stopPropagation();
            const open = profileDrop.classList.toggle('show');
            profileBtn.classList.toggle('open', open);
            notifDrop.classList.remove('show');
            notifBtn.classList.remove('active');
        });
        document.addEventListener('click', () => {
            notifDrop.classList.remove('show');
            notifBtn.classList.remove('active');
            profileDrop.classList.remove('show');
            profileBtn.classList.remove('open');
        });

        /* ===== SERVER DATA ===== */
        // Real rows from calendar_events. Each has a real DB id, so multiple
        // events per day work correctly (the old localStorage version only
        // half-supported this — save always overwrote a single date slot).
        const PHP_EVENTS = <?= $jsEvents ?>;

        const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        const CAT_LABELS = {
            planting: 'Planting',
            harvest: 'Harvest',
            irrigation: 'Irrigation',
            pest: 'Pest Control',
            fertilize: 'Fertilize',
            other: 'Other'
        };
        const REMINDER_LABELS = {
            'same-day': 'Same Day',
            '1-day': '1 Day Before',
            '1-week': '1 Week Before'
        };

        // In-memory store keyed by id, mirroring the DB.
        let eventsById = {};
        PHP_EVENTS.forEach(e => {
            eventsById[e.id] = {
                    id: e.id,
                    title: e.title,
                    description: e.description || '',
                    date: e.event_date,
                    endDate: e.event_end_date || e.event_date,   // NEW
                    time: (e.event_time || '08:00:00').slice(0, 5),
                    category: e.category || 'other',
                    reminderType: e.reminder_type || 'same-day',
                    linked: e.cycle_id !== null                   // NEW: came from Planting Process
                };
        });

        function getKey(d) {
            return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        }

        function eventsForDate(dateKey) {
            return Object.values(eventsById)
                .filter(e => dateKey >= e.date && dateKey <= (e.endDate || e.date))
                .sort((a, b) => (a.time || '').localeCompare(b.time || ''));
        }

        let currentDate = new Date(),
            selectedDate = new Date();
        let selectedCat = 'other';
        let editingId = null; // null = creating a new event

        function renderCalendar() {
            const year = currentDate.getFullYear(),
                month = currentDate.getMonth();
            document.getElementById('calMonthTitle').textContent = `${MONTHS[month]} ${year}`;
            document.getElementById('monthSelect').value = month;
            document.getElementById('yearSelect').value = year;
            const monthEvents = Object.values(eventsById).filter(e => {
                const [ey, em] = e.date.split('-').map(Number);
                return ey === year && em === month + 1;
            }).length;
            document.getElementById('calSub').textContent = `${monthEvents} event${monthEvents !== 1 ? 's' : ''} this month`;
            const grid = document.getElementById('calendarGrid');
            grid.innerHTML = '';
            const firstDay = new Date(year, month, 1).getDay(),
                daysInMonth = new Date(year, month + 1, 0).getDate(),
                daysInPrev = new Date(year, month, 0).getDate();
            const today = new Date();
            for (let i = firstDay - 1; i >= 0; i--) grid.appendChild(makeCell(daysInPrev - i, [], true, year, month - 1));
            for (let d = 1; d <= daysInMonth; d++) {
                const cls = [],
                    dow = new Date(year, month, d).getDay();
                if (dow === 0) cls.push('sun');
                if (dow === 6) cls.push('sat');
                if (today.getFullYear() === year && today.getMonth() === month && today.getDate() === d) cls.push('today');
                if (selectedDate.getFullYear() === year && selectedDate.getMonth() === month && selectedDate.getDate() === d) cls.push('selected');
                const cellDate = new Date(year, month, d),
                    dayEvents = eventsForDate(getKey(cellDate));
                grid.appendChild(makeCell(d, cls, false, year, month, cellDate, dayEvents));
            }
            const total = firstDay + daysInMonth,
                rem = total % 7 === 0 ? 0 : 7 - (total % 7);
            for (let d = 1; d <= rem; d++) grid.appendChild(makeCell(d, [], true, year, month + 1));
            updateUpcoming();
        }

        function makeCell(day, classes, isOther, year, month, dateObj, dayEvents) {
            const cell = document.createElement('div');
            cell.className = 'day-cell ' + (isOther ? 'other-month ' : '') + classes.join(' ');
            const numEl = document.createElement('div');
            numEl.className = 'day-num';
            numEl.textContent = day;
            cell.appendChild(numEl);
            if (!isOther && dayEvents && dayEvents.length) {
                const evWrap = document.createElement('div');
                evWrap.className = 'cell-events';
                dayEvents.slice(0, 2).forEach(ev => {
                    const pill = document.createElement('div');
                    pill.className = 'cell-event-pill ' + (ev.category || 'other');
                    pill.textContent = ev.title;
                    pill.addEventListener('click', e => {
                        e.stopPropagation();
                        selectedDate = dateObj;
                        renderCalendar();
                        loadEventById(ev.id);
                        updateHeroSelected();
                    });
                    evWrap.appendChild(pill);
                });
                if (dayEvents.length > 2) {
                    const more = document.createElement('div');
                    more.className = 'cell-more';
                    more.textContent = `+${dayEvents.length - 2} more`;
                    evWrap.appendChild(more);
                }
                cell.appendChild(evWrap);
            }
            if (!isOther) {
                cell.addEventListener('click', () => {
                    selectedDate = dateObj;
                    renderCalendar();
                    const dayEvs = eventsForDate(getKey(dateObj));
                    if (dayEvs.length) loadEventById(dayEvs[0].id);
                    else loadBlankForm(dateObj);
                    updateHeroSelected();
                });
            }
            return cell;
        }

        function updateClock() {
            const now = new Date();
            document.getElementById('heroDay').textContent = now.toLocaleDateString('en-US', {
                weekday: 'long'
            });
            document.getElementById('heroDate').textContent = now.toLocaleDateString('en-US', {
                month: 'long',
                day: 'numeric',
                year: 'numeric'
            });
            document.getElementById('heroTime').textContent = now.toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            });
        }

        function updateHeroSelected() {
            document.getElementById('heroSelected').querySelector('span').textContent = `Viewing: ${selectedDate.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}`;
        }

                /* ===== CUSTOM TIME + REMINDER DROPDOWNS ===== */
        const reminderWrap = document.getElementById('reminderSelectWrap');
        const reminderBtn = document.getElementById('reminderBtn');
        const reminderBtnText = document.getElementById('reminderBtnText');
        const reminderDrop = document.getElementById('reminderDrop');
        const reminderInput = document.getElementById('reminderType');

        function setReminder(value) {
            reminderInput.value = value;
            reminderBtnText.textContent = REMINDER_LABELS[value] || 'Same Day';
            reminderDrop.querySelectorAll('.cs-opt').forEach(o => {
                o.classList.toggle('active', o.dataset.value === value);
            });
        }

        reminderBtn.addEventListener('click', e => {
            e.stopPropagation();
            timeWrap.classList.remove('open');
            reminderWrap.classList.toggle('open');
        });

        reminderDrop.addEventListener('click', e => {
            const opt = e.target.closest('.cs-opt');
            if (!opt) return;
            setReminder(opt.dataset.value);
            reminderWrap.classList.remove('open');
        });

                const timeWrap = document.getElementById('timeWrap');
        const timeBtn = document.getElementById('timeBtn');
        const timeBtnText = document.getElementById('timeBtnText');
        const timeInput = document.getElementById('eventTime');
        const timeDrop = document.getElementById('timeDrop');

        // Build a flat list of times every 15 min, e.g. "08:00" -> "8:00 AM"
        const TIME_OPTIONS = [];
        for (let m = 0; m < 24 * 60; m += 15) {
            const h24 = Math.floor(m / 60), min = m % 60;
            const h12 = h24 % 12 === 0 ? 12 : h24 % 12;
            const period = h24 < 12 ? 'AM' : 'PM';
            const value = `${String(h24).padStart(2, '0')}:${String(min).padStart(2, '0')}`;
            const label = `${h12}:${String(min).padStart(2, '0')} ${period}`;
            TIME_OPTIONS.push({ value, label });
        }
        const timeDropScroll = document.getElementById('timeDropScroll');
        TIME_OPTIONS.forEach(t => {
            const el = document.createElement('div');
            el.className = 'cs-opt';
            el.dataset.value = t.value;
            el.textContent = t.label;
            timeDropScroll.appendChild(el);
        });

        function setTimeFrom24(hhmm) {
            const value = hhmm || '08:00';
            const match = TIME_OPTIONS.find(t => t.value === value) || TIME_OPTIONS.find(t => t.value === '08:00');
            timeInput.value = match.value;
            timeBtnText.textContent = match.label;
            timeDrop.querySelectorAll('.cs-opt').forEach(o => o.classList.toggle('active', o.dataset.value === match.value));
        }

        timeBtn.addEventListener('click', e => {
            e.stopPropagation();
            reminderWrap.classList.remove('open');
            timeWrap.classList.toggle('open');
            if (timeWrap.classList.contains('open')) {
                const active = timeDrop.querySelector('.cs-opt.active');
                if (active) active.scrollIntoView({ block: 'center' });
            }
        });

        timeDrop.addEventListener('click', e => {
            const opt = e.target.closest('.cs-opt');
            if (!opt) return;
            setTimeFrom24(opt.dataset.value);
            timeWrap.classList.remove('open');
        });

        document.addEventListener('click', () => {
            reminderWrap.classList.remove('open');
            timeWrap.classList.remove('open');
        });

        setTimeFrom24('08:00');
        setReminder('same-day');

        document.getElementById('catGrid').addEventListener('click', e => {
            const btn = e.target.closest('.cat-opt');
            if (!btn) return;
            selectedCat = btn.dataset.cat;
            document.querySelectorAll('.cat-opt').forEach(b => b.className = 'cat-opt');
            btn.classList.add('active-' + selectedCat);
        });

        function setCat(cat) {
            selectedCat = cat || 'other';
            document.querySelectorAll('.cat-opt').forEach(b => b.className = 'cat-opt');
            const active = document.querySelector(`.cat-opt[data-cat="${selectedCat}"]`);
            if (active) active.classList.add('active-' + selectedCat);
        }

        function loadEventById(id) {
            const ev = eventsById[id];
            if (!ev) {
                loadBlankForm(selectedDate);
                return;
            }
            editingId = id;
            document.getElementById('eventTitle').value = ev.title || '';
            document.getElementById('eventDesc').value = ev.description || '';
            setTimeFrom24(ev.time || '08:00');
            setReminder(ev.reminderType || 'same-day');
            setCat(ev.category || 'other');
            document.getElementById('delEventBtn').style.display = 'inline-block';
            document.getElementById('newEventBtn').style.display = 'inline-block';
            const [y, m, d] = ev.date.split('-').map(Number);
            selectedDate = new Date(y, m - 1, d);
        }

        function loadBlankForm(date) {
            editingId = null;
            document.getElementById('eventTitle').value = '';
            document.getElementById('eventDesc').value = '';
            setTimeFrom24('08:00');
            setReminder('same-day');
            setCat('other');
            document.getElementById('delEventBtn').style.display = 'none';
            const hasOthers = eventsForDate(getKey(date)).length > 0;
            document.getElementById('newEventBtn').style.display = hasOthers ? 'inline-block' : 'none';
        }

        document.getElementById('newEventBtn').addEventListener('click', () => loadBlankForm(selectedDate));

        document.getElementById('saveBtn').addEventListener('click', () => {
            const title = document.getElementById('eventTitle').value.trim();
            if (!title) {
                const inp = document.getElementById('eventTitle');
                inp.classList.add('error');
                inp.style.animation = 'shake 0.4s';
                setTimeout(() => {
                    inp.style.animation = '';
                    inp.classList.remove('error');
                }, 500);
                inp.focus();
                return;
            }
            const time = document.getElementById('eventTime').value,
                rt = document.getElementById('reminderType').value;
            const dateStr = selectedDate.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            document.getElementById('saveDetails').innerHTML = `
                <div class="modal-detail-row"><span class="modal-detail-label">Event</span><span class="modal-detail-value">${title}</span></div>
                <div class="modal-detail-row"><span class="modal-detail-label">Category</span><span class="modal-detail-value">${CAT_LABELS[selectedCat]}</span></div>
                <div class="modal-detail-row"><span class="modal-detail-label">Date</span><span class="modal-detail-value">${dateStr}</span></div>
                <div class="modal-detail-row"><span class="modal-detail-label">Time</span><span class="modal-detail-value">${time}</span></div>
                <div class="modal-detail-row"><span class="modal-detail-label">Remind</span><span class="modal-detail-value">${REMINDER_LABELS[rt]}</span></div>`;
            document.getElementById('saveModal').classList.add('show');
        });

        function confirmSave() {
            const btn = document.getElementById('confirmSaveBtn');
            btn.disabled = true;
            btn.textContent = 'Saving…';
            const payload = {
                id: editingId,
                title: document.getElementById('eventTitle').value.trim(),
                description: document.getElementById('eventDesc').value.trim(),
                event_date: getKey(selectedDate),
                event_time: document.getElementById('eventTime').value,
                category: selectedCat,
                reminder_type: document.getElementById('reminderType').value
            };
            const url = editingId ? 'calendar_edit.php' : 'calendar_add.php';
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            }).then(res => res.json()).then(json => {
                btn.disabled = false;
                btn.textContent = 'Save Event';
                if (!json.success) {
                    showToast('warning', json.message || 'Failed to save event.');
                    return;
                }
                const id = editingId || json.id;
                eventsById[id] = {
                    id,
                    title: payload.title,
                    description: payload.description,
                    date: payload.event_date,
                    time: payload.event_time,
                    category: payload.category,
                    reminderType: payload.reminder_type
                };
                editingId = id;
                renderCalendar();
                loadEventById(id);
                closeModal('saveModal');
                showToast('success', 'Event saved successfully!');
            }).catch(() => {
                btn.disabled = false;
                btn.textContent = 'Save Event';
                showToast('warning', 'Network error — please try again.');
            });
        }

        document.getElementById('delEventBtn').addEventListener('click', () => {
            if (!editingId) return;
            const ev = eventsById[editingId];
            if (!ev) return;
            const [y, m, d] = ev.date.split('-').map(Number);
            const dateStr = new Date(y, m - 1, d).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            document.getElementById('deleteDetails').innerHTML = `<div class="modal-detail-row"><span class="modal-detail-label">Event</span><span class="modal-detail-value">${ev.title}</span></div><div class="modal-detail-row"><span class="modal-detail-label">Date</span><span class="modal-detail-value">${dateStr}</span></div>`;
            document.getElementById('deleteModal').classList.add('show');
        });

        function confirmDelete() {
            if (!editingId) return;
            const btn = document.getElementById('confirmDeleteBtn');
            btn.disabled = true;
            btn.textContent = 'Deleting…';
            fetch('calendar_delete.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    id: editingId
                })
            }).then(res => res.json()).then(json => {
                btn.disabled = false;
                btn.textContent = 'Delete Event';
                if (!json.success) {
                    showToast('warning', json.message || 'Failed to delete event.');
                    return;
                }
                delete eventsById[editingId];
                editingId = null;
                renderCalendar();
                loadBlankForm(selectedDate);
                closeModal('deleteModal');
                showToast('delete', 'Event deleted');
            }).catch(() => {
                btn.disabled = false;
                btn.textContent = 'Delete Event';
                showToast('⚠️', 'Network error — please try again.');
            });
        }

        function updateUpcoming() {
            const now = new Date();
            now.setHours(0, 0, 0, 0);
            const list = Object.values(eventsById).map(e => {
                const [y, m, d] = e.date.split('-').map(Number);
                return {
                    ...e,
                    _d: new Date(y, m - 1, d)
                };
            }).filter(e => e._d >= now).sort((a, b) => a._d - b._d).slice(0, 8);
            document.getElementById('eventsCount').textContent = list.length;
            const container = document.getElementById('upcomingList');
            if (!list.length) {
                container.innerHTML = '<div class="no-events">No upcoming events scheduled</div>';
                return;
            }
            container.innerHTML = list.map(e => {
                const catLabel = CAT_LABELS[e.category || 'other'],
                    dateStr = e._d.toLocaleDateString('en-US', {
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric'
                    });
                const isEditing = e.id === editingId;
                return `<div class="event-item ${isEditing ? 'editing' : ''}" data-id="${e.id}"><div class="event-cat-bar ${e.category || 'other'}"></div><div class="event-info"><div class="event-item-title">${e.title}</div><div class="event-item-meta">${dateStr} · ${e.time || '—'}</div></div><span class="event-cat-pill" style="background:var(--cat-${e.category || 'other'}-pale);color:var(--cat-${e.category || 'other'})">${catLabel}</span></div>`;
            }).join('');
            container.querySelectorAll('.event-item').forEach(el => {
                el.addEventListener('click', () => goToEvent(parseInt(el.dataset.id, 10)));
            });
        }

        function goToEvent(id) {
            const ev = eventsById[id];
            if (!ev) return;
            const [y, m, d] = ev.date.split('-').map(Number);
            currentDate = new Date(y, m - 1, 1);
            selectedDate = new Date(y, m - 1, d);
            syncSelects();
            renderCalendar();
            loadEventById(id);
            updateHeroSelected();
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }
        window.addEventListener('click', e => {
            if (e.target.classList.contains('modal')) e.target.classList.remove('show');
        });

        let toastTimer;

        const TOAST_ICONS = {
            success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="8 12 11 15 16 9"/></svg>',
            warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
            delete: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>'
        };

        function showToast(type, msg) {
            document.getElementById('toastIcon').innerHTML = TOAST_ICONS[type] || TOAST_ICONS.success;
            document.getElementById('toastMsg').textContent = msg;
            const t = document.getElementById('toast');
            t.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => t.classList.remove('show'), 2800);
        }

        function syncSelects() {
            document.getElementById('monthSelect').value = currentDate.getMonth();
            document.getElementById('yearSelect').value = currentDate.getFullYear();
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (localStorage.getItem('sidebarCollapsed') === '1' && !isMobile()) {
                document.body.classList.add('sidebar-collapsed');
            }
            const yearSelect = document.getElementById('yearSelect');
            for (let y = 2020; y <= 2032; y++) {
                const opt = document.createElement('option');
                opt.value = y;
                opt.textContent = y;
                if (y === currentDate.getFullYear()) opt.selected = true;
                yearSelect.appendChild(opt);
            }
            document.getElementById('monthSelect').value = currentDate.getMonth();
            document.getElementById('prevBtn').addEventListener('click', () => {
                currentDate.setMonth(currentDate.getMonth() - 1);
                syncSelects();
                renderCalendar();
            });
            document.getElementById('nextBtn').addEventListener('click', () => {
                currentDate.setMonth(currentDate.getMonth() + 1);
                syncSelects();
                renderCalendar();
            });
            document.getElementById('todayBtn').addEventListener('click', () => {
                currentDate = new Date();
                selectedDate = new Date();
                syncSelects();
                renderCalendar();
                loadBlankForm(selectedDate);
                updateHeroSelected();
            });
            document.getElementById('monthSelect').addEventListener('change', e => {
                currentDate.setMonth(parseInt(e.target.value));
                renderCalendar();
            });
            document.getElementById('yearSelect').addEventListener('change', e => {
                currentDate.setFullYear(parseInt(e.target.value));
                renderCalendar();
            });
            setCat('other');
            renderCalendar();
            const initialEvs = eventsForDate(getKey(selectedDate));
            if (initialEvs.length) loadEventById(initialEvs[0].id);
            else loadBlankForm(selectedDate);
            updateHeroSelected();
            updateClock();
            setInterval(updateClock, 1000);
        });
    </script>
</body>

</html>