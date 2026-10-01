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

// Fetch user (for header name/email — same pattern as dashboard.php)
$stmt = $conn->prepare("SELECT id,name,email,location,username,profile_pic FROM users WHERE id=? LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) {
    header('Location: logout.php');
    exit;
}
$_SESSION['rw_user_name'] = $user['name'];

// Fetch all farm records for this user
$rs = $conn->prepare("SELECT id, activity, crop_type, location, quantity, unit, status, record_date FROM farm_records WHERE user_id=? ORDER BY record_date DESC, id DESC");
$rs->bind_param('i', $uid);
$rs->execute();
$records = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
$rs->close();

$jsRecords = json_encode($records);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RiceWise - Records</title>
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
            --sky: #4a90d9;
            --sky-pale: #eaf3fc;
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
            --gap: 16px;
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
            font-size: 15px;
            display: flex;
            height: 100vh;
            overflow: hidden
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
            transition: width .3s cubic-bezier(.4, 0, .2, 1), transform .3s cubic-bezier(.4, 0, .2, 1)
        }
            
        .sidebar-top {
            height: var(--header-h);        /* ADD — locks to the same 64px as .header */
            padding: 0 16px 0 8px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            flex-shrink: 0;
            transition: padding .3s cubic-bezier(.4, 0, .2, 1);
        }

        body.sidebar-collapsed .sidebar {
            width: 68px
        }

        body.sidebar-collapsed .expand-btn {
            display: none
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
            padding: 0;
            border-radius: var(--radius-sm);
            gap: 0
        }

        body.sidebar-collapsed .nav-item.active::before {
            display: none
        }

        body.sidebar-collapsed .nav-item svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0
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
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 0;
            border-radius: var(--radius-sm);
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

        .logo-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            min-width: 0;
            flex: 1;
            text-decoration: none;
            opacity: 1;
            transform: translateX(0);
            max-width: 300px;
            overflow: hidden;
            transition: opacity .22s ease, transform .22s ease, max-width .22s ease
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
            overflow: hidden
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
            margin-left: auto
        }

        .toggle-btn:hover {
            background: var(--sage-pale)
        }

        .toggle-btn svg {
            width: 18px;
            height: 18px;
            stroke: var(--text-muted)
        }

        body.sidebar-collapsed .toggle-btn {
            margin-left: 0
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
            overflow: hidden;
            opacity: 1;
            transform: translateX(0);
            max-height: 20px;
            transition: opacity .22s ease, transform .22s ease, max-height .22s ease, margin .22s ease
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
            background: #fcfcfb;
            box-shadow: inset 0 0 0 1px var(--sage-pale);
            color: var(--sage-dark);
            font-weight: 600
        }

        .nav-item.active {
            background: var(--sage-pale);
            color: var(--sage-dark);
            font-weight: 600
        }
          
        .nav-item.active:hover {
            background: var(--sage-pale);
            box-shadow: inset 0 0 0 1px var(--sage-light);
            color: var(--sage-dark)
        }

        .nav-item svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0
        }

        .nav-item svg .icon-filled {
            display: none
        }

        .nav-item.active svg .icon-outline {
            display: none
        }

        .nav-item.active svg .icon-filled {
            display: block
        }

        .sidebar-bottom {
            padding: 10px 12px 16px;
            border-top: 1px solid var(--border);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            transition: padding .3s cubic-bezier(.4, 0, .2, 1)
        }

        .signout-btn {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 11px;
            width: 100%;
            padding: 10px;
            background: transparent;
            color: var(--text-secondary);
            border: none;
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
            background: #fcfcfb;
            box-shadow: inset 0 0 0 1px var(--sage-pale);
            color: var(--sage-dark);
            font-weight: 600
        }

        body.sidebar-collapsed .logo-wrap,
        body.sidebar-collapsed .nav-label,
        body.sidebar-collapsed .nav-text,
        body.sidebar-collapsed .signout-text {
            opacity: 0;
            transform: translateX(10px);
            max-width: 0;
            pointer-events: none;
            transition: opacity .18s ease, transform .18s ease, max-width .18s ease
        }

        body.sidebar-collapsed .nav-label {
            margin-top: 0;
            margin-bottom: 0;
            max-height: 0;
            transition: opacity .18s ease, transform .18s ease, max-width .18s ease, margin .18s ease, max-height .18s ease
        }

        .logo-wrap,
        .nav-text,
        .signout-text {
            opacity: 1;
            transform: translateX(0);
            max-width: 300px;
            overflow: hidden;
            transition: opacity .22s ease, transform .22s ease, max-width .22s ease
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
            position: sticky;
            top: 0
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
            background: none;
            border: none;
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
            text-decoration: none;
            transition: background .2s;
            cursor: pointer;
            border: none;
            background: transparent
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
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--cream-3) transparent
        }

        .nd-list::-webkit-scrollbar {
            width: 4px
        }
        .nd-list::-webkit-scrollbar-track {
            background: transparent
        }
        .nd-list::-webkit-scrollbar-thumb {
            background: var(--cream-3);
            border-radius: 2px
        }
        .nd-list::-webkit-scrollbar-thumb:hover {
            background: var(--sage-light)
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
            width: 14px !important;
            height: 14px !important;
            stroke: var(--sage)
        }

        .nd-icon.nd-ic-plant    { background: #e8f5e2 }
        .nd-icon.nd-ic-plant    svg { stroke: #3d7a32 }
        .nd-icon.nd-ic-weather  { background: #e3eef9 }
        .nd-icon.nd-ic-weather  svg { stroke: #2471a3 }
        .nd-icon.nd-ic-reminder { background: #fef9ec }
        .nd-icon.nd-ic-reminder svg { stroke: #d68910 }
        .nd-icon.nd-ic-harvest  { background: #fdf0e0 }
        .nd-icon.nd-ic-harvest  svg { stroke: #c47d1a }
        .nd-icon.nd-ic-pest     { background: #fdecea }
        .nd-icon.nd-ic-pest     svg { stroke: #c0392b }
        .nd-icon.nd-ic-water    { background: #e8f5fd }
        .nd-icon.nd-ic-water    svg { stroke: #1a8ab5 }

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

        .page-scroll {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 20px 24px 28px;
            display: flex;
            flex-direction: column;
            gap: var(--gap)
        }

        .page-scroll::-webkit-scrollbar {
            width: 4px
        }

        .page-scroll::-webkit-scrollbar-thumb {
            background: var(--cream-3);
            border-radius: 2px
        }
            
        .records-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: nowrap;
            gap: 10px;
            flex-shrink: 0
        }

        .records-title {
            font-family: 'Lora', serif;
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .header-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap
        }

        .header-btn {
            padding: 9px 16px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 7px;
            transition: all .2s;
            font-family: 'DM Sans', sans-serif
        }

        .header-btn:disabled {
            opacity: .4;
            cursor: not-allowed
        }

        .header-btn svg {
            width: 15px;
            height: 15px
        }
            
        .action-group {
            display: flex;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            overflow: hidden
        }

        .action-group .header-btn {
            border-radius: 0;
            border-right: 1px solid var(--border-light);
            background: transparent
        }

        .action-group .header-btn:last-child {
            border-right: none
        }

        .action-group .header-btn.btn-view:hover:not(:disabled) { background: var(--blue-pale) }
        .action-group .header-btn.btn-edit:hover:not(:disabled) { background: var(--amber-pale) }
        .action-group .header-btn.btn-delete:hover:not(:disabled) { background: var(--red-pale) }

        .btn-view {
            background: var(--blue-pale);
            color: var(--blue)
        }

        .btn-view:hover:not(:disabled) {
            background: #d5eaf7
        }

        .btn-edit {
            background: var(--amber-pale);
            color: var(--amber)
        }

        .btn-edit:hover:not(:disabled) {
            background: #faedc5
        }

        .btn-delete {
            background: var(--red-pale);
            color: var(--red)
        }

        .btn-delete:hover:not(:disabled) {
            background: #fad7d4
        }

        .btn-add {
            background: var(--sage);
            color: var(--white)
        }

        .btn-add:hover {
            background: var(--sage-dark)
        }

        .btn-export {
            background: var(--gold-pale);
            border: 1px solid var(--gold);
            color: var(--gold)
        }
        .btn-export:hover {
            background: #f5e6c4
        }

        .search-filter-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            flex-shrink: 0
            /* REMOVE margin-left: auto — space-between on the parent now handles this */
		}

        .search-box {
            flex: 1;
            min-width: 200px;
            max-width: 280px;   /* ADD — optional, keeps it from growing too wide */
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 10px 14px 10px 40px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 13.5px;
            background: var(--white);
            color: var(--text-primary);
            font-family: 'DM Sans', sans-serif;
            transition: border .2s
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--sage-light)
        }

        .search-box input::placeholder {
            color: var(--text-muted)
        }

        .search-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            width: 16px;
            height: 16px
        }

        .filter-wrap {
            position: relative
        }

        .filter-btn {
            padding: 10px 16px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 13.5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 7px;
            transition: all .2s;
            color: var(--text-secondary);
            font-family: 'DM Sans', sans-serif
        }

        .filter-btn:hover {
            border-color: var(--sage-light);
            color: var(--sage-dark)
        }

        .filter-btn svg {
            width: 15px;
            height: 15px
        }

        .filter-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            box-shadow: var(--shadow-md);
            min-width: 210px;
            display: none;
            z-index: 1000;
            overflow: hidden
        }

        .filter-dropdown.show {
            display: block;
            animation: dropIn .15s ease
        }

        @keyframes dropIn {
            from {
                opacity: 0;
                transform: translateY(-6px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .filter-section-label {
            padding: 10px 14px 6px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--text-muted)
        }

        .filter-option {
            padding: 9px 14px;
            font-size: 13px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: background .15s;
            display: flex;
            align-items: center;
            gap: 8px
        }

        .filter-option:hover {
            background: var(--cream);
            color: var(--sage-dark)
        }

        .filter-option.active {
            background: var(--sage-pale);
            color: var(--sage-dark);
            font-weight: 600
        }

        .filter-option svg {
            width: 14px;
            height: 14px;
            opacity: .5
        }

        .filter-option.active svg {
            opacity: 1
        }

        .filter-divider {
            height: 1px;
            background: var(--border-light);
            margin: 4px 0
        }

        .filter-footer {
            padding: 10px 12px;
            border-top: 1px solid var(--border-light);
            display: flex;
            gap: 8px
        }

        .filter-clear-btn {
            flex: 1;
            padding: 7px;
            background: var(--cream-2);
            border: none;
            border-radius: var(--radius-sm);
            font-size: 12.5px;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            color: var(--text-secondary);
            transition: background .2s
        }

        .filter-clear-btn:hover {
            background: var(--cream-3)
        }

        .filter-apply-btn {
            flex: 1;
            padding: 7px;
            background: var(--sage);
            color: var(--white);
            border: none;
            border-radius: var(--radius-sm);
            font-size: 12.5px;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            font-weight: 600;
            transition: background .2s
        }

        .filter-apply-btn:hover {
            background: var(--sage-dark)
        }

        .table-card {
            width: 100%;
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            flex-shrink: 0
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto
        }

        .records-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 520px
        }

        .records-table thead {
            background: var(--cream-2)
        }

        .records-table th {
            padding: 12px 20px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .8px;
            white-space: nowrap
        }

        .records-table td {
            padding: 14px 20px;
            border-top: 1px solid var(--border-light);
            font-size: 13.5px;
            color: var(--text-primary)
        }

        .records-table tbody tr {
            transition: background .15s;
            cursor: pointer
        }

        .records-table tbody tr:hover {
            background: var(--cream)
        }

        .records-table tbody tr.selected {
            background: var(--sage-pale);
            box-shadow: inset 3px 0 0 var(--sage)
        }

        .value-cell {
            display: flex;
            align-items: center;
            gap: 6px
        }
            
        .activity-cell {
            display: flex;
            align-items: center;
            gap: 9px
        }

        .activity-icon-badge {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0
        }

        .activity-icon-badge svg {
            width: 14px;
            height: 14px
        }

        .unit-tag {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .4px;
            padding: 2px 7px;
            border-radius: 5px;
            white-space: nowrap;
            flex-shrink: 0
        }

        .unit-tag.peso {
            background: var(--gold-pale);
            color: var(--gold)
        }

        .unit-tag.kg {
            background: var(--sage-pale);
            color: var(--sage-dark)
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 11px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 600;
            white-space: nowrap
        }

        .status-badge::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor
        }

        .badge-completed {
            background: #e4f5e4;
            color: #2d6a2d
        }

        .badge-pending {
            background: var(--amber-pale);
            color: var(--amber)
        }

        .badge-in-progress {
            background: var(--blue-pale);
            color: var(--blue)
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
            padding: 16px 20px;
            border-top: 1px solid var(--border-light);
            flex-wrap: wrap
        }

        .page-btn {
            padding: 6px 12px;
            border: 1px solid var(--border);
            background: var(--white);
            border-radius: var(--radius-sm);
            font-size: 13px;
            cursor: pointer;
            color: var(--text-secondary);
            transition: all .2s;
            font-family: 'DM Sans', sans-serif
        }

        .page-btn:hover {
            border-color: var(--sage-light);
            color: var(--sage-dark)
        }

        .page-btn.active {
            background: var(--sage);
            color: var(--white);
            border-color: var(--sage);
            font-weight: 600
        }

        .empty-state {
            padding: 50px 20px;
            text-align: center;
            color: var(--text-muted);
            font-size: 14px;
            display: flex;
            flex-direction: column;
            align-items: center
        }

        /* ═══════════════════════════════════════════════
           MODAL STYLES
        ═══════════════════════════════════════════════ */

        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            inset: 0;
            background: rgba(15, 25, 13, 0.55);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
            padding: 16px
        }

        .modal.show {
            display: flex;
            animation: modalBgIn .25s ease
        }

        @keyframes modalBgIn {
            from {
                opacity: 0
            }

            to {
                opacity: 1
            }
        }

        .modal-content {
            background: var(--white);
            border-radius: 20px;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            border: 1px solid rgba(255, 255, 255, 0.8);
            box-shadow:
                0 2px 4px rgba(0, 0, 0, .04),
                0 8px 24px rgba(30, 42, 26, .12),
                0 32px 64px rgba(30, 42, 26, .18);
            animation: modalSlideUp .28s cubic-bezier(.34, 1.2, .64, 1)
        }

        @keyframes modalSlideUp {
            from {
                transform: translateY(32px) scale(.97);
                opacity: 0
            }

            to {
                transform: translateY(0) scale(1);
                opacity: 1
            }
        }

        .modal-body {
            padding: 24px 26px;
            overflow-y: auto;
            flex: 1
        }

        .modal-body::-webkit-scrollbar {
            width: 3px
        }

        .modal-body::-webkit-scrollbar-thumb {
            background: var(--cream-3);
            border-radius: 2px
        }

        .modal-header {
            padding: 0;
            position: relative;
            flex-shrink: 0
        }

        .modal-header-inner {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding: 22px 24px 18px;
            gap: 12px
        }

        .modal-content::before {
            content: '';
            display: block;
            height: 3px;
            background: linear-gradient(90deg, var(--sage), var(--sage-light), var(--gold));
            border-radius: 20px 20px 0 0;
            flex-shrink: 0
        }

        .modal-header-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0
        }

        .modal-header-icon svg {
            width: 20px;
            height: 20px
        }

        .modal-header-icon.icon-view {
            background: var(--blue-pale)
        }

        .modal-header-icon.icon-view svg {
            stroke: var(--blue)
        }

        .modal-header-icon.icon-edit {
            background: var(--amber-pale)
        }

        .modal-header-icon.icon-edit svg {
            stroke: var(--amber)
        }

        .modal-header-icon.icon-add {
            background: var(--sage-pale)
        }

        .modal-header-icon.icon-add svg {
            stroke: var(--sage)
        }

        .modal-header-icon.icon-delete {
            background: var(--red-pale)
        }

        .modal-header-icon.icon-delete svg {
            stroke: var(--red)
        }

        .modal-header-text {
            flex: 1
        }

        .modal-title {
            font-family: 'Lora', serif;
            font-size: 19px;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
            margin-bottom: 3px
        }

        .modal-subtitle {
            font-size: 12.5px;
            color: var(--text-muted);
            font-weight: 400
        }

        .close-btn {
            width: 32px;
            height: 32px;
            background: var(--cream-2);
            border: 1px solid var(--border-light);
            border-radius: 9px;
            font-size: 17px;
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all .18s;
            flex-shrink: 0;
            margin-top: 2px
        }

        .close-btn:hover {
            background: var(--cream-3);
            color: var(--text-secondary)
        }

        .modal-divider {
            height: 1px;
            background: var(--border-light);
            margin: 0 24px
        }

        .form-group {
            margin-bottom: 18px
        }

        .form-group:last-child {
            margin-bottom: 0
        }

        .form-label {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 7px;
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text-secondary);
            letter-spacing: .3px;
            text-transform: uppercase
        }

        .form-label-icon {
            width: 14px;
            height: 14px;
            opacity: .55
        }

        .form-input,
        .form-select {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
            color: var(--text-primary);
            background: var(--cream);
            transition: border-color .2s, background .2s, box-shadow .2s
        }

        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--sage-light);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(90, 122, 82, .1)
        }

        .form-input::placeholder {
            color: var(--text-muted)
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a18e' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 13px center;
            padding-right: 36px;
            cursor: pointer
        }

        .input-affix-wrap {
            position: relative
        }

        .input-prefix-sym {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
            pointer-events: none;
            line-height: 1
        }

        .input-suffix-sym {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            pointer-events: none;
            line-height: 1
        }

        .input-affix-wrap.show-prefix .form-input {
            padding-left: 28px
        }

        .input-affix-wrap.show-suffix .form-input {
            padding-right: 42px
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px
        }

        .form-grid .form-group {
            margin-bottom: 0
        }

        .form-grid .form-group.full {
            grid-column: 1 / -1
        }

        /* ── Crop Type field slide-in animation ── */
        .crop-type-group {
            overflow: hidden;
            max-height: 0;
            opacity: 0;
            transition: max-height .3s cubic-bezier(.4, 0, .2, 1), opacity .25s ease, margin .3s ease;
            margin-top: 0
        }

        .crop-type-group.visible {
            max-height: 100px;
            opacity: 1;
            margin-top: 0
        }

        /* ── View record details ── */
        .record-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px
        }

        .record-detail {
            padding: 13px 15px;
            background: var(--cream);
            border-radius: 10px;
            border: 1px solid var(--border-light);
            transition: background .15s
        }

        .record-detail:hover {
            background: var(--cream-2)
        }

        .record-detail.full {
            grid-column: 1 / -1
        }

        .record-detail-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .9px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 5px
        }

        .record-detail-label svg {
            width: 11px;
            height: 11px;
            opacity: .7
        }

        .record-detail-value {
            font-size: 14.5px;
            color: var(--text-primary);
            font-weight: 600;
            line-height: 1.3
        }

        /* ── Delete warning ── */
        .delete-warning {
            display: flex;
            gap: 14px;
            align-items: flex-start;
            padding: 16px 18px;
            background: var(--red-pale);
            border: 1.5px solid rgba(192, 57, 43, .15);
            border-radius: 12px;
            margin-bottom: 18px
        }

        .delete-warning-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(192, 57, 43, .12);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 18px
        }

        .delete-warning-title {
            font-weight: 700;
            color: var(--red);
            margin-bottom: 3px;
            font-size: 13.5px
        }

        .delete-warning-text {
            font-size: 12.5px;
            color: #8b2522;
            line-height: 1.5
        }

        /* ── Modal footer ── */
        .modal-footer {
            padding: 16px 26px 22px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
            flex-shrink: 0
        }

        .modal-btn {
            padding: 11px 22px;
            border: none;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all .18s;
            font-family: 'DM Sans', sans-serif;
            display: flex;
            align-items: center;
            gap: 7px
        }

        .modal-btn svg {
            width: 14px;
            height: 14px
        }

        .modal-btn:disabled {
            opacity: .55;
            cursor: not-allowed
        }

        .btn-cancel {
            background: var(--cream-2);
            color: var(--text-secondary);
            border: 1px solid var(--border)
        }

        .btn-cancel:hover {
            background: var(--cream-3)
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--sage), var(--sage-dark));
            color: var(--white);
            box-shadow: 0 3px 10px rgba(61, 92, 56, .25)
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 14px rgba(61, 92, 56, .32)
        }

        .btn-primary:active {
            transform: translateY(0)
        }

        .btn-danger {
            background: linear-gradient(135deg, #d44638, var(--red));
            color: var(--white);
            box-shadow: 0 3px 10px rgba(192, 57, 43, .25)
        }

        .btn-danger:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 14px rgba(192, 57, 43, .32)
        }

        .btn-danger:active {
            transform: translateY(0)
        }

        .modal-section-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 12px;
            margin-top: 20px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-light)
        }

        .modal-section-label:first-child {
            margin-top: 0
        }

        .form-error-text {
            font-size: 12px;
            color: var(--red);
            font-weight: 600;
            margin-top: 8px;
            display: none
        }

        .form-error-text.show {
            display: block
        }

        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: var(--text-primary);
            color: var(--white);
            padding: 12px 18px;
            border-radius: var(--radius-sm);
            font-size: 13.5px;
            font-weight: 500;
            box-shadow: var(--shadow-lg);
            z-index: 5000;
            opacity: 0;
            transform: translateY(10px);
            transition: opacity .25s, transform .25s;
            pointer-events: none;
            max-width: 320px
        }

        .toast.show {
            opacity: 1;
            transform: translateY(0)
        }

        .toast.error {
            background: var(--red)
        }

        @media(max-width:768px) {

            html,
            body {
                height: auto !important;
                min-height: 100%;
                overflow: auto !important
            }

            body {
                display: block !important;
                width: 100% !important;
                height: auto !important;
                overflow-x: hidden !important
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

            body.sidebar-collapsed .sidebar {
                width: var(--sidebar-w) !important
            }

            body.sidebar-collapsed .logo-wrap {
                display: flex !important
            }

            body.sidebar-collapsed .logo-text,
            body.sidebar-collapsed .nav-label,
            body.sidebar-collapsed .nav-text,
            body.sidebar-collapsed .signout-text {
                display: inline !important
            }

            body.sidebar-collapsed .toggle-btn {
                display: flex !important
            }

            body.sidebar-collapsed .expand-btn {
                display: none !important
            }

            body.sidebar-collapsed .sidebar-top {
                padding: 18px 16px 14px !important;
                justify-content: flex-start !important
            }

            body.sidebar-collapsed .nav-section {
                padding: 8px 12px !important;
                align-items: stretch !important;
                display: flex !important;
                flex-direction: column !important;
                gap: 0 !important
            }

            body.sidebar-collapsed .nav-item {
                width: auto !important;
                height: auto !important;
                justify-content: flex-start !important;
                padding: 10px !important
            }

            body.sidebar-collapsed .nav-item.active::before {
                display: block !important
            }

            body.sidebar-collapsed .nav-item::after {
                display: none !important
            }

            body.sidebar-collapsed .sidebar-bottom {
                padding: 10px 12px 16px !important;
                display: flex !important
            }

            body.sidebar-collapsed .signout-btn {
                width: 100% !important;
                height: auto !important;
                padding: 10px 13px !important;
                gap: 9px !important
            }

            .main-content {
                display: block !important;
                width: 100% !important;
                height: auto !important;
                overflow: visible !important
            }

            .header {
                position: sticky;
                top: 0;
                z-index: 100;
                padding: 0 16px
            }

            .hamburger-btn {
                display: flex !important
            }

            .profile-pill-name {
                display: none
            }

            .page-scroll {
                overflow: visible !important;
                height: auto !important;
                padding: 14px 16px 24px
            }

            .records-header {
            	display: flex;
            	justify-content: space-between;
            	align-items: center;
            	flex-wrap: nowrap;   /* CHANGED — was wrap; keeps both groups on one row until the mobile breakpoint */
            	gap: 10px;
            	flex-shrink: 0
        	}

            .header-buttons {
                width: 100%
            }

            .header-btn {
                flex: 1;
                justify-content: center;
                font-size: 12px;
                padding: 8px 10px
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

            .form-grid {
                grid-template-columns: 1fr
            }

            .form-grid .form-group.full {
                grid-column: 1
            }

            .record-details-grid {
                grid-template-columns: 1fr
            }

            .record-detail.full {
                grid-column: 1
            }
        }

        @media(max-width:480px) {
            .header-btn {
                padding: 8px 6px;
                font-size: 11px;
                gap: 4px
            }

            .records-table th,
            .records-table td {
                padding: 10px 12px;
                font-size: 12.5px
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

            <a href="records.php" class="nav-item active" data-tooltip="Records"><svg viewBox="0 0 24 24">
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
                <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()" aria-label="Open menu">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <rect x="3" y="3" width="18" height="18" rx="2" />
                        <path d="M9 3v18" />
                    </svg>
                </button>
                Records
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

        <div class="notif-drop" id="notifDrop">
            <div class="nd-head"><span class="nd-title">Notifications</span><button class="mark-read-btn"
                    onclick="markAllRead()">Mark all read</button></div>
            <div class="nd-list" id="ndList"></div>
            <div class="nd-foot"><a href="notifications.php">View all</a></div>
        </div>
        <div class="profile-drop" id="profileDrop">
            <h3 id="profileName"><?= htmlspecialchars($user['name']) ?></h3>
            <p id="profileEmail"><?= htmlspecialchars($user['email']) ?></p>
            <hr>
            <a href="profile.php" class="pd-btn">My Profile</a>
            <a href="logout.php" class="pd-btn">Sign out</a>
        </div>

                <div class="page-scroll">
                    <div class="records-header">
                        <div class="search-filter-bar">
                            <div class="search-box">
                                <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8" />
                                    <path d="m21 21-4.35-4.35" />
                                </svg>
                                <input type="text" placeholder="Search records…" id="searchInput">
                            </div>
                            <div class="filter-wrap">
                                <button class="filter-btn" id="filterBtn">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" />
                                    </svg>Filter
                                </button>
                                <div class="filter-dropdown" id="filterDropdown">
                                    <div class="filter-section-label">Activity</div>
                                    <div class="filter-option active" data-filter="all" data-type="activity"><svg
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <polyline points="20 6 9 17 4 12" />
                                        </svg>All Records</div>
                                    <div class="filter-divider"></div>
                                    <div class="filter-option" data-filter="Planting" data-type="activity"><svg viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="1.5" />
                                        </svg>Planting</div>
                                    <div class="filter-option" data-filter="Fertilizing" data-type="activity"><svg
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="1.5" />
                                        </svg>Fertilizing</div>
                                    <div class="filter-option" data-filter="Irrigation" data-type="activity"><svg
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="1.5" />
                                        </svg>Irrigation</div>
                                    <div class="filter-option" data-filter="Pest Control" data-type="activity"><svg
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="1.5" />
                                        </svg>Pest Control</div>
                                    <div class="filter-option" data-filter="Harvesting" data-type="activity"><svg
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="1.5" />
                                        </svg>Harvesting</div>
                                    <div class="filter-option" data-filter="Sales" data-type="activity"><svg viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="1.5" />
                                        </svg>Sales</div>
                                    <div class="filter-divider"></div>
                                    <div class="filter-section-label">Status</div>
                                    <div class="filter-option" data-filter="Completed" data-type="status"><svg viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="1.5" />
                                        </svg>Completed</div>
                                    <div class="filter-option" data-filter="In Progress" data-type="status"><svg viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="1.5" />
                                        </svg>In Progress</div>
                                    <div class="filter-option" data-filter="Pending" data-type="status"><svg viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="1.5" />
                                        </svg>Pending</div>
                                    <div class="filter-footer">
                                        <button class="filter-clear-btn" id="clearFilterBtn">Clear</button>
                                        <button class="filter-apply-btn" id="applyFilterBtn">Apply</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="header-buttons">
                            <div class="action-group">
                                <button class="header-btn btn-view" id="viewBtn" disabled onclick="openViewModal()">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>View
                                </button>
                                <button class="header-btn btn-edit" id="editBtn" disabled onclick="openEditModal()">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                    </svg>Edit
                                </button>
                                <button class="header-btn btn-delete" id="deleteBtn" disabled onclick="openDeleteModal()">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="3 6 5 6 21 6" />
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                    </svg>Delete
                                </button>
                            </div>
                            <button class="header-btn btn-add" onclick="openAddModal()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="12" y1="5" x2="12" y2="19" />
                                    <line x1="5" y1="12" x2="19" y2="12" />
                                </svg>Add Record
                            </button>
                            <button class="header-btn btn-export" onclick="exportRecordsExcel()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                    <polyline points="7 10 12 15 17 10" />
                                    <line x1="12" y1="15" x2="12" y2="3" />
                                </svg>Export
                            </button>
                        </div>
                    </div>

                    <div class="table-card">
                        <div class="table-responsive">
                            <table class="records-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Activity</th>
                                        <th>Field Location</th>
                                        <th>Cost (₱) / Qty (kg)</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="recordsTableBody">
                                    <!-- rows injected by JS from PHP_RECORDS -->
                                </tbody>
                            </table>
                        </div>
                        <div class="pagination" id="paginationBar" style="display:none">
                            <button class="page-btn" id="prevPageBtn">Previous</button>
                            <span id="pageNumbers" style="display:flex;gap:6px"></span>
                            <button class="page-btn" id="nextPageBtn">Next</button>
                        </div>
                    </div>
                </div>
            </div>
    

    <!-- ═══════════════════════════════════════════
         VIEW MODAL
    ═══════════════════════════════════════════ -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-header-inner">
                    <div class="modal-header-icon icon-view">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                    </div>
                    <div class="modal-header-text">
                        <div class="modal-title">Record Details</div>
                        <div class="modal-subtitle">View full information for this entry</div>
                    </div>
                    <button class="close-btn" onclick="closeModal('viewModal')">×</button>
                </div>
                <div class="modal-divider"></div>
            </div>
            <div class="modal-body">
                <div class="modal-section-label">Record Information</div>
                <div class="record-details-grid">
                    <div class="record-detail">
                        <div class="record-detail-label">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" />
                                <line x1="3" y1="10" x2="21" y2="10" />
                            </svg>
                            Date
                        </div>
                        <div class="record-detail-value" id="viewDate"></div>
                    </div>
                    <div class="record-detail">
                        <div class="record-detail-label">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="8" />
                            </svg>
                            Activity
                        </div>
                        <div class="record-detail-value" id="viewActivity"></div>
                    </div>
                    <!-- Crop Type — shown only for Planting -->
                    <div class="record-detail full" id="viewCropTypeWrap" style="display:none">
                        <div class="record-detail-label">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 22V12" />
                                <path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8" />
                                <path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8" />
                            </svg>
                            Crop Type
                        </div>
                        <div class="record-detail-value" id="viewCropType"></div>
                    </div>
                    <div class="record-detail full">
                        <div class="record-detail-label">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                                <circle cx="12" cy="10" r="3" />
                            </svg>
                            Field Location
                        </div>
                        <div class="record-detail-value" id="viewLocation"></div>
                    </div>
                    <div class="record-detail">
                        <div class="record-detail-label">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="1" x2="12" y2="23" />
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                            </svg>
                            <span id="viewValueLabel">Cost</span>
                        </div>
                        <div class="record-detail-value" id="viewValue"></div>
                    </div>
                    <div class="record-detail">
                        <div class="record-detail-label">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="20 6 9 17 4 12" />
                            </svg>
                            Status
                        </div>
                        <div class="record-detail-value" id="viewStatus"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn btn-cancel" onclick="closeModal('viewModal')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         EDIT MODAL
    ═══════════════════════════════════════════ -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-header-inner">
                    <div class="modal-header-icon icon-edit">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                        </svg>
                    </div>
                    <div class="modal-header-text">
                        <div class="modal-title">Edit Record</div>
                        <div class="modal-subtitle">Update the details for this entry</div>
                    </div>
                    <button class="close-btn" onclick="closeModal('editModal')">×</button>
                </div>
                <div class="modal-divider"></div>
            </div>
            <div class="modal-body">
                <div class="modal-section-label">Record Details</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">
                            <svg class="form-label-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" />
                                <line x1="3" y1="10" x2="21" y2="10" />
                            </svg>
                            Date
                        </label>
                        <input type="date" class="form-input" id="editDate">
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <svg class="form-label-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <circle cx="12" cy="12" r="8" />
                            </svg>
                            Activity
                        </label>
                        <select class="form-select" id="editActivity"
                            onchange="updateValueUI('edit'); toggleCropType('edit')">
                            <option>Planting</option>
                            <option>Fertilizing</option>
                            <option>Irrigation</option>
                            <option>Pest Control</option>
                            <option>Harvesting</option>
                            <option>Sales</option>
                        </select>
                    </div>
                    <!-- Crop Type field — slides in when Planting is selected -->
                    <div class="form-group full crop-type-group" id="editCropTypeGroup">
                        <label class="form-label">
                            <svg class="form-label-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <path d="M12 22V12" />
                                <path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8" />
                                <path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8" />
                            </svg>
                            Crop Type
                        </label>
                        <select class="form-select" id="editCropType">
                            <option value="">Select crop type…</option>
                            <option>IR64</option>
                            <option>NSIC Rc222</option>
                            <option>NSIC Rc160</option>
                            <option>PSB Rc18</option>
                            <option>Dinorado</option>
                            <option>Sinandomeng</option>
                            <option>Milagrosa</option>
                            <option>Jasmine 85</option>
                            <option>Other</option>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label class="form-label">
                            <svg class="form-label-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                                <circle cx="12" cy="10" r="3" />
                            </svg>
                            Field Location
                        </label>
                        <input type="text" class="form-input" id="editLocation">
                    </div>
                    <div class="form-group">
                        <label class="form-label" id="editValueLabel">
                            <svg class="form-label-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <line x1="12" y1="1" x2="12" y2="23" />
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                            </svg>
                            Cost (₱)
                        </label>
                        <div class="input-affix-wrap show-prefix" id="editValueWrap">
                            <span class="input-prefix-sym" id="editPrefix">₱</span>
                            <input type="number" class="form-input" id="editValue" placeholder="0" step="0.01" min="0">
                            <span class="input-suffix-sym" id="editSuffix" style="display:none"></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <svg class="form-label-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <polyline points="20 6 9 17 4 12" />
                            </svg>
                            Status
                        </label>
                        <select class="form-select" id="editStatus">
                            <option>Pending</option>
                            <option>In Progress</option>
                            <option>Completed</option>
                        </select>
                    </div>
                </div>
                <div class="form-error-text" id="editError"></div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn btn-cancel" onclick="closeModal('editModal')">Cancel</button>
                <button class="modal-btn btn-primary" id="editSaveBtn" onclick="saveEdit()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
                        <polyline points="17 21 17 13 7 13 7 21" />
                        <polyline points="7 3 7 8 15 8" />
                    </svg>
                    Save Changes
                </button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         ADD MODAL
    ═══════════════════════════════════════════ -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-header-inner">
                    <div class="modal-header-icon icon-add">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19" />
                            <line x1="5" y1="12" x2="19" y2="12" />
                        </svg>
                    </div>
                    <div class="modal-header-text">
                        <div class="modal-title">Add New Record</div>
                        <div class="modal-subtitle">Log a new farm activity entry</div>
                    </div>
                    <button class="close-btn" onclick="closeModal('addModal')">×</button>
                </div>
                <div class="modal-divider"></div>
            </div>
            <div class="modal-body">
                <div class="modal-section-label">Entry Details</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">
                            <svg class="form-label-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" />
                                <line x1="3" y1="10" x2="21" y2="10" />
                            </svg>
                            Date
                        </label>
                        <input type="date" class="form-input" id="addDate">
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <svg class="form-label-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <circle cx="12" cy="12" r="8" />
                            </svg>
                            Activity
                        </label>
                        <select class="form-select" id="addActivity"
                            onchange="updateValueUI('add'); toggleCropType('add')">
                            <option value="">Select activity…</option>
                            <option>Planting</option>
                            <option>Fertilizing</option>
                            <option>Irrigation</option>
                            <option>Pest Control</option>
                            <option>Harvesting</option>
                            <option>Sales</option>
                        </select>
                    </div>
                    <!-- Crop Type field — slides in when Planting is selected -->
                    <div class="form-group full crop-type-group" id="addCropTypeGroup">
                        <label class="form-label">
                            <svg class="form-label-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <path d="M12 22V12" />
                                <path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8" />
                                <path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8" />
                            </svg>
                            Crop Type
                        </label>
                        <select class="form-select" id="addCropType">
                            <option value="">Select crop type…</option>
                            <option>IR64</option>
                            <option>NSIC Rc222</option>
                            <option>NSIC Rc160</option>
                            <option>PSB Rc18</option>
                            <option>Dinorado</option>
                            <option>Sinandomeng</option>
                            <option>Milagrosa</option>
                            <option>Jasmine 85</option>
                            <option>Other</option>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label class="form-label">
                            <svg class="form-label-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                                <circle cx="12" cy="10" r="3" />
                            </svg>
                            Field Location
                        </label>
                        <input type="text" class="form-input" id="addLocation" placeholder="e.g. Field A-1">
                    </div>
                    <div class="form-group">
                        <label class="form-label" id="addValueLabel">
                            <svg class="form-label-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <line x1="12" y1="1" x2="12" y2="23" />
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                            </svg>
                            Cost (₱)
                        </label>
                        <div class="input-affix-wrap show-prefix" id="addValueWrap">
                            <span class="input-prefix-sym" id="addPrefix">₱</span>
                            <input type="number" class="form-input" id="addValue" placeholder="0" step="0.01" min="0">
                            <span class="input-suffix-sym" id="addSuffix" style="display:none"></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            <svg class="form-label-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <polyline points="20 6 9 17 4 12" />
                            </svg>
                            Status
                        </label>
                        <select class="form-select" id="addStatus">
                            <option value="">Select status…</option>
                            <option>Pending</option>
                            <option>In Progress</option>
                            <option>Completed</option>
                        </select>
                    </div>
                </div>
                <div class="form-error-text" id="addError"></div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn btn-cancel" onclick="closeModal('addModal')">Cancel</button>
                <button class="modal-btn btn-primary" id="addSaveBtn" onclick="saveAdd()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19" />
                        <line x1="5" y1="12" x2="19" y2="12" />
                    </svg>
                    Add Record
                </button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         DELETE MODAL
    ═══════════════════════════════════════════ -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-header-inner">
                    <div class="modal-header-icon icon-delete">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="3 6 5 6 21 6" />
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                        </svg>
                    </div>
                    <div class="modal-header-text">
                        <div class="modal-title">Delete Record</div>
                        <div class="modal-subtitle">This action is permanent and cannot be undone</div>
                    </div>
                    <button class="close-btn" onclick="closeModal('deleteModal')">×</button>
                </div>
                <div class="modal-divider"></div>
            </div>
            <div class="modal-body">
                <div class="delete-warning">
                    <div class="delete-warning-icon">⚠️</div>
                    <div>
                        <div class="delete-warning-title">Permanent Deletion</div>
                        <div class="delete-warning-text">Once deleted, this record cannot be recovered. Please confirm
                            you want to permanently remove this entry from your farm records.</div>
                    </div>
                </div>
                <div class="modal-section-label">Record to Delete</div>
                <div class="record-detail full">
                    <div class="record-detail-label">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                        </svg>
                        Entry
                    </div>
                    <div class="record-detail-value" id="deleteRecordInfo"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn btn-cancel" onclick="closeModal('deleteModal')">Keep Record</button>
                <button class="modal-btn btn-danger" id="deleteConfirmBtn" onclick="confirmDelete()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6" />
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                    </svg>
                    Delete Permanently
                </button>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        // ── Server data ──
        const PHP_RECORDS = <?= $jsRecords ?>;

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

        /* ===== BELL DROPDOWN — live data, shared pattern across all pages ===== */
        let _bellNotifs = [];
        let _bellLoaded = false;

        function bellNotifIcon(type) {
            const icons = {
                plant: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22V12"/><path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8"/><path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8"/></svg>`,
                weather: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 17.58A5 5 0 0 0 18 7h-1.26A8 8 0 1 0 4 16.25"/></svg>`,
                reminder: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="8"/><polyline points="12 6 12 12 16 14"/></svg>`,
                harvest: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 22 16 8"/><path d="M3.47 12.53 5 11l1.53 1.53a3.5 3.5 0 0 1 0 4.94L5 19l-1.53-1.53a3.5 3.5 0 0 1 0-4.94z"/></svg>`,
                pest: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="M8.5 8.5 16 16"/></svg>`,
                water: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>`,
            };
            return icons[type] || `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="8"/><polyline points="12 6 12 12 16 14"/></svg>`;
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

        const BELL_ICON_TYPES = ['plant', 'weather', 'reminder', 'harvest', 'pest', 'water'];
        const ND_STAR_SVG = '<svg viewBox="0 0 24 24" width="11" height="11" fill="var(--star,#e6a817)" stroke="var(--star,#e6a817)" stroke-width="1.5" style="vertical-align:-1px;margin-right:3px;flex-shrink:0"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';

        function renderBellNotifs() {
            const list = document.getElementById('ndList');
            if (!list) return;
            if (!_bellNotifs.length) {
                list.innerHTML = `<div style="padding:24px 16px;text-align:center;font-size:12.5px;color:var(--text-muted);">No notifications yet.</div>`;
                return;
            }
            list.innerHTML = _bellNotifs.map(n => {
                const iconClass = BELL_ICON_TYPES.includes(n.type) ? n.type : 'default';
                return `
                <div class="nd-item ${n.status}${n.starred && parseInt(n.starred, 10) ? ' starred' : ''}">
                    <div class="nd-icon nd-ic-${iconClass}">${bellNotifIcon(n.type)}</div>
                    <div>
                        <div class="nd-item-title">${parseInt(n.starred, 10) ? ND_STAR_SVG : ''}${bellEscapeHtml(n.title)}</div>
                        <div class="nd-item-desc">${bellEscapeHtml(n.description || '')}</div>
                        <div class="nd-item-date">${n.notif_date || ''}</div>
                    </div>
                </div>`;
            }).join('');
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
                        list.innerHTML = `<div style="padding:24px 16px;text-align:center;font-size:12.5px;color:var(--text-muted);">Couldn't load notifications.</div>`;
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

        // ── Toast helper ──
        let toastTimer = null;

        function showToast(msg, isError) {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.classList.toggle('error', !!isError);
            t.classList.add('show');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => t.classList.remove('show'), 3200);
        }

        // ── Date helpers (DB stores YYYY-MM-DD, display as "Jan 28, 2026") ──
        function dbToDisplayDate(dbDate) {
            if (!dbDate) return '';
            const [y, m, d] = dbDate.split('-').map(Number);
            const dt = new Date(y, m - 1, d);
            return dt.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
        }

        // ── Records state (live mirror of DB rows) ──
        let records = PHP_RECORDS.map(r => ({
            id: r.id,
            activity: r.activity,
            crop_type: r.crop_type || '',
            location: r.location,
            quantity: parseFloat(r.quantity),
            unit: r.unit || (r.activity === 'Harvesting' ? 'kg' : 'peso'),
            status: r.status,
            record_date: r.record_date // 'YYYY-MM-DD'
        }));

        let filteredRecords = records.slice();
        let selectedId = null;
        let currentPage = 1;
        const PAGE_SIZE = 8;
        let sf = {
            activity: 'all',
            status: 'all'
        };
        let searchTerm = '';

        function isKg(activity) {
            return activity === 'Harvesting';
        }

        function buildValueCellHtml(value, unit) {
            const num = (Math.round(parseFloat(value) * 100) / 100);
            const display = Number.isInteger(num) ? num : num.toFixed(2);
            if (unit === 'kg') return `<div class="value-cell"><span>${display}</span><span class="unit-tag kg">kg</span></div>`;
            return `<div class="value-cell"><span>${display}</span><span class="unit-tag peso">₱</span></div>`;
        }

        function statusBadgeHtml(status) {
            const cls = status === 'Completed' ? 'badge-completed' : status === 'In Progress' ? 'badge-in-progress' : 'badge-pending';
            return `<span class="status-badge ${cls}">${status}</span>`;
        }

        function applyFiltersAndSearch() {
            const term = searchTerm.toLowerCase();
            filteredRecords = records.filter(r => {
                const matchesActivity = sf.activity === 'all' || r.activity === sf.activity;
                const matchesStatus = sf.status === 'all' || r.status === sf.status;
                if (!matchesActivity || !matchesStatus) return false;
                if (!term) return true;
                const haystack = [
                    dbToDisplayDate(r.record_date), r.activity, r.crop_type, r.location,
                    String(r.quantity), r.unit, r.status
                ].join(' ').toLowerCase();
                return haystack.includes(term);
            });
            currentPage = 1;
            renderTable();
        }

        function renderTable() {
            const tbody = document.getElementById('recordsTableBody');
            if (!filteredRecords.length) {
                tbody.innerHTML = `<tr><td colspan="5">
                    <div class="empty-state">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" width="38" height="38" style="opacity:.4;margin-bottom:10px">
                            <rect x="3" y="3" width="18" height="18" rx="2" />
                            <line x1="3" y1="9" x2="21" y2="9" />
                            <line x1="9" y1="21" x2="9" y2="9" />
                        </svg>
                        <div>No records found. Try adjusting your search or filters, or add a new record.</div>
                    </div>
                </td></tr>`;
                document.getElementById('paginationBar').style.display = 'none';
                clearSelection();
                return;
            }
            const totalPages = Math.max(1, Math.ceil(filteredRecords.length / PAGE_SIZE));
            if (currentPage > totalPages) currentPage = totalPages;
            const start = (currentPage - 1) * PAGE_SIZE;
            const pageRows = filteredRecords.slice(start, start + PAGE_SIZE);

            tbody.innerHTML = pageRows.map(r => `
                <tr data-id="${r.id}" class="${r.id === selectedId ? 'selected' : ''}">
                    <td>${dbToDisplayDate(r.record_date)}</td>
                    <td>${activityCellHtml(r.activity)}</td>
                    <td>${escapeHtml(r.location)}</td>
                    <td>${buildValueCellHtml(r.quantity, r.unit)}</td>
                    <td>${statusBadgeHtml(r.status)}</td>
                </tr>
            `).join('');

            tbody.querySelectorAll('tr[data-id]').forEach(row => {
                row.addEventListener('click', function() {
                    selectRow(parseInt(this.dataset.id, 10));
                });
            });

            renderPagination(totalPages);
        }

        function renderPagination(totalPages) {
            const bar = document.getElementById('paginationBar');
            if (totalPages <= 1) {
                bar.style.display = 'none';
                return;
            }
            bar.style.display = 'flex';
            const nums = document.getElementById('pageNumbers');
            let html = '';
            for (let i = 1; i <= totalPages; i++) {
                html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</button>`;
            }
            nums.innerHTML = html;
            nums.querySelectorAll('.page-btn').forEach(b => b.addEventListener('click', () => {
                currentPage = parseInt(b.dataset.page, 10);
                renderTable();
            }));
            document.getElementById('prevPageBtn').disabled = currentPage === 1;
            document.getElementById('nextPageBtn').onclick = () => {
                if (currentPage > 1) {
                    currentPage--;
                    renderTable();
                }
            };
            document.getElementById('prevPageBtn').onclick = () => {
                if (currentPage > 1) {
                    currentPage--;
                    renderTable();
                }
            };
            document.getElementById('nextPageBtn').onclick = () => {
                if (currentPage < totalPages) {
                    currentPage++;
                    renderTable();
                }
            };
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
            
        const ACT_ICONS = {
            'Planting':     ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22V12"/><path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8"/><path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8"/></svg>', 'var(--sage-pale)', 'var(--sage-dark)'],
            'Fertilizing':  ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 3h6l1 4H8z"/><path d="M8 7h8l1 13a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1z"/></svg>', '#e8f0e4', 'var(--sage-dark)'],
            'Irrigation':   ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>', 'var(--sky-pale)', 'var(--sky)'],
            'Pest Control': ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="M8.5 8.5 16 16"/></svg>', 'var(--gold-pale)', 'var(--gold)'],
            'Harvesting':   ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 17l6-6 4 4 8-8v10H3z"/></svg>', 'var(--gold-pale)', 'var(--gold)'],
            'Sales':        ['<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 9.5c0-1.5 1.3-2.5 3-2.5s3 1 3 2.5S13.5 12 12 12s-3 1-3 2.5 1.3 2.5 3 2.5 3-1 3-2.5"/></svg>', 'var(--sage-pale)', 'var(--sage-dark)'],
        };

        function activityCellHtml(activity) {
            return escapeHtml(activity);
        }

        function selectRow(id) {
            selectedId = id;
            document.querySelectorAll('#recordsTableBody tr[data-id]').forEach(row => {
                row.classList.toggle('selected', parseInt(row.dataset.id, 10) === id);
            });
            ['viewBtn', 'editBtn', 'deleteBtn'].forEach(elId => document.getElementById(elId).disabled = false);
        }

        function clearSelection() {
            selectedId = null;
            ['viewBtn', 'editBtn', 'deleteBtn'].forEach(elId => document.getElementById(elId).disabled = true);
        }

        function getSelectedRecord() {
            return records.find(r => r.id === selectedId) || null;
        }

        // ── Export to Excel ──
        // Note: a plain .csv file is just raw text and can't carry colors,
        // bold headers, or subtotal rows. To get the polished, grouped
        // look (colored section headers + subtotals), this builds a real
        // HTML table and hands it to Excel as an .xls file — Excel opens
        // it natively and renders all the styling, the same trick used
        // to produce the report in your reference screenshot.
        function exportRecordsExcel() {
            const source = filteredRecords.length ? filteredRecords : records;
            if (!source.length) {
                showToast('No records to export.', true);
                return;
            }

            // Group by Activity (each activity type shares one unit — kg or peso —
            // so a subtotal per group is always in a consistent unit).
            const groupOrder = [];
            const groups = {};
            source.forEach(r => {
                if (!groups[r.activity]) {
                    groups[r.activity] = [];
                    groupOrder.push(r.activity);
                }
                groups[r.activity].push(r);
            });

            const fmtNum = n => Number(n).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            const fmtVal = (n, unit) => unit === 'kg' ? (fmtNum(n) + ' kg') : ('&#8369;' + fmtNum(n));
            const todayStr = new Date().toLocaleDateString('en-US', {
                month: 'long',
                day: 'numeric',
                year: 'numeric'
            });

            let grandTotalPeso = 0,
                grandTotalKg = 0;

            let rowsHtml = '';
            groupOrder.forEach(activity => {
                const rows = groups[activity];
                const unit = rows[0].unit;
                rowsHtml += `<tr>
                    <td colspan="5" style="background:#c8963e;color:#ffffff;font-weight:bold;font-size:13px;padding:7px 10px;border:1px solid #b8842e;">${escapeHtml(activity)}</td>
                </tr>`;

                let subtotal = 0;
                rows.forEach(r => {
                    subtotal += Number(r.quantity);
                    rowsHtml += `<tr>
                        <td style="border:1px solid #ddd8cf;padding:6px 10px;">${dbToDisplayDate(r.record_date)}</td>
                        <td style="border:1px solid #ddd8cf;padding:6px 10px;">${escapeHtml(r.activity)}</td>
                        <td style="border:1px solid #ddd8cf;padding:6px 10px;">${escapeHtml(r.location)}</td>
                        <td style="border:1px solid #ddd8cf;padding:6px 10px;mso-number-format:'0.00';" align="right">${fmtVal(r.quantity, unit)}</td>
                        <td style="border:1px solid #ddd8cf;padding:6px 10px;">${escapeHtml(r.status)}</td>
                    </tr>`;
                });

                if (unit === 'kg') grandTotalKg += subtotal;
                else grandTotalPeso += subtotal;

                rowsHtml += `<tr>
                    <td colspan="3" style="background:#fdf6ec;color:#1e2a1a;font-weight:bold;padding:6px 10px;border:1px solid #ddd8cf;" align="right">Subtotal</td>
                    <td style="background:#fdf6ec;color:#1e2a1a;font-weight:bold;padding:6px 10px;border:1px solid #ddd8cf;" align="right">${fmtVal(subtotal, unit)}</td>
                    <td style="background:#fdf6ec;border:1px solid #ddd8cf;"></td>
                </tr>`;
                rowsHtml += `<tr><td colspan="5" style="padding:4px;border:none;"></td></tr>`;
            });

            const html = `
                <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
                <head><meta charset="UTF-8">
                <!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
                <x:Name>Farm Records</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
                </x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
                </head>
                <body>
                <table border="0" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:13px;">
                    <tr><td colspan="5" style="background:#3d5c38;color:#ffffff;font-size:20px;font-weight:bold;padding:14px 10px;">Farm Records</td></tr>
                    <tr><td colspan="5" style="padding:2px 10px;color:#5a6655;font-size:11px;">Exported ${todayStr}${filteredRecords.length !== records.length ? ' &middot; filtered view' : ''}</td></tr>
                    <tr><td colspan="5" style="padding:6px;"></td></tr>
                    <tr>
                        <td style="background:#5a7a52;color:#ffffff;font-weight:bold;padding:7px 10px;border:1px solid #ddd8cf;">Date</td>
                        <td style="background:#5a7a52;color:#ffffff;font-weight:bold;padding:7px 10px;border:1px solid #ddd8cf;">Activity</td>
                        <td style="background:#5a7a52;color:#ffffff;font-weight:bold;padding:7px 10px;border:1px solid #ddd8cf;">Field Location</td>
                        <td style="background:#5a7a52;color:#ffffff;font-weight:bold;padding:7px 10px;border:1px solid #ddd8cf;">Cost / Qty</td>
                        <td style="background:#5a7a52;color:#ffffff;font-weight:bold;padding:7px 10px;border:1px solid #ddd8cf;">Status</td>
                    </tr>
                    ${rowsHtml}
                    <tr><td colspan="5" style="padding:4px;"></td></tr>
                    <tr>
                        <td colspan="3" style="background:#3d5c38;color:#ffffff;font-weight:bold;padding:8px 10px;" align="right">Grand Total</td>
                        <td style="background:#3d5c38;color:#ffffff;font-weight:bold;padding:8px 10px;" align="right">${grandTotalPeso ? '&#8369;' + fmtNum(grandTotalPeso) : ''}${grandTotalPeso && grandTotalKg ? ' / ' : ''}${grandTotalKg ? fmtNum(grandTotalKg) + ' kg' : ''}</td>
                        <td style="background:#3d5c38;"></td>
                    </tr>
                </table>
                </body></html>`;

            const blob = new Blob(['\ufeff', html], {
                type: 'application/vnd.ms-excel'
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `ricewise-records-${new Date().toISOString().slice(0, 10)}.xls`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
            showToast('Records exported.');
        }

        document.getElementById('searchInput').addEventListener('input', function() {
            searchTerm = this.value.trim();
            applyFiltersAndSearch();
        });

        const filterBtn = document.getElementById('filterBtn'),
            filterDD = document.getElementById('filterDropdown');
        filterBtn.addEventListener('click', e => {
            e.stopPropagation();
            filterDD.classList.toggle('show');
        });
        document.querySelectorAll('.filter-option').forEach(opt => {
            opt.addEventListener('click', function() {
                const type = this.dataset.type,
                    filter = this.dataset.filter;
                if (type === 'activity' || filter === 'all') {
                    document.querySelectorAll('.filter-option[data-type="activity"],.filter-option[data-filter="all"]').forEach(o => o.classList.remove('active'));
                    sf.activity = filter;
                } else {
                    document.querySelectorAll('.filter-option[data-type="status"]').forEach(o => o.classList.remove('active'));
                    sf.status = filter;
                }
                this.classList.add('active');
            });
        });
        document.getElementById('clearFilterBtn').addEventListener('click', () => {
            sf = {
                activity: 'all',
                status: 'all'
            };
            document.querySelectorAll('.filter-option').forEach(o => o.classList.remove('active'));
            document.querySelector('.filter-option[data-filter="all"]').classList.add('active');
            applyFiltersAndSearch();
            filterDD.classList.remove('show');
        });
        document.getElementById('applyFilterBtn').addEventListener('click', () => {
            applyFiltersAndSearch();
            filterDD.classList.remove('show');
        });
        document.addEventListener('click', e => {
            if (!filterBtn.contains(e.target) && !filterDD.contains(e.target)) filterDD.classList.remove('show');
        });

        function updateValueUI(pfx) {
            const activity = document.getElementById(pfx + 'Activity').value;
            const label = document.getElementById(pfx + 'ValueLabel');
            const wrap = document.getElementById(pfx + 'ValueWrap');
            const prefix = document.getElementById(pfx + 'Prefix');
            const suffix = document.getElementById(pfx + 'Suffix');
            const labelSvg = label.querySelector('svg') ? label.querySelector('svg').outerHTML : '';
            if (isKg(activity)) {
                label.innerHTML = labelSvg + ' Quantity (kg)';
                prefix.style.display = 'none';
                suffix.style.display = '';
                suffix.textContent = 'kg';
                wrap.classList.remove('show-prefix');
                wrap.classList.add('show-suffix');
            } else {
                label.innerHTML = labelSvg + ' Cost (₱)';
                prefix.style.display = '';
                prefix.textContent = '₱';
                suffix.style.display = 'none';
                wrap.classList.add('show-prefix');
                wrap.classList.remove('show-suffix');
            }
        }

        function toggleCropType(pfx) {
            const activity = document.getElementById(pfx + 'Activity').value;
            const group = document.getElementById(pfx + 'CropTypeGroup');
            if (activity === 'Planting') {
                group.classList.add('visible');
            } else {
                group.classList.remove('visible');
                document.getElementById(pfx + 'CropType').value = '';
            }
        }

        function setButtonLoading(btnId, loading, loadingText) {
            const btn = document.getElementById(btnId);
            if (!btn) return;
            btn.disabled = loading;
            if (loading) {
                btn.dataset.origHtml = btn.innerHTML;
                btn.innerHTML = loadingText || 'Saving…';
            } else if (btn.dataset.origHtml) {
                btn.innerHTML = btn.dataset.origHtml;
            }
        }

        // ── VIEW ──
        function openViewModal() {
            const r = getSelectedRecord();
            if (!r) return;
            document.getElementById('viewDate').textContent = dbToDisplayDate(r.record_date);
            document.getElementById('viewActivity').textContent = r.activity;
            document.getElementById('viewLocation').textContent = r.location;
            if (r.unit === 'kg') {
                document.getElementById('viewValueLabel').textContent = 'Quantity';
                document.getElementById('viewValue').textContent = r.quantity + ' kg';
            } else {
                document.getElementById('viewValueLabel').textContent = 'Cost';
                document.getElementById('viewValue').textContent = '₱' + r.quantity;
            }
            document.getElementById('viewStatus').textContent = r.status;

            const cropWrap = document.getElementById('viewCropTypeWrap');
            if (r.activity === 'Planting') {
                document.getElementById('viewCropType').textContent = r.crop_type || '—';
                cropWrap.style.display = '';
            } else {
                cropWrap.style.display = 'none';
            }
            document.getElementById('viewModal').classList.add('show');
        }

        // ── EDIT ──
        function openEditModal() {
            const r = getSelectedRecord();
            if (!r) return;
            document.getElementById('editError').classList.remove('show');
            document.getElementById('editDate').value = r.record_date;
            document.getElementById('editActivity').value = r.activity;
            document.getElementById('editLocation').value = r.location;
            document.getElementById('editValue').value = r.quantity;
            document.getElementById('editStatus').value = r.status;
            document.getElementById('editCropType').value = r.crop_type || '';

            updateValueUI('edit');
            toggleCropType('edit');
            document.getElementById('editModal').classList.add('show');
        }

        function saveEdit() {
            const r = getSelectedRecord();
            if (!r) return;
            const errEl = document.getElementById('editError');
            errEl.classList.remove('show');

            const date = document.getElementById('editDate').value;
            const activity = document.getElementById('editActivity').value;
            const location = document.getElementById('editLocation').value.trim();
            const value = document.getElementById('editValue').value;
            const status = document.getElementById('editStatus').value;
            const cropType = document.getElementById('editCropType').value;

            if (!date || !activity || !location || value === '' || !status) {
                errEl.textContent = 'Please fill all required fields.';
                errEl.classList.add('show');
                return;
            }
            if (activity === 'Planting' && !cropType) {
                errEl.textContent = 'Please select a crop type for Planting.';
                errEl.classList.add('show');
                return;
            }
            if (parseFloat(value) < 0) {
                errEl.textContent = 'Value cannot be negative.';
                errEl.classList.add('show');
                return;
            }

            const unit = isKg(activity) ? 'kg' : 'peso';
            const payload = {
                id: r.id,
                record_date: date,
                activity,
                location,
                quantity: parseFloat(value),
                unit,
                status,
                crop_type: activity === 'Planting' ? cropType : null
            };

            setButtonLoading('editSaveBtn', true, 'Saving…');
            fetch('records_edit.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            }).then(res => res.json()).then(json => {
                setButtonLoading('editSaveBtn', false);
                if (!json.success) {
                    errEl.textContent = json.message || 'Failed to save changes.';
                    errEl.classList.add('show');
                    return;
                }
                Object.assign(r, payload, {
                    crop_type: payload.crop_type || ''
                });
                applyFiltersAndSearch();
                closeModal('editModal');
                showToast('Record updated successfully.');
            }).catch(() => {
                setButtonLoading('editSaveBtn', false);
                errEl.textContent = 'Network error — please try again.';
                errEl.classList.add('show');
            });
        }

        // ── ADD ──
        function openAddModal() {
            ['addDate', 'addLocation', 'addValue'].forEach(id => document.getElementById(id).value = '');
            document.getElementById('addActivity').value = '';
            document.getElementById('addStatus').value = '';
            document.getElementById('addCropType').value = '';
            document.getElementById('addCropTypeGroup').classList.remove('visible');
            document.getElementById('addError').classList.remove('show');

            const today = new Date();
            document.getElementById('addDate').value = today.toISOString().slice(0, 10);

            const lbl = document.getElementById('addValueLabel');
            const svgEl = lbl.querySelector('svg');
            const svgHtml = svgEl ? svgEl.outerHTML : '';
            lbl.innerHTML = svgHtml + ' Cost (₱)';
            document.getElementById('addPrefix').textContent = '₱';
            document.getElementById('addPrefix').style.display = '';
            document.getElementById('addSuffix').style.display = 'none';
            document.getElementById('addValueWrap').classList.add('show-prefix');
            document.getElementById('addValueWrap').classList.remove('show-suffix');
            document.getElementById('addModal').classList.add('show');
        }

        function saveAdd() {
            const errEl = document.getElementById('addError');
            errEl.classList.remove('show');

            const date = document.getElementById('addDate').value;
            const activity = document.getElementById('addActivity').value;
            const location = document.getElementById('addLocation').value.trim();
            const value = document.getElementById('addValue').value;
            const status = document.getElementById('addStatus').value;
            const cropType = document.getElementById('addCropType').value;

            if (!date || !activity || !location || value === '' || !status) {
                errEl.textContent = 'Please fill all required fields.';
                errEl.classList.add('show');
                return;
            }
            if (activity === 'Planting' && !cropType) {
                errEl.textContent = 'Please select a crop type for Planting.';
                errEl.classList.add('show');
                return;
            }
            if (parseFloat(value) < 0) {
                errEl.textContent = 'Value cannot be negative.';
                errEl.classList.add('show');
                return;
            }

            const unit = isKg(activity) ? 'kg' : 'peso';
            const payload = {
                record_date: date,
                activity,
                location,
                quantity: parseFloat(value),
                unit,
                status,
                crop_type: activity === 'Planting' ? cropType : null
            };

            setButtonLoading('addSaveBtn', true, 'Adding…');
            fetch('records_add.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            }).then(res => res.json()).then(json => {
                setButtonLoading('addSaveBtn', false);
                if (!json.success) {
                    errEl.textContent = json.message || 'Failed to add record.';
                    errEl.classList.add('show');
                    return;
                }
                records.unshift({
                    id: json.id,
                    activity,
                    crop_type: payload.crop_type || '',
                    location,
                    quantity: payload.quantity,
                    unit,
                    status,
                    record_date: date
                });
                applyFiltersAndSearch();
                closeModal('addModal');
                showToast('Record added successfully.');
            }).catch(() => {
                setButtonLoading('addSaveBtn', false);
                errEl.textContent = 'Network error — please try again.';
                errEl.classList.add('show');
            });
        }

        // ── DELETE ──
        function openDeleteModal() {
            const r = getSelectedRecord();
            if (!r) return;
            document.getElementById('deleteRecordInfo').textContent = `${r.activity} — ${r.location} (${dbToDisplayDate(r.record_date)})`;
            document.getElementById('deleteModal').classList.add('show');
        }

        function confirmDelete() {
            const r = getSelectedRecord();
            if (!r) return;
            setButtonLoading('deleteConfirmBtn', true, 'Deleting…');
            fetch('records_delete.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    id: r.id
                })
            }).then(res => res.json()).then(json => {
                setButtonLoading('deleteConfirmBtn', false);
                if (!json.success) {
                    showToast(json.message || 'Failed to delete record.', true);
                    return;
                }
                records = records.filter(x => x.id !== r.id);
                clearSelection();
                applyFiltersAndSearch();
                closeModal('deleteModal');
                showToast('Record deleted.');
            }).catch(() => {
                setButtonLoading('deleteConfirmBtn', false);
                showToast('Network error — please try again.', true);
            });
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }
        window.addEventListener('click', e => {
            if (e.target.classList.contains('modal')) e.target.classList.remove('show');
        });

        // ── Init ──
        document.addEventListener('DOMContentLoaded', () => {
            applyFiltersAndSearch();
        });
    </script>
</body>

</html>