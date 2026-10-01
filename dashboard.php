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
// Fetch user
$stmt = $conn->prepare("SELECT id,name,email,location,username,profile_pic,hectares,farm_lat,farm_lng FROM users WHERE id=? LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) {
    header('Location: logout.php');
    exit;
}
$_SESSION['rw_user_name'] = $user['name'];
// Fetch records
$rs = $conn->prepare("SELECT activity,location,quantity,status,record_date FROM farm_records WHERE user_id=? ORDER BY record_date DESC LIMIT 5");
$rs->bind_param('i', $uid);
$rs->execute();
$records = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
$rs->close();
// Fetch reminders
$rm = $conn->prepare("SELECT title,event_date FROM calendar_events WHERE user_id=? AND event_date>=CURDATE() AND event_date<=DATE_ADD(CURDATE(),INTERVAL 30 DAY) ORDER BY event_date ASC LIMIT 5");
$rm->bind_param('i', $uid);
$rm->execute();
$reminders = $rm->get_result()->fetch_all(MYSQLI_ASSOC);
$rm->close();
// ── Real planting-process data (was a hardcoded fixed-date STEPS array,
// completely disconnected from the real planting_cycles system built in
// process.php). Now pulling actual active cycles so the ring and modal
// agree with what process.php shows. ──
$pc = $conn->prepare(
    "SELECT id, field_location, crop_type, start_date
     FROM planting_cycles WHERE user_id=? AND status='active' ORDER BY start_date ASC"
);
$pc->bind_param('i', $uid);
$pc->execute();
$activeCycles = $pc->get_result()->fetch_all(MYSQLI_ASSOC);
$pc->close();
foreach ($activeCycles as &$cyc) {
    $ds = $conn->prepare("SELECT step_index FROM planting_process_completed WHERE cycle_id=?");
    $ds->bind_param('i', $cyc['id']);
    $ds->execute();
    $cyc['done_steps'] = array_map(fn($r) => (int)$r['step_index'], $ds->get_result()->fetch_all(MYSQLI_ASSOC));
    $ds->close();
}
unset($cyc);
// ── Rice Field Visualizer — saved layout (was pure client-side/no
// persistence in the original widget, so it reset on every reload) ──
$flStmt = $conn->prepare("SELECT columns, `rows`, cell_size, grid_json FROM field_layouts WHERE user_id=? LIMIT 1");
$flStmt->bind_param('i', $uid);
$flStmt->execute();
$fieldLayout = $flStmt->get_result()->fetch_assoc();
$flStmt->close();
if ($fieldLayout) {
    $rawGrid = json_decode($fieldLayout['grid_json'], true) ?: [];
    $fieldLayout['grid'] = array_map(function ($cell) {
        if (is_array($cell)) {
            return ['type' => $cell['type'] ?? 'bare', 'field' => $cell['field'] ?? null];
        }
        return ['type' => $cell, 'field' => null];
    }, $rawGrid);
} else {
    $defCols = 5;
    $defRows = 4;
    $fieldLayout = [
        'columns'   => $defCols,
        'rows'      => $defRows,
        'cell_size' => 52,
        'grid'      => array_fill(0, $defCols * $defRows, ['type' => 'seedling', 'field' => null]),
    ];
}
/*
 * Daily severe-weather notification check. Runs on dashboard load, but
 * guarded so it only ever creates ONE weather notification per user per
 * calendar day — repeated page loads don't spam duplicates.
 */
$todayStr = date('Y-m-d');
$fallbackLat = 14.937936;
$fallbackLng = 120.927572;
$userLat = ($user['farm_lat'] !== null) ? (float)$user['farm_lat'] : $fallbackLat;
$userLng = ($user['farm_lng'] !== null) ? (float)$user['farm_lng'] : $fallbackLng;
$existsCheck = $conn->prepare(
    "SELECT id FROM notifications WHERE user_id=? AND type='weather' AND notif_date=? LIMIT 1"
);
$existsCheck->bind_param('is', $uid, $todayStr);
$existsCheck->execute();
$alreadyNotified = $existsCheck->get_result()->fetch_assoc();
$existsCheck->close();
if (!$alreadyNotified) {
    $weatherUrl = "https://api.open-meteo.com/v1/forecast?latitude={$userLat}&longitude={$userLng}&current=weather_code,precipitation_probability&timezone=Asia%2FManila&forecast_days=1";
    $ctx = stream_context_create(['http' => ['timeout' => 4]]);
    $weatherRaw = @file_get_contents($weatherUrl, false, $ctx);
    if ($weatherRaw !== false) {
        $weatherData = json_decode($weatherRaw, true);
        $code = $weatherData['current']['weather_code'] ?? null;
        $rainChance = $weatherData['current']['precipitation_probability'] ?? null;
        $isStorm = $code !== null && $code >= 95;
        $isHeavyRain = ($rainChance !== null && $rainChance >= 70) || ($code !== null && $code >= 65 && $code <= 82);
        if ($isStorm || $isHeavyRain) {
            $title = $isStorm ? 'Thunderstorm Advisory' : 'Heavy Rain Advisory';
            $desc = $isStorm
                ? 'Thunderstorms are likely today. Consider postponing field activities.'
                : "There's a {$rainChance}% chance of rain today — plan irrigation and field work accordingly.";
            $notifStmt = $conn->prepare(
                "INSERT INTO notifications (user_id, title, description, status, starred, type, notif_date)
                 VALUES (?, ?, ?, 'unread', 0, 'weather', ?)"
            );
            $notifStmt->bind_param('isss', $uid, $title, $desc, $todayStr);
            $notifStmt->execute();
            $notifStmt->close();
        }
    }
}

$firstName   = explode(' ', trim($user['name']))[0];
$locationStr = $user['location'] ?? 'Philippines';
$locParts    = array_map('trim', explode(',', $locationStr));
$locationTown = $locParts[0] ?? 'Bustos';
$locationProv = $locParts[1] ?? 'Bulacan';
$jsUser = json_encode([
    'name' => $user['name'],
    'email' => $user['email'],
    'location' => $locationStr,
    'locationTown' => $locationTown,
    'locationProvince' => $locationProv,
    'locationLat' => $userLat,
    'locationLng' => $userLng,
    'hectares' => $user['hectares'] ?? 1
]);
$jsRecords       = json_encode($records);
$jsReminders     = json_encode($reminders);
$jsActiveCycles  = json_encode($activeCycles);
$fvFields = $conn->prepare("SELECT DISTINCT location FROM farm_records WHERE user_id=? AND location IS NOT NULL AND location <> '' ORDER BY location ASC");
$fvFields->bind_param('i', $uid);
$fvFields->execute();
$knownFieldsViz = array_column($fvFields->get_result()->fetch_all(MYSQLI_ASSOC), 'location');
$fvFields->close();
$jsFieldLayout   = json_encode($fieldLayout);
$jsKnownFieldsViz = json_encode($knownFieldsViz);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RiceWise - Dashboard</title>
    <link rel="icon" type="image/png" href="pictures/RiceWise_Logo.png">
    <script>
    if (window.innerWidth > 768 && localStorage.getItem('sidebarCollapsed') === '1') {
        document.documentElement.classList.add('sidebar-pre-collapse')
    }
        </script>
        <style>
            html.sidebar-pre-collapse body {
                overflow: hidden
            }
            html.sidebar-pre-collapse .sidebar {
                width: 68px !important;
                transition: none !important
            }
            html.sidebar-pre-collapse .logo-wrap,
            html.sidebar-pre-collapse .nav-label,
            html.sidebar-pre-collapse .nav-text,
            html.sidebar-pre-collapse .signout-text,
            html.sidebar-pre-collapse .toggle-btn {
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="forecast-algorithms.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
	<link href="https://fonts.googleapis.com/css2?family=Lora:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">    <style>
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
            --text-primary: #1e2a1a;
            --text-secondary: #5a6655;
            --text-muted: #94a18e;
            --border: #ddd8cf;
            --border-light: #ede8e0;
            --white: #ffffff;
            --plum: #8a5a8f;
    		--plum-pale: #f3e9f4;
            --teal: #2f6f6a;
			--teal-pale: #e3f0ef;
            --shadow-sm: 0 2px 8px rgba(90, 122, 82, .08);
            --shadow-md: 0 6px 24px rgba(90, 122, 82, .12);
            --shadow-lg: 0 12px 40px rgba(90, 122, 82, .18);
            --radius: 16px;
            --radius-sm: 10px;
            --sidebar-w: 252px;
            --header-h: 64px;
            --gap: 16px
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

        body.sidebar-collapsed .sidebar {
            width: 68px
        }

        body.sidebar-collapsed .expand-btn {
            display: none
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

        body.sidebar-collapsed .sidebar-top {
            padding: 16px 0;
            justify-content: center
        }
        
        body.sidebar-collapsed .toggle-btn {
            margin-left: 0
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
            gap: 0;
            border-radius: var(--radius-sm)
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
        
        body.sidebar-collapsed .logo-icon {
            width: 68px;
            height: 68px;
            background: none;
            box-shadow: none;
            border-radius: 0
        }

        body.sidebar-collapsed .logo-icon img {
            width: 48px;
            height: 48px
        }

        .sidebar-top {
            height: var(--header-h);
            padding: 0 16px 0 8px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            flex-shrink: 0;
            transition: padding .3s cubic-bezier(.4,0,.2,1)
        }

        .logo-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            min-width: 0;
            flex: 1;
            text-decoration: none
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

        .logo-wrap:hover .logo-icon {
            transform: scale(1.08)
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
        
        .logo-wrap,
        .nav-text,
        .signout-text {
            opacity: 1;
            transform: translateX(0);
            max-width: 300px;
            overflow: hidden;
            transition: opacity .22s ease, transform .22s ease, max-width .22s ease
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
        
        .dash-icon {
            transition: fill .18s, stroke .18s
        }

        .nav-item.active .dash-icon {
            fill: currentColor;
            stroke: none
        }

        .sidebar-bottom {
            padding: 10px 12px 16px;
            border-top: 1px solid var(--border);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            transition: padding .3s cubic-bezier(.4,0,.2,1)
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
            width: 14px !important;
            height: 14px !important
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

        /* themed scrollbar, matching .page-scroll */
        .nd-list::-webkit-scrollbar {
            width: 4px
        }
        .nd-list::-webkit-scrollbar-thumb {
            background: var(--cream-3);
            border-radius: 2px
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
            padding: 20px 24px 60px;
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
        
        .pm-left::-webkit-scrollbar,
        .pm-right::-webkit-scrollbar,
        .wm-left::-webkit-scrollbar,
        .wm-right::-webkit-scrollbar,
        .rm-left::-webkit-scrollbar,
        .rm-right::-webkit-scrollbar {
            width: 6px
        }

        .pm-left::-webkit-scrollbar-track,
        .pm-right::-webkit-scrollbar-track,
        .wm-left::-webkit-scrollbar-track,
        .wm-right::-webkit-scrollbar-track,
        .rm-left::-webkit-scrollbar-track,
        .rm-right::-webkit-scrollbar-track {
            background: transparent
        }

        .pm-left::-webkit-scrollbar-thumb,
        .pm-right::-webkit-scrollbar-thumb,
        .wm-left::-webkit-scrollbar-thumb,
        .wm-right::-webkit-scrollbar-thumb,
        .rm-left::-webkit-scrollbar-thumb,
        .rm-right::-webkit-scrollbar-thumb {
            background: var(--cream-3);
            border-radius: 3px
        }

        .pm-left::-webkit-scrollbar-thumb:hover,
        .pm-right::-webkit-scrollbar-thumb:hover,
        .wm-left::-webkit-scrollbar-thumb:hover,
        .wm-right::-webkit-scrollbar-thumb:hover,
        .rm-left::-webkit-scrollbar-thumb:hover,
        .rm-right::-webkit-scrollbar-thumb:hover {
            background: var(--sage-light)
        }

        .welcome-banner {
            background: linear-gradient(155deg, #23361f 0%, #3d5c38 42%, #8a7a3e 78%, #c8963e 100%);
            border-radius: var(--radius);
            padding: 44px 30px 26px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            position: relative;
            overflow: hidden;
            flex-shrink: 0;
            min-height: 128px
        }

        .banner-terraces {
            position: absolute;
            left: 0;
            bottom: -1px;
            width: 100%;
            height: 84px;
            pointer-events: none
        }

        .banner-sky {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 36px;
            pointer-events: none;
            z-index: 0
        }

        .banner-sky svg {
            position: absolute;
            top: 6px;
            left: 6%;
            width: 88%;
            height: 26px;
            overflow: visible
        }

        .banner-sun {
            position: absolute;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: radial-gradient(circle at 35% 30%, #fff3d4, #f4c45c 60%, #e0a83c);
            box-shadow: 0 0 12px 2px rgba(244, 196, 92, .55);
            transition: left 1s linear, top 1s linear, background .6s, box-shadow .6s;
            z-index: 1
        }

        .banner-sun.is-night {
            background: radial-gradient(circle at 35% 30%, #eef2fb, #c7d3ea 60%, #9fb0cf);
            box-shadow: 0 0 9px 2px rgba(199, 211, 234, .45)
        }

        .welcome-eyebrow {
            font-family: 'DM Mono', monospace;
            font-size: 10.5px;
            font-weight: 500;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, .55);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px
        }

        .welcome-eyebrow::before {
            content: '';
            width: 14px;
            height: 1px;
            background: rgba(255, 255, 255, .4)
        }

        .welcome-text {
            position: relative;
            z-index: 1
        }

        .welcome-text h1 {
            font-family: 'Lora', serif;
            font-size: 26px;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 5px;
            letter-spacing: -.3px
        }

        .welcome-text p {
            font-size: 13.5px;
            color: rgba(255, 255, 255, .68)
        }

        .field-log-stamp {
            position: relative;
            z-index: 1;
            flex-shrink: 0;
            background: rgba(255, 255, 255, .1);
            border: 1px solid rgba(255, 255, 255, .22);
            border-radius: 10px;
            padding: 9px 16px 10px;
            text-align: center
        }

        .fls-eyebrow {
            font-family: 'DM Mono', monospace;
            font-size: 8.5px;
            font-weight: 600;
            letter-spacing: 1.6px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, .55);
            margin-bottom: 3px
        }

        .fls-date {
            font-family: 'DM Mono', monospace;
            font-size: 13.5px;
            font-weight: 700;
            color: #fff;
            white-space: nowrap
        }

        .fls-time {
            font-family: 'DM Mono', monospace;
            font-size: 11px;
            color: rgba(255, 255, 255, .72);
            margin-top: 1px
        }

        @media(max-width:768px) {
            .welcome-banner {
                padding: 22px 18px 60px;
                flex-direction: column;
                align-items: flex-start;
                gap: 11px
            }

            .field-log-stamp {
                align-self: flex-start;
                transform: rotate(-1deg)
            }

            .welcome-text h1 {
                font-size: 20px
            }

            .banner-terraces {
                height: 56px
            }
        }

        .welcome-dt {
            display: flex;
            align-items: center;
            gap: 14px;
            z-index: 1;
            flex-shrink: 0
        }

        .dt-chip {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
            color: rgba(255, 255, 255, .82)
        }

        .dt-chip svg {
            width: 14px;
            height: 14px;
            stroke: rgba(255, 255, 255, .6);
            flex-shrink: 0
        }

        .dt-sep {
            width: 1px;
            height: 18px;
            background: rgba(255, 255, 255, .22)
        }

        .card {
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            transition: box-shadow .22s, transform .22s;
            cursor: pointer
        }

        .card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px)
        }

        .top-row {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: var(--gap);
            align-items: stretch
        }

        .top-row-right {
            display: flex;
            flex-direction: column;
            gap: var(--gap)
        }

        .top-row-right .card {
            flex: 1;
            min-height: 0
        }

        .bottom-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: var(--gap)
        }

        .profit-card {
            padding: 22px 22px 0;
            padding-left: 28px;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
            gap: 4px
        }

        .profit-card-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 7px
        }

        .profit-card-header h3 {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--text-muted)
        }

        .live-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--sage-pale);
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            color: var(--sage-dark)
        }

        .live-badge::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--sage);
            box-shadow: 0 0 0 2px rgba(90, 122, 82, .2)
        }

        .profit-amount {
            font-family: 'DM Mono', monospace;
            font-size: 40px;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -1.5px;
            line-height: 1.1;
            margin-bottom: 7px;
            display: flex;
            align-items: baseline;
            gap: 3px
        }

        .profit-amount .php-sign {
            font-family: 'DM Sans', sans-serif;
            font-size: 0.68em;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: 0
        }

        .profit-chart-tag {
            position: absolute;
            top: 10px;
            right: 14px;
            display: none;
            align-items: center;
            gap: 5px;
            background: rgba(255, 255, 255, .88);
            border: 1px solid var(--border-light);
            border-radius: 20px;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 700;
            color: var(--sage-dark);
            box-shadow: var(--shadow-sm);
            z-index: 2
        }

        .profit-chart-tag svg {
            width: 11px;
            height: 11px
        }

        .profit-chart-tag.is-down {
            color: #c0392b
        }

        .profit-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px
        }

        .profit-change {
            background: var(--sage-pale);
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            color: var(--sage-dark)
        }

        .profit-sub {
            font-size: 13px;
            color: var(--text-muted)
        }

        .profit-chart-wrap {
            flex: 1;
            min-height: 130px;
            max-height: 200px;
            width: calc(100% + 44px);
            margin-left: -22px;
            margin-right: -22px;
            margin-top: auto;
            position: relative;
            border-radius: 0 0 var(--radius) var(--radius);
            overflow: hidden
        }

        .profit-chart-wrap canvas {
            display: block;
            width: 100% !important;
            height: 100% !important
        }

        .info-card {
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            gap: 9px
        }

        .info-card-head {
            display: flex;
            align-items: center;
            gap: 11px
        }
        

        .icon-box {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1.5px solid transparent
        }

        .icon-box svg {
            width: 20px;
            height: 20px
        }
        
        .icon-box.icon-weather {
            background: none;
            border: none;
            box-shadow: none;
            width: auto;
            height: auto
        }

        .icon-weather {
            background: var(--sky-pale);
            border-color: rgba(74, 144, 217, .18)
        }

        .icon-weather svg {
            width: 30px;
            height: 30px;
            fill: var(--sky-pale);
            stroke: var(--sky)
        }

        .icon-rice {
            background: var(--sage-pale)   /* this rule is currently unused since icon-box.icon-rice overrides it below — leaving as-is */
        }

        .icon-box.icon-rice {
            background: none;
            border: none;
            box-shadow: none;
            width: auto;
            height: auto
        }

        .icon-rice svg {
            width: 30px;
            height: 30px;
            fill: #f3e4dc;              /* CHANGED — was var(--sage-pale), now pale terracotta */
            stroke: #b5563b              /* CHANGED — was var(--sage), now rust/terracotta */
        }

        .icon-remind {
            background: none;
            border: none;
            box-shadow: none;
            width: auto;
            height: auto
        }

        .icon-remind svg {
            width: 30px;
            height: 30px;
            fill: var(--plum-pale);      /* CHANGED — was none/gold, now distinct plum */
            stroke: var(--plum)          /* CHANGED — was var(--gold) */
        }

        .card-lbl {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .9px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 1px
        }

        .card-ttl {
            font-family: 'Lora', serif;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .weather-temp {
            font-family: 'DM Mono', monospace;
            font-size: 30px;
            font-weight: 700;
            color: var(--sky);
            line-height: 1;
            background: var(--sky-pale);
            padding: 6px 14px;
            border-radius: 20px;
            border: 1px solid rgba(74, 144, 217, .18)
        }

        .weather-desc {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 24px
        }
        
        .weather-desc svg {
            stroke: #7fa8d4;
            flex-shrink: 0
        }

        .mini-chart {
            height: 90px;
            position: relative
        }

        .price-blocks {
            display: flex;
            gap: 16px;
            margin-top: 15px
        }

        .price-block {
            flex: 1;
            background: var(--cream-2);
            padding: 9px 10px;
            border-radius: var(--radius-sm);
            text-align: center;
            border-top: 3px solid transparent
        }

        .price-block.is-current {
            border-top-color: var(--gold)
        }

        .price-block.is-standard {
            border-top-color: var(--sage)
        }
        
        .price-blocks + .mini-chart {
            margin-top: -8px
        }

        .pb-label {
            font-size: 11px;
            color: var(--text-muted);
            margin-bottom: 2px
        }

        .pb-value {
            font-family: 'DM Mono', monospace;
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: baseline;
            justify-content: center;
            gap: 2px
        }

        .pb-value .php-sign-sm {
            font-family: 'DM Sans', sans-serif;
            font-size: 0.72em;
            font-weight: 700
        }

        .activity-card {
            padding: 20px 20px 22px;
            display: flex;
            flex-direction: column;
            gap: 20px;   /* CHANGED — was 14px */
            justify-content: flex-start;
            text-align: center
        }
        
        .icon-box.icon-activity {
            background: none;
            border: none;
            box-shadow: none;
            width: auto;
            height: auto
        }

        .icon-activity svg {
            width: 30px;
            height: 30px; 	
            fill: var(--sage-pale);
            stroke: var(--sage)
        }

        .activity-ring-center {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 14px;
            padding: 4px 0;
            margin-top: 10px;   /* NEW — extra breathing room below the header row */
        }

        .act-nav-btn {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            border: 1px solid var(--border);
            background: var(--cream-2);
            color: var(--sage-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            transition: background .18s, border-color .18s, transform .15s
        }

        .act-nav-btn:hover {
            background: var(--sage-pale);
            border-color: var(--sage-light);
            transform: scale(1.08)
        }

        .act-nav-btn svg {
            width: 15px;
            height: 15px
        }

        .activity-dots {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-bottom: 8px
        }

        .act-dot-nav {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--cream-3);
            transition: background .18s, transform .18s
        }

        .act-dot-nav.active {
            background: var(--sage);
            transform: scale(1.25)
        }

        .activity-ring-center .ring-wrap svg {
            filter: drop-shadow(0 4px 10px rgba(90, 122, 82, .2))
        }

        .activity-info-center {
            text-align: center
        }

        .activity-info-center h3 {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 10px;
            line-height: 1.4
        }

        .activity-chips {
            display: flex;
            gap: 6px;
            justify-content: center;
            flex-wrap: wrap
        }

        .view-btn-improved {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 46px;              /* NEW — explicit height instead of relying on padding */
            padding: 0 20px;           /* CHANGED — was 12px 20px, vertical spacing now comes from height */
            background: var(--cream-2);
            color: var(--sage-dark);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            box-shadow: none;
            transition: background .18s, border-color .18s
        }

        .view-btn-improved:hover {
            background: var(--sage-pale);
            border-color: var(--sage-light);
            box-shadow: none;
        }

        .ring-wrap {
            position: relative;
            width: 86px;
            height: 86px;
            flex-shrink: 0
        }

        .ring-wrap svg {
            transform: rotate(-90deg)
        }

        .ring-wrap circle {
            fill: none;
            stroke-width: 9
        }

        .ring-bg {
            stroke: var(--cream-3)
        }

        .ring-fill {
            stroke: var(--sage);
            stroke-linecap: round;
            transition: stroke-dashoffset .9s cubic-bezier(.4, 0, .2, 1)
        }

        .ring-label {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'DM Mono', monospace;
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .activity-info h3 {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            line-height: 1.45;
            margin-bottom: 9px
        }

        .view-btn {
            display: inline-block;
            padding: 9px 22px;
            background: var(--sage);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: background .18s, transform .18s;
            text-decoration: none
        }

        .view-btn:hover {
            background: var(--sage-dark);
            transform: translateY(-1px)
        }

        .recent-card {
            padding: 18px 20px;
            display: flex;              /* NEW */
            flex-direction: column;      /* NEW */
            height: 100%                  /* NEW — stretches to match the grid row height */
        }
        
        #recentList {
            display: flex;              /* NEW */
            flex-direction: column;      /* NEW */
            flex: 1;                       /* NEW — fills remaining space so foot gets pushed down */
        }

        .recent-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px
        }

        .recent-head .icon-box {
            width: 44px;       /* CHANGED — was 34px */
            height: 44px;       /* CHANGED — was 34px */
            background: var(--sage-pale);
            border-radius: 12px;   /* CHANGED — was 9px, scales with bigger box */
        }

        .recent-head .icon-box.icon-remind svg {
            width: 30px;
            height: 30px;
            stroke: var(--plum)
        }
        
        .recent-head .icon-box.icon-recent svg {
            width: 30px;      /* CHANGED — overrides the 30px/sage rule above with matching specificity + more specific class chain */
            height: 30px;
            stroke: var(--gold);
        }

        .recent-title {
            font-family: 'Lora', serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary)
        }
        
        .icon-box.icon-recent,
        .icon-box.icon-remind {
            background: none;
            border: none;
            box-shadow: none;
            width: auto;
            height: auto
        }

        .icon-remind svg {
            width: 30px;
            height: 30px;
            fill: none;
            stroke: var(--plum)         /* make sure this is present and not overridden below it */
        }
        
        .act-list {
            list-style: none
        }

        .act-item {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-light)
        }

        .act-item:last-child {
            border-bottom: none
        }

        .act-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--sage-light);
            flex-shrink: 0
        }

        .act-text {
            font-size: 13px;
            color: var(--text-secondary)
        }

        .reminders-card {
            padding: 18px 20px
        }
        
        .reminders-card .recent-head {
            margin-bottom: 30px;    /* ADD — more breathing room before the reminders list */
        }

        .reminder-item {
            display: flex;
            align-items: flex-start;      /* CHANGED — was center, so top-right badge aligns to top */
            padding: 10px 12px;
            background: var(--cream-2);
            border-radius: var(--radius-sm);
            margin-bottom: 5px;
            transition: background .18s;
            cursor: pointer
        }

        .reminder-main {
            flex: 1;
            min-width: 0;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px
        }

        .reminder-title-wrap {
            min-width: 0
        }

        .reminder-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary)
        }

        .reminder-date {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px
        }

        .reminder-badge {
            padding: 2px 8px;
            background: var(--gold);
            color: white;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
            flex-shrink: 0
        }

        .no-reminders {
            font-size: 13px;
            color: var(--text-muted);
            text-align: center;
            padding: 10px 0
        }

        .modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 2000;
            background: rgba(20, 35, 18, .48);
            backdrop-filter: blur(5px);
            align-items: center;
            justify-content: center;
            padding: 16px
        }

        .modal.show {
            display: flex;
            animation: fadeIn .2s ease
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
            max-width: 560px;
            max-height: 88vh;
            overflow-y: auto;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-lg);
            animation: slideUp .24s ease
        }

        @keyframes slideUp {
            from {
                transform: translateY(22px);
                opacity: 0
            }

            to {
                transform: translateY(0);
                opacity: 1
            }
        }

        .modal-head {
            padding: 20px 22px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            justify-content: space-between;
            align-items: center
        }

        .modal-title {
            font-family: 'Lora', serif;
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .modal-close {
            width: 32px;
            height: 32px;
            background: var(--cream-2);
            border: none;
            border-radius: 8px;
            font-size: 17px;
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all .18s
        }

        .modal-close:hover {
            background: var(--cream-3)
        }

        .modal-body {
            padding: 20px 22px
        }

        .modal-foot {
            padding: 14px 22px;
            border-top: 1px solid var(--border-light);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            align-items: center
        }

        .modal-btn {
            padding: 10px 22px;
            background: var(--sage);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: background .18s
        }

        .modal-btn:hover {
            background: var(--sage-dark)
        }

        .detail-item {
            padding: 10px 11px;
            background: var(--cream-2);
            border-radius: var(--radius-sm);
            margin-bottom: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center
        }

        .d-lbl {
            font-size: 13px;
            color: var(--text-secondary)
        }

        .d-val {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--text-primary);
            font-family: 'DM Mono', monospace
        }

        .act-modal-head {
            background: linear-gradient(135deg, var(--sage-dark), var(--sage));
            border-radius: var(--radius) var(--radius) 0 0;
            padding: 20px 22px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start
        }

        .act-modal-sub {
            font-size: 10px;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, .58);
            margin-bottom: 4px
        }

        .act-modal-title {
            font-family: 'Lora', serif;
            font-size: 20px;
            font-weight: 700;
            color: white
        }

        .act-modal-close {
            width: 36px;
            height: 36px;
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: opacity .18s;
            flex-shrink: 0;
            z-index: 1
        }

         .act-modal-close:hover {
            background: none;
            opacity: .7
        }

        .act-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 9px;
            margin-bottom: 14px
        }

        .act-stat {
            border-radius: 11px;
            padding: 13px;
            text-align: center;
            border: 1px solid
        }

        .act-stat.c {
            background: var(--sage-pale);
            border-color: rgba(90, 122, 82, .14)
        }

        .act-stat.a {
            background: var(--gold-pale);
            border-color: rgba(200, 150, 62, .14)
        }

        .act-stat.u {
            background: var(--cream-2);
            border-color: var(--border)
        }

        .act-stat-n {
            font-family: 'DM Mono', monospace;
            font-size: 26px;
            font-weight: 700
        }

        .act-stat.c .act-stat-n {
            color: var(--sage-dark)
        }

        .act-stat.a .act-stat-n {
            color: var(--gold)
        }

        .act-stat.u .act-stat-n {
            color: var(--text-secondary)
        }

        .act-stat-l {
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .9px;
            margin-top: 2px
        }
        
        .activity-ring-center .ring-wrap svg {
            filter: drop-shadow(0 6px 14px rgba(90, 122, 82, .28))
        }

        .activity-ring-center .ring-bg {
            stroke: var(--cream-2)
        }

        .activity-ring-center .ring-fill {
            stroke: url(#ringGradient)
        }
        
        .act-row {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 10px 4px;
            border-radius: var(--radius-sm);
            transition: background .15s
        }

        .act-row:hover {
            background: var(--cream-2)
        }

        .act-row + .act-row {
            border-top: 1px solid var(--border-light)
        }

        .act-icon-badge {
            width: 32px;       /* REVERTED — back to original */
            height: 32px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0
        }

        .act-icon-badge svg {
            width: 16px;       /* REVERTED — back to original */
            height: 16px;
        }

        .act-row-main {
            flex: 1;
            min-width: 0
        }

        .act-row-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .act-row-sub {
            font-size: 11.5px;
            color: var(--text-muted);
            margin-top: 1px
        }

        .act-row-date {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-muted);
            font-family: 'DM Mono', monospace;
            flex-shrink: 0;
            white-space: nowrap
        }

        .recent-card-foot {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 46px;
            padding: 0 9px;
            margin-top: 40px;              /* CHANGED — was 6px, pushes button to bottom of available space */
            background: var(--cream-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 12.5px;
            font-weight: 600;
            color: var(--sage-dark);
            text-decoration: none;
            transition: background .18s, border-color .18s
        }

        .recent-card-foot:hover {
            background: var(--sage-pale);
            border-color: var(--sage-light)
        }

        .cycle-summary-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 15px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-light);
            margin-bottom: 8px;
            transition: all .18s;
            cursor: pointer;
            text-decoration: none;
            display: flex
        }

        .cycle-summary-row:hover {
            border-color: var(--sage-light);
            box-shadow: var(--shadow-sm)
        }

        .cycle-summary-ring {
            position: relative;
            width: 44px;
            height: 44px;
            flex-shrink: 0
        }

        .cycle-summary-ring svg {
            transform: rotate(-90deg)
        }

        .cycle-summary-ring circle {
            fill: none;
            stroke-width: 5
        }

        .cycle-summary-ring .bg {
            stroke: var(--cream-3)
        }

        .cycle-summary-ring .fill {
            stroke: var(--sage);
            stroke-linecap: round
        }

        .cycle-summary-pct {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'DM Mono', monospace;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .cycle-summary-info {
            flex: 1;
            min-width: 0
        }

        .cycle-summary-field {
            font-family: 'Lora', serif;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .cycle-summary-step {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px
        }

        .cycle-summary-arrow {
            color: var(--text-muted);
            font-size: 16px;
            flex-shrink: 0
        }

        .no-cycles-modal {
            text-align: center;
            padding: 30px 20px
        }

        .no-cycles-modal-icon {
            font-size: 40px;
            margin-bottom: 10px;
            opacity: .6
        }

        .no-cycles-modal-text {
            font-size: 13.5px;
            color: var(--text-muted);
            margin-bottom: 18px
        }

        #weatherModal .modal-box,
        #riceModal .modal-box,
        #profitModal .modal-box {
            max-width: 1020px;
            max-height: 92vh;
            height: 92vh;
            overflow: hidden;
            display: flex;
            flex-direction: column
        }

        .wm-header {
            background: linear-gradient(135deg, #1b3d5f 0%, #2a5c8a 45%, #4a90d9 100%);
            border-radius: var(--radius) var(--radius) 0 0;
            padding: 20px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            overflow: hidden;
            flex-shrink: 0
        }
        
        .wm-header-top {
            display: flex;
            align-items: center;
            gap: 2px
        }

        .wm-header-icon {
            width: 46px;
            height: 46px;
            background: rgba(255, 255, 255, .16);
            border: 1px solid rgba(255, 255, 255, .28);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-right: 12px;
            box-shadow: inset 0 1px 2px rgba(255,255,255,.15), 0 2px 6px rgba(0,0,0,.15)
        }

        .wm-header-icon svg {
            width: 24px;
            height: 24px;
            stroke: white;
            fill: none;
            stroke-width: 2.2;
            filter: drop-shadow(0 1px 2px rgba(0, 0, 0, .2))
        }

        .wm-hdr-sup {
            font-size: 11px;
            letter-spacing: 1.8px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, .55);
            margin-bottom: 5px
        }

        .wm-hdr-title {
            font-family: 'Lora', serif;
            font-size: 26px;
            font-weight: 700;
            color: white;
            position: relative;
            z-index: 1
        }

        .wm-close-btn {
            width: 36px;
            height: 36px;
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: opacity .18s;
            flex-shrink: 0;
            z-index: 1
        }

        .wm-close-btn:hover {
            background: none;
            opacity: .7
        }

        .wm-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            border-bottom: 1px solid var(--border-light);
            flex-shrink: 0
        }

        .wm-stat {
            padding: 18px 12px;
            text-align: center;
            border-right: 1px solid var(--border-light)
        }

        .wm-stat:last-child {
            border-right: none
        }

        .wm-stat-val {
            font-family: 'DM Mono', monospace;
            font-size: 32px;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 4px
        }

        .wm-stat-lbl {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--text-muted)
        }

        .wm-extra-inline {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            background: #faf8f5;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            overflow: hidden;
            flex-shrink: 0
        }

        .wm-extra-item-inline {
            padding: 10px 12px;
            border-right: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: 9px
        }

        .wm-extra-item-inline:last-child {
            border-right: none
        }

        .wm-extra-ico {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: var(--cream-2) !important;
        }

        .wm-extra-ico svg {
            stroke: var(--text-secondary) !important;
        }

        .wm-extra-ico.ico-gold { background: var(--gold) !important }
        .wm-extra-ico.ico-gold svg { stroke: #fff !important }

        .wm-extra-ico.ico-sage { background: var(--sage) !important }
        .wm-extra-ico.ico-sage svg { stroke: #fff !important }

        .wm-extra-ico.ico-plum { background: var(--plum) !important }
        .wm-extra-ico.ico-plum svg { stroke: #fff !important }
        
        .wm-extra-ico.ico-sunset { background: #e0a53c !important }
        .wm-extra-ico.ico-sunset svg { stroke: #fff !important }

        .wm-extra-ico.ico-uv { background: #d9724a !important }
        .wm-extra-ico.ico-uv svg { stroke: #fff !important }

        .wm-extra-ico.ico-wind { background: var(--sky) !important }
        .wm-extra-ico.ico-wind svg { stroke: #fff !important }
        
        .wm-extra-ico.ico-sky { background: var(--sky) !important }
		.wm-extra-ico.ico-sky svg { stroke: #fff !important }

    
        .wm-extra-lbl {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .8px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 3px
        }

        .wm-extra-val {
            font-family: 'DM Mono', monospace;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary)
        }

        .wm-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 0;
            flex: 1 1 0;
            overflow: hidden
        }

        .wm-left {
            border-right: 1px solid var(--border-light);
            padding: 20px 22px 24px;
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow-y: auto
        }

        .wm-right {
            padding: 20px 22px 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            overflow-y: auto;
            min-height: 0
        }

        .wm-sec-lbl {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 9px
        }
        .wm-sec-lbl svg {
            width: 14px;
            height: 14px;
            stroke: var(--text-muted);
            stroke-width: 2;
            fill: none;
            flex-shrink: 0
        }

        .wm-layer-tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 9px;
            flex-wrap: wrap
        }

        .wm-layer-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px 5px 10px;
            background: var(--cream-2);
            border: 1px solid var(--border-light);
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-secondary);
            cursor: pointer;
            font-family: inherit;
            transition: all .18s;
            white-space: nowrap
        }

        .wm-layer-btn svg {
            width: 13px;
            height: 13px;
            flex-shrink: 0;
            color: var(--layer-color, var(--text-muted));
            transition: color .18s
        }

        .wm-layer-btn:hover {
            background: color-mix(in srgb, var(--layer-color, var(--sky)) 12%, white);
            border-color: var(--layer-color, var(--sky));
            color: var(--layer-color, var(--sky))
        }

        .wm-layer-btn:hover svg {
            color: var(--layer-color, var(--sky))
        }

        .wm-layer-btn.active {
            background: var(--layer-color, var(--sky));
            border-color: var(--layer-color, var(--sky));
            color: white
        }

        .wm-layer-btn.active svg {
            color: white
        }

        .wm-map-frame {
            border-radius: var(--radius-sm);
            overflow: hidden;
            border: 1px solid var(--border-light);
            flex: 1;
            min-height: 300px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .07)
        }

        .wm-map-frame iframe {
            width: 100%;
            height: 100%;
            display: block;
            border: 0
        }

        .wm-map-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 9px;
            flex-shrink: 0
        }

        .wm-map-coord {
            font-size: 12px;
            color: var(--text-muted)
        }

        .wm-map-link {
            font-size: 12px;
            color: var(--sky);
            font-weight: 600;
            text-decoration: none
        }

        .wm-fc-scroll {
            overflow-x: auto;
            padding-bottom: 5px
        }

        .wm-fc-strip {
            display: flex;
            gap: 5px;
            min-width: max-content
        }

        .wm-fc-card {
            width: 64px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-light);
            text-align: center;
            padding: 9px 5px 8px;
            background: var(--cream);
            transition: transform .15s, box-shadow .15s
        }

        .wm-fc-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, .08)
        }

        .wm-fc-card.today {
            background: linear-gradient(160deg, #1e4d80, #4a90d9);
            border-color: transparent
        }

        .wm-fc-dname {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .4px;
            text-transform: uppercase
        }

        .wm-fc-ddate {
            font-size: 9px;
            opacity: .55;
            margin-bottom: 3px
        }

        .wm-fc-icon {
            font-size: 20px;
            display: block;
            margin: 3px 0
        }

        .wm-fc-hi {
            font-family: 'DM Mono', monospace;
            font-size: 14px;
            font-weight: 700
        }

        .wm-fc-lo {
            font-family: 'DM Mono', monospace;
            font-size: 11px;
            opacity: .6
        }

        .wm-fc-rain {
            font-size: 10px;
            font-weight: 600;
            margin-top: 4px;
            padding: 2px 5px;
            border-radius: 5px;
            display: inline-block
        }

        .wm-adv-list {
            display: flex;
            flex-direction: column;
            gap: 7px
        }

        .wm-adv-item {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 14px 16px;
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            transition: transform .15s, box-shadow .15s
        }

        .wm-adv-item:hover {
            transform: translateX(2px);
            box-shadow: var(--shadow-sm)
        }

        .wm-adv-icon-badge {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--cream-2)
        }

        .wm-adv-icon-badge svg {
            width: 16px;
            height: 16px;
            stroke: var(--text-secondary);
            stroke-width: 2;
            fill: none;
            color: var(--adv-color, var(--text-secondary));
    		stroke: currentColor
        }

        .wm-adv-title {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 3px
        }

        .wm-adv-text {
            font-size: 12.5px;
            color: var(--text-secondary);
            line-height: 1.55
        }

        .rm-header {
            background: linear-gradient(135deg, #5a3a0a 0%, #c8963e 55%, #e8b96a 100%);
            border-radius: var(--radius) var(--radius) 0 0;
            padding: 20px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            overflow: hidden;
            flex-shrink: 0
        }
        
        .rm-header-top {
            display: flex;
            align-items: center;
            gap: 2px
        }

        .rm-header-icon {
            width: 42px;
            height: 42px;
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.28);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-right: 10px;
            box-shadow: inset 0 1px 2px rgba(255,255,255,.15), 0 2px 6px rgba(0,0,0,.15)
        }

        .rm-header-icon svg {
            width: 26px;
            height: 26px;
            stroke: white;
            fill: none;
            filter: drop-shadow(0 1px 3px rgba(0, 0, 0, .25))
        }
        
        .rm-header::before {
            content: '';
            position: absolute; top: -55%; right: -8%;
            width: 260px; height: 260px;
            background: radial-gradient(circle, rgba(255,233,179,.22) 0%, transparent 72%);
            pointer-events: none
        }
        .rm-header::after {
            content: '';
            position: absolute; inset: 0;
            background-image: radial-gradient(rgba(255,255,255,.09) 1px, transparent 1px);
            background-size: 16px 16px; opacity: .5;
            pointer-events: none
        }
        .rm-header-top, .rm-header > .wm-close-btn { position: relative; z-index: 1 }

        .rm-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            border-bottom: 1px solid var(--border-light);
            flex-shrink: 0
        }

        .rm-stat {
            padding: 18px 12px;
            text-align: center;
            border-right: 1px solid var(--border-light)
            transition: background .18s
        }
        
        .rm-stat:hover { background: var(--cream-2) }

        .rm-stat:last-child {
            border-right: none
        }

        .rm-stat-val {
            font-family: 'DM Mono', monospace;
            font-size: 32px;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 4px
        }

        .rm-stat-lbl {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--text-muted)
        }

        .rm-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 0;
            flex: 1 1 0;
            overflow: hidden
        }

        .rm-left {
            border-right: 1px solid var(--border-light);
            padding: 20px 22px 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            overflow-y: auto;
            min-height: 0
        }

        .rm-right {
            padding: 20px 22px 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            overflow-y: auto;
            min-height: 0
        }

        .rm-sec-lbl {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 11px
        }

        .rm-sec-lbl svg {
            width: 14px;
            height: 14px;
            stroke: var(--text-muted);
            stroke-width: 2;
            fill: none;
            flex-shrink: 0
        }

        .rm-chart-wrap {
            flex: 1;
            min-height: 200px;
            position: relative
        }

        .rm-chart-tab-row {
            display: flex;
            gap: 5px;
            margin-bottom: 9px;
            flex-wrap: wrap
        }

        .rm-chart-tab {
            padding: 5px 11px;
            background: var(--cream-2);
            border: 1px solid var(--border-light);
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-secondary);
            cursor: pointer;
            font-family: inherit;
            transition: all .18s
        }

        /* NEW — hover for inactive tabs */
        .rm-chart-tab:not(.active):hover {
            background: var(--gold-pale);
            border-color: var(--gold);
            color: #a06a1e
        }

        .rm-chart-tab.active {
            background: var(--gold);
            border-color: var(--gold);
            color: white
        }

        /* NEW — subtle hover feedback even on the active tab */
        .rm-chart-tab.active:hover {
            background: #b5822f;
            border-color: #b5822f
        }

        .rm-msrp-list {
            display: flex;
            flex-direction: column;
            gap: 5px
        }

        .rm-msrp-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 13px;
            background: var(--cream-2);
            border-radius: var(--radius-sm);
            transition: transform .15s
        }

        .rm-msrp-row:hover {
            transform: translateX(2px)
        }

        .rm-msrp-row.current {
            background: var(--sage-pale)
        }

        .rm-msrp-row.suspended {
            opacity: .5
        }

        .rm-msrp-row.scheduled {
            background: var(--sky-pale)
        }

        .rm-variety-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px
        }

        .rm-variety-item {
            background: var(--cream-2);
            border-radius: var(--radius-sm);
            padding: 12px 14px;
            border: 1px solid var(--border-light)
        }

        .rm-variety-bar {
            height: 3px;
            border-radius: 2px;
            margin-top: 8px;
            background: var(--cream-3);
            overflow: hidden
        }

        .rm-variety-bar-fill {
            height: 100%;
            border-radius: 2px;
            background: var(--gold);
            transition: width .6s ease
        }

        .pm-header {
            background: linear-gradient(135deg, #1a3a1a 0%, #2f5c2a 35%, #5a9050 100%);
            border-radius: var(--radius) var(--radius) 0 0;
            padding: 20px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            overflow: hidden;
            flex-shrink: 0
        }
        
        .pm-header::before {
            content: '';
            position: absolute;
            top: -55%;
            right: -8%;
            width: 260px;
            height: 260px;
            background: radial-gradient(circle, rgba(180, 220, 140, .22) 0%, transparent 72%);
            pointer-events: none
        }

        .pm-header::after {
            content: '';
            position: absolute;
            inset: 0;
            background-image: radial-gradient(rgba(255, 255, 255, .09) 1px, transparent 1px);
            background-size: 16px 16px;
            opacity: .55;
            pointer-events: none
        }

        .pm-header-top,
        .pm-header > .wm-close-btn {
            position: relative;
            z-index: 1
        }

        .pm-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            border-bottom: 1px solid var(--border-light);
            flex-shrink: 0
        }

        .pm-stat {
            padding: 18px 12px;
            text-align: center;
            border-right: 1px solid var(--border-light);
            transition: background .18s
        }

        .pm-stat:hover {
            background: var(--cream-2)
        }

        .pm-stat:last-child {
            border-right: none
        }

        .pm-stat-val {
            font-family: 'DM Mono', monospace;
            font-size: 28px;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 4px
        }

        .pm-stat-lbl {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .9px;
            text-transform: uppercase;
            color: var(--text-muted)
        }

        .pm-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 0;
            flex: 1 1 0;
            overflow: hidden
        }

        .pm-left {
            border-right: 1px solid var(--border-light);
            padding: 20px 22px 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            overflow-y: auto;
            min-height: 0
        }

        .pm-right {
            padding: 20px 22px 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            overflow-y: auto;
            min-height: 0
        }
        
        .pm-header .wm-close-btn {
            background: none;
            border: none;
            box-shadow: none
        }

        .pm-header .wm-close-btn:hover {
            background: none;
            opacity: .7
        }

        .pm-header-top {
            display: flex;
            align-items: center;
            gap: 2px
        }
        
        .pm-header-icon {
            width: 46px;
            height: 46px;
            background: rgba(255, 255, 255, .16);
            border: 1px solid rgba(255, 255, 255, .28);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-right: 12px;
            box-shadow: inset 0 1px 2px rgba(255,255,255,.15), 0 2px 6px rgba(0,0,0,.15)
        }

        .pm-header-icon svg {
            width: 24px;
            height: 24px;
            stroke: white;
            fill: none;
            stroke-width: 2.2;
            filter: drop-shadow(0 1px 2px rgba(0, 0, 0, .2))
        }

        .pm-sec-lbl {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 10px
        }

        .pm-sec-lbl svg {
            width: 14px;
            height: 14px;
            stroke: var(--text-muted);
            stroke-width: 2;
            fill: none;
            flex-shrink: 0
        }
        
        .pm-adv-item {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 14px 16px;
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            transition: transform .15s, box-shadow .15s
        }

        .pm-adv-item:hover {
            transform: translateX(2px);
            box-shadow: var(--shadow-sm)
        }

        .pm-adv-icon-badge {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--cream-2) !important;
        }

        .pm-adv-icon-badge svg {
            width: 16px;
            height: 16px;
            stroke: white;
            stroke-width: 2;
            fill: none;
            stroke: var(--text-secondary) !important;
        }

        .pm-adv-title {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 3px
        }

        .pm-adv-text {
            font-size: 12.5px;
            color: var(--text-secondary);
            line-height: 1.55
        }
        
        .pm-notice-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 20px;
            background: var(--cream-2);
            border-bottom: 1px solid var(--border-light);
            font-size: 12px;
            color: var(--text-secondary);
            font-weight: 600;
            flex-shrink: 0
        }

        .pm-notice-bar svg {
            stroke: var(--text-muted);
            flex-shrink: 0
        }

        .pm-cost-list {
            display: flex;
            flex-direction: column;
            gap: 5px
        }

        .pm-cost-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            background: var(--cream-2);
            border-radius: var(--radius-sm);
            transition: background .15s, transform .15s
        }

        .pm-cost-row:hover {
            background: var(--cream-3);
            transform: translateX(2px)
        }
        
        
        .pm-cost-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary)
        }

        .pm-cost-sub {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 1px
        }

        .pm-cost-amt {
            font-family: 'DM Mono', monospace;
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .pm-cost-row.total {
            background: var(--sage-pale);
            margin-top: 4px
        }

        .pm-cost-row.total .pm-cost-label {
            font-weight: 700
        }

        .pm-cost-row.total .pm-cost-amt {
            font-size: 16px;
            color: var(--sage-dark)
        }

        .pm-rev-bar-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 13px;
            background: var(--cream-2);
            border-radius: var(--radius-sm);
            margin-bottom: 6px
        }

        .pm-rev-bar-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center
        }

        .pm-rev-bar-icon svg {
            width: 15px;
            height: 15px;
            stroke: white;
            stroke-width: 2.2;
            fill: none;
        }
        
        .pm-rev-bar-icon,
        .pm-adv-icon-badge,
        .pm-price-icon {
            background: var(--cream-3) !important;      /* CHANGED — was var(--cream-2), one shade darker so it stands off the row */
        }
        .pm-rev-bar-icon svg,
        .pm-adv-icon-badge svg,
        .pm-price-icon svg {
            stroke: var(--text-primary) !important;      /* CHANGED — was text-secondary, darker for better icon contrast */
        }
        
        .pm-rev-bar-icon svg {
            stroke: var(--rb-color, var(--text-primary)) !important;
        }

        .pm-rev-bar-main {
            flex: 1;
            min-width: 0
        }

        .pm-rev-bar-label {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 6px
        }

        .pm-rev-bar-label span:last-child {
            font-family: 'DM Mono', monospace;
            font-size: 13px
        }

        .pm-rev-bar-track {
            height: 9px;
            background: var(--cream-3);
            border-radius: 5px;
            overflow: hidden
        }

        .pm-rev-bar-fill {
            height: 100%;
            border-radius: 5px;
            transition: width .8s cubic-bezier(.4,0,.2,1)
        }

        .pm-price-impact {
            display: flex;
            flex-direction: column;
            gap: 8px
        }

        .pm-price-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 15px;
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            transition: transform .15s, box-shadow .15s
        }

        .pm-price-row:hover {
            transform: translateX(2px);
            box-shadow: var(--shadow-sm)
        }

        .pm-price-row.current {
            background: var(--sage-pale);
            border-color: var(--sage-light)
        }

        .pm-price-icon {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--cream-2) !important;
        }

        .pm-price-icon svg {
            width: 16px;
            height: 16px;
            stroke: white;
            stroke-width: 2.3;
            fill: none;
            stroke: var(--text-secondary) !important;
        }
        
        .pm-price-icon svg {
            stroke: var(--pi-color, var(--text-secondary)) !important;
        }

        .pm-adv-icon-badge svg {
            stroke: var(--adv-color, var(--text-secondary)) !important;
        }

        .pm-price-label-wrap {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 2px
        }
        .pm-price-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary)
        }

        .pm-price-sub {
            font-size: 11px;
            color: var(--text-muted)
        }

        .pm-price-right {
            width: 130px;
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 3px;
            flex-shrink: 0
        }

        .pm-price-net {
            font-family: 'DM Mono', monospace;
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .pm-price-margin {
            font-size: 11px;
            color: var(--text-muted)
        }

        .pm-price-delta {
            font-family: 'DM Mono', monospace;
            font-size: 10.5px;
            font-weight: 700;
            padding: 1px 8px;
            border-radius: 10px
        }

        .pm-price-delta.up {
            background: var(--sage-pale);
            color: var(--sage-dark)
        }

        .pm-price-delta.down {
            background: #fdecea;
            color: #c0392b
        }

        .pm-price-baseline-tag {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .5px;
            text-transform: uppercase;
            color: var(--sage-dark)
        }

        .pm-forecast-hero {
            width: 100%;
            background: var(--white);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            border-radius: var(--radius);
            padding: 18px 20px;
            position: relative;
            overflow: hidden
        }

        .pm-forecast-hero::before {
            content: '';
            position: absolute;
            top: -30%;
            right: -10%;
            width: 160px;
            height: 160px;
            background: radial-gradient(circle, rgba(200, 150, 62, .18) 0%, transparent 70%);   /* CHANGED — was rgba(90,122,82,.12) sage, now gold */
            pointer-events: none
        }

        .pm-forecast-hero-top {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            margin-bottom: 8px;
            position: relative;
            z-index: 1
        }

        .pm-forecast-hero-badge {
            display: flex;
            align-items: center;
            gap: 5px;
            background: rgba(90, 122, 82, .12);
            color: var(--sage-dark);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .6px;
            text-transform: uppercase;
            padding: 3px 10px;
            border-radius: 20px
        }

        .pm-forecast-hero-badge svg {
            width: 11px;
            height: 11px;
            stroke: currentColor;
            fill: none
        }

        .pm-forecast-hero-label {
            text-align: center;
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 4px;
            position: relative;
            z-index: 1
        }

        .pm-forecast-hero-val {
            text-align: center;
            font-family: 'DM Mono', monospace;
            font-size: 34px;
            font-weight: 700;
            color: var(--sage-dark);
            line-height: 1.1;
            margin-bottom: 6px;
            position: relative;
            z-index: 1
        }

        .pm-forecast-hero-sub {
            text-align: center;
            font-size: 11px;
            color: var(--text-muted);
            position: relative;
            z-index: 1
        }
        
        .pm-verdict-card {
            position: relative;
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            padding: 18px 20px;
            display: flex;
            gap: 14px;
            align-items: flex-start
        }

        .pm-verdict-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 2px
        }

        .pm-verdict-icon {
            width: 34px;
            height: 34px;
            flex-shrink: 0;
            border-radius: 50%;
            background: var(--pm-verdict-color, var(--sage-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 2px
        }

        .pm-verdict-icon svg {
            width: 17px;
            height: 17px;
            stroke: white;
            stroke-width: 2.3;
            fill: none
        }
        
        .pm-verdict-body {
            flex: 1;
            min-width: 0
        }

        .pm-verdict-badge {
            font-family: 'DM Mono', monospace;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--pm-verdict-color, var(--sage-dark));
            background: var(--pm-verdict-bg, var(--sage-pale));
            padding: 3px 10px;
            border-radius: 20px;
            flex-shrink: 0;
            white-space: nowrap
        }

        .pm-verdict-title {
            font-family: 'Lora', serif;
            font-size: 17px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .pm-verdict-desc {
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.5
        }

        .rf-banner-chip {
            background: var(--cream-2);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 4px 12px;
            font-size: 11.5px;
            font-family: 'DM Mono', monospace;
            color: var(--text-secondary);
            white-space: nowrap
        }

        .rf-banner-chip strong {
            color: var(--sage-dark);
            font-weight: 600;
            margin-left: 3px
        }

        .rf-dim-box {
            background: var(--cream-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 8px 10px;
            display: flex;
            flex-direction: column;
            gap: 3px
        }

        .rf-dim-sub {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--text-muted)
        }

        .rf-dim-input {
            width: 100%;
            background: transparent;
            border: none;
            outline: none;
            font-family: 'DM Mono', monospace;
            font-size: 20px;
            font-weight: 500;
            color: var(--text-primary);
            padding: 0;
            -moz-appearance: textfield
        }

        .rf-dim-input::-webkit-inner-spin-button,
        .rf-dim-input::-webkit-outer-spin-button {
            -webkit-appearance: none
        }

        .rf-stage-pill2 {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 7px 9px;
            border-radius: 8px;
            border: 1.5px solid var(--border);
            background: var(--cream-2);
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            font-size: 11.5px;
            font-weight: 500;
            color: var(--text-secondary);
            transition: all .15s;
            text-align: left
        }

        .rf-stage-pill2:hover {
            border-color: var(--sage-light);
            color: var(--sage-dark);
            background: var(--sage-pale)
        }

        .rf-stage-pill2.active {
            border-color: var(--sc, var(--sage));
            color: var(--text-primary);
            background: var(--white)
        }

        .rf-sdot {
            width: 10px;
            height: 10px;
            border-radius: 3px;
            flex-shrink: 0
        }

        .rf-reset-btn2 {
            width: 100%;
            padding: 9px 14px;
            border-radius: var(--radius-sm);
            border: 1.5px solid var(--border);
            background: transparent;
            font-family: 'DM Sans', sans-serif;
            font-size: 12.5px;
            font-weight: 500;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all .15s
        }

        .rf-reset-btn2:hover {
            border-color: var(--sage-light);
            color: var(--sage-dark);
            background: var(--sage-pale)
        }

        .rf-canvas-wrap2 {
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            min-height: 380px;
            position: relative;
            background-color: #cfc3ae;
            background-image: repeating-linear-gradient(0deg, transparent, transparent 39px, rgba(0, 0, 0, .04) 39px, rgba(0, 0, 0, .04) 40px), repeating-linear-gradient(90deg, transparent, transparent 39px, rgba(0, 0, 0, .04) 39px, rgba(0, 0, 0, .04) 40px)
        }

        #rfCanvas {
            border-radius: 10px;
            cursor: crosshair;
            display: block;
            max-width: 100%;
            height: auto !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .28), 0 8px 28px rgba(0, 0, 0, .18)
        }

        .rf-tooltip2 {
            position: absolute;
            pointer-events: none;
            background: rgba(10, 20, 10, .88);
            color: rgba(255, 255, 255, .92);
            font-size: 11.5px;
            padding: 5px 10px;
            border-radius: 6px;
            white-space: nowrap;
            z-index: 99;
            opacity: 0;
            transition: opacity .1s
        }

        .rf-section {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: visible;
            box-shadow: var(--shadow-sm)
        }

        .rf-body {
            display: grid;
            grid-template-columns: 300px minmax(0, 1fr)
        }

        .rf-controls {
            padding: 18px 18px 18px 20px;
            border-right: 1px solid var(--border-light);
            display: flex;
            flex-direction: column;
            gap: 14px;
            background: var(--cream)
        }

        .rf-ctrl-group {
            display: flex;
            flex-direction: column;
            gap: 5px
        }

        .rf-ctrl-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .9px;
            text-transform: uppercase;
            color: var(--text-muted)
        }

        .rf-range {
            -webkit-appearance: none;
            width: 100%;
            height: 5px;
            border-radius: 3px;
            background: var(--cream-3);
            outline: none
        }

        .rf-range::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 17px;
            height: 17px;
            border-radius: 50%;
            background: var(--sage);
            cursor: pointer;
            box-shadow: 0 1px 4px rgba(0, 0, 0, .18)
        }

        .rf-stage-pills {
            display: flex;
            gap: 5px;
            flex-wrap: wrap
        }

        .rf-stage-pill {
            padding: 5px 11px;
            border-radius: 20px;
            border: 1.5px solid var(--border);
            background: var(--white);
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all .18s;
            font-family: inherit
        }

        .rf-stage-pill:hover {
            border-color: var(--sage-light);
            color: var(--sage-dark);
            background: var(--sage-pale)
        }

        .rf-stage-pill.active {
            background: var(--sage);
            border-color: var(--sage);
            color: white
        }

        .rf-divider {
            border: none;
            border-top: 1px solid var(--border-light);
            margin: 2px 0
        }

        .rf-stats-mini {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px
        }

        .rf-stat-mini {
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            padding: 9px 11px
        }

        .rf-stat-mini-val {
            font-family: 'DM Mono', monospace;
            font-size: 15px;
            font-weight: 700;
            color: var(--sage-dark);
            margin-bottom: 1px
        }

        .rf-stat-mini-lbl {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .6px;
            text-transform: uppercase;
            color: var(--text-muted)
        }

        .rf-legend {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            justify-content: center
        }

        .rf-legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            color: var(--text-secondary);
            font-weight: 500
        }

        .rf-legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 3px;
            flex-shrink: 0
        }

        .rf-canvas-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: rgba(0, 0, 0, .35);
            align-self: flex-start
        }

        @keyframes wmPulse {

            0%,
            100% {
                opacity: 1;
                transform: scale(1)
            }

            50% {
                opacity: .4;
                transform: scale(.8)
            }
        }

        @media(max-width:1100px) {
            .top-row {
                grid-template-columns: 1fr 1fr
            }

            .bottom-row {
                grid-template-columns: 1fr 1fr
            }

            .bottom-row .reminders-card {
                grid-column: span 2
            }
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

            .welcome-banner {
                padding: 22px 18px;
                flex-direction: column;
                align-items: flex-start;
                gap: 11px
            }

            .welcome-text h1 {
                font-size: 20px
            }

            .top-row {
                grid-template-columns: 1fr
            }

            .top-row-right {
                flex-direction: row
            }

            .bottom-row {
                grid-template-columns: 1fr 1fr
            }

            .bottom-row .reminders-card {
                grid-column: span 2
            }

            .notif-drop {
                right: 10px;
                left: 10px;
                width: auto
            }

            .profile-drop {
                right: 10px
            }
        }

        @media(max-width:600px) {
            .top-row-right {
                flex-direction: column
            }

            .bottom-row {
                grid-template-columns: 1fr
            }

            .bottom-row .reminders-card {
                grid-column: span 1
            }
        }

        @media (max-width:768px) {
            .modal {
                padding: 0
            }

            .modal-box {
                width: 100% !important;
                max-width: 100% !important;
                height: 100vh !important;
                height: 100dvh !important;
                max-height: 100vh !important;
                max-height: 100dvh !important;
                border-radius: 0 !important;
                display: flex;
                flex-direction: column;
                overflow: hidden
            }

            .modal-head,
            .act-modal-head,
            .wm-header,
            .rm-header,
            .pm-header {
                flex-shrink: 0;
                padding: 16px 18px
            }

            .wm-hdr-title {
                font-size: 19px
            }

            .wm-hdr-sup {
                font-size: 9.5px
            }

            .act-modal-title {
                font-size: 18px
            }

            .modal-body {
                flex: 1 1 auto;
                overflow-y: auto;
                min-height: 0;
                -webkit-overflow-scrolling: touch
            }

            .modal-foot {
                flex-shrink: 0;
                padding: 12px 16px
            }

            .pm-stats,
            .wm-stats,
            .rm-stats {
                grid-template-columns: repeat(2, 1fr);
                flex-shrink: 0
            }

            #pmNoticeBar,
            #wmLoadingBar,
            #riceLiveBar {
                flex-shrink: 0
            }

            .wm-extra-inline {
                grid-template-columns: 1fr
            }

            .wm-extra-item-inline {
                border-right: none;
                border-bottom: 1px solid var(--border-light)
            }

            .wm-extra-item-inline:last-child {
                border-bottom: none
            }

            .pm-body,
            .wm-body,
            .rm-body {
                display: block;
                flex: 1 1 auto;
                overflow-y: auto;
                min-height: 0;
                -webkit-overflow-scrolling: touch
            }

            .pm-left,
            .pm-right,
            .wm-left,
            .wm-right,
            .rm-left,
            .rm-right {
                overflow-y: visible;
                border-right: none;
                min-height: 0
            }
        }

        @media(max-width:860px) {
            .rf-body {
                grid-template-columns: 1fr
            }
        }
        
        .am-header {
            background: var(--white);
            border-bottom: 1px solid var(--border-light);
            border-radius: var(--radius) var(--radius) 0 0;
            padding: 14px 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0
        }

        .am-header-icon {
            width: 36px;
            height: 36px;
            background: var(--sage-pale);
            border: 1px solid rgba(90, 122, 82, .18);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-right: 11px
        }

        .am-header-icon svg {
            width: 18px;
            height: 18px;
            stroke: var(--sage-dark)
        }

        .am-header-sup {
            color: var(--text-muted)
        }

        .am-header-title {
            color: var(--text-primary)
        }

        .am-header .wm-close-btn {
            color: var(--text-muted)
        }

        .am-header-top {
            display: flex;
            align-items: center;
            gap: 2px
        }

        .am-header-icon {
            width: 36px;
            height: 36px;
            background: var(--sage-pale);                  /* CHANGED — light fill instead of white-on-color */
            border: 1px solid rgba(90, 122, 82, .18);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-right: 11px
        }

        .am-header-icon svg {
            width: 18px;
            height: 18px;
            stroke: var(--sage-dark)                       /* CHANGED — icon now colored, not white */
        }
        
        .am-header-icon.am-header-icon-gold {
            background: var(--gold-pale);
            border-color: rgba(200, 150, 62, .22)
        }

        .am-header-icon.am-header-icon-gold svg {
            stroke: var(--gold)
        }

        .am-header-icon.am-header-icon-plum {
            background: var(--plum-pale);
            border-color: rgba(138, 90, 143, .22)
        }

        .am-header-icon.am-header-icon-plum svg {
            stroke: var(--plum)
        }

        .am-header-sup {
            font-size: 10px;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: var(--text-muted);                      /* CHANGED — muted gray, not translucent white */
            margin-bottom: 2px
        }

        .am-header-title {
            font-family: 'Lora', serif;
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary)                     /* CHANGED — dark text on light bg */
        }

        .am-header .wm-close-btn {
            color: var(--text-muted)                        /* CHANGED — dark muted ×, not white */
        }

        .am-header .wm-close-btn:hover {
            opacity: .6
        }

        .am-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            border-bottom: 1px solid var(--border-light);
            flex-shrink: 0
        }

        .am-stat {
            padding: 14px 10px;
            text-align: center;
            border-right: 1px solid var(--border-light)
        }

        .am-stat:last-child {
            border-right: none
        }

        .am-stat-val {
            font-family: 'DM Mono', monospace;
            font-size: 24px;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 3px
        }

        .am-stat-lbl {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .8px;
            text-transform: uppercase;
            color: var(--text-muted)
        }

        .am-sec-lbl {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.1px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 10px
        }

        .am-sec-lbl svg {
            width: 13px;
            height: 13px;
            stroke: var(--text-muted);
            stroke-width: 2;
            fill: none
        }
        
        .modal-head,
        .act-modal-head,
        .am-header,          /* ADD */
        .wm-header,
        .rm-header,
        .pm-header {
            flex-shrink: 0;
            padding: 16px 18px
        }

        .fv-card {
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            padding: 20px 22px;
            flex-shrink: 0
        }

        .fv-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px
        }

        .fv-title {
            font-family: 'Lora', serif;
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px
        }

        .fv-sub {
            font-size: 12.5px;
            color: var(--text-muted);
            margin-top: 3px
        }

        .fv-chips {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
            padding-top: 2px
        }

        .fv-chip {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 6px 13px;
            border-radius: 20px;
            background: var(--cream-2);
            border: 1px solid var(--border);
            color: var(--text-secondary);
            white-space: nowrap
        }

        .fv-chip-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0
        }

        .fv-chip-val {
            font-family: 'DM Mono', monospace;
            font-weight: 700
        }

        .fv-body {
            display: grid;
            grid-template-columns: 240px 1fr;
            gap: 18px
        }

        @media(max-width:860px) {
            .fv-body {
                grid-template-columns: 1fr
            }
        }

        .fv-controls {
            display: flex;
            flex-direction: column;
            gap: 16px
        }

        .fv-group-label {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .8px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 8px
        }

        .fv-dims-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px
        }

        .fv-dim-box {
            background: var(--cream-2);
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 7px 9px
        }

        .fv-dim-sub {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .7px;
            text-transform: uppercase;
            color: var(--text-muted)
        }

        .fv-dim-input {
            width: 100%;
            border: none;
            background: transparent;
            font-family: 'DM Mono', monospace;
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            padding: 0;
            outline: none
        }

        .fv-range {
            -webkit-appearance: none;
            width: 100%;
            height: 5px;
            border-radius: 3px;
            background: var(--cream-3);
            outline: none
        }

        .fv-range::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--sage);
            cursor: pointer
        }

        .fv-stage-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px
        }

        .fv-stage-btn {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 7px 9px;
            border-radius: 8px;
            border: 1.5px solid var(--border);
            background: var(--cream-2);
            font-family: 'DM Sans', sans-serif;
            font-size: 12px;
            font-weight: 500;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all .15s;
            text-align: left
        }

        .fv-stage-btn:hover {
            border-color: var(--sage-light)
        }

        .fv-stage-btn.active {
            background: var(--white);
            border-color: var(--fv-color, var(--sage));
            color: var(--text-primary);
            box-shadow: 0 0 0 1px var(--fv-color, var(--sage))
        }

        .fv-dot {
            width: 10px;
            height: 10px;
            border-radius: 3px;
            flex-shrink: 0
        }

        .fv-mode-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px
        }

        .fv-mode-btn {
            padding: 8px 10px;
            border-radius: 9px;
            border: 1.5px solid var(--border);
            background: var(--cream-2);
            font-family: 'DM Sans', sans-serif;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0,0,0,.04);
    		transition: all .18s
        }

        .fv-mode-btn:hover {
            border-color: var(--sage-light)
            transform: translateY(-1px);
   	 		box-shadow: 0 3px 8px rgba(0,0,0,.08)
        }

        .fv-mode-btn.active {
            background: var(--sage);
            border-color: var(--sage);
            color: white
        }
        
        .fv-mode-btn,
        .fv-reset-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px
        }

        .fv-mode-btn svg,
        .fv-reset-btn svg {
            width: 14px;
            height: 14px;
            stroke: currentColor;
            flex-shrink: 0
        }
        
        .fv-mode-btn[data-mode="stage"] svg { stroke: var(--sage-dark) }
        .fv-mode-btn[data-mode="water"] svg { stroke: var(--sky) }
        .fv-mode-btn[data-mode="path"] svg { stroke: #a5673f }
        .fv-mode-btn[data-mode="field"] svg { stroke: var(--plum) }
        .fv-mode-btn[data-mode="erase"] svg { stroke: #c0392b }

        .fv-mode-btn[data-mode="stage"].active { background: linear-gradient(135deg, var(--sage), var(--sage-dark)); border-color: var(--sage-dark) }
        .fv-mode-btn[data-mode="water"].active { background: linear-gradient(135deg, #5aa8e0, var(--sky)); border-color: var(--sky) }
        .fv-mode-btn[data-mode="path"].active { background: linear-gradient(135deg, #b58657, #a5673f); border-color: #a5673f }
        .fv-mode-btn[data-mode="field"].active { background: linear-gradient(135deg, #a578ab, var(--plum)); border-color: var(--plum) }
        .fv-mode-btn[data-mode="erase"].active { background: linear-gradient(135deg, #e05c4a, #c0392b); border-color: #c0392b }
        .fv-mode-btn.active svg { stroke: white }

        .fv-summary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px
        }

        .fv-summary-box {
            background: var(--cream-2);
            border: 1px solid var(--border-light);
            border-radius: 9px;
            padding: 9px 11px
        }

        .fv-summary-val {
            font-family: 'DM Mono', monospace;
            font-size: 19px;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1
        }

        .fv-summary-lbl {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .6px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-top: 3px
        }

        .fv-reset-btn {
            width: 100%;
            height: 46px;
            padding: 0 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border-radius: var(--radius-sm);
            border: 1px solid #f0c7c1;
            background: #fdecea;
            font-family: 'DM Sans', sans-serif;
            font-size: 12.5px;
            font-weight: 700;
            color: #c0392b;
            cursor: pointer;
            transition: background .18s, border-color .18s, transform .18s;
            box-shadow: none;
        }

        .fv-reset-btn:hover {
            background: #fadbd8;
            border-color: #e8a49c;
            transform: translateY(-1px);
        }
        
        #fvSyncBtn {
            background: var(--sage-pale);
            border-color: var(--sage-light);
            color: var(--sage-dark);
            font-weight: 700
        }

        #fvSyncBtn:hover {
            background: var(--sage-light);
            color: white
        }

        #fvSyncBtn svg {
            stroke: currentColor
        }

        .fv-map-wrap {
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-width: 0
        }

        .fv-map-label {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .8px;
            text-transform: uppercase;
            color: var(--text-muted)
        }

        .fv-map-frame {
            background: linear-gradient(155deg, #cfc3ae, #dcd2bd);
            background-image: repeating-linear-gradient(0deg, transparent, transparent 39px, rgba(0, 0, 0, .05) 39px, rgba(0, 0, 0, .05) 40px), repeating-linear-gradient(90deg, transparent, transparent 39px, rgba(0, 0, 0, .05) 39px, rgba(0, 0, 0, .05) 40px);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: auto;
            height: 450px;              /* CHANGED — was min-height: 260px */
            box-shadow: inset 0 2px 8px rgba(0,0,0,.08), 0 2px 6px rgba(0,0,0,.04)
        }

        .fv-grid {
            display: grid;
            gap: 3px
        }

        .fv-cell {
            position: relative;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            transition: transform .1s;
            user-select: none
            box-shadow: 0 1px 3px rgba(0,0,0,.15)
        }

        .fv-cell:hover {
            transform: scale(1.06);
            z-index: 2;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .18)
        }

        .fv-cell-coord {
            position: absolute;
            top: 2px;
            left: 3px;
            font-size: 7.5px;
            font-weight: 700;
            font-family: 'DM Mono', monospace;
            color: rgba(0, 0, 0, .35)
        }

        .fv-legend {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: center
        }

        .fv-legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            color: var(--text-secondary)
        }
        
        .fv-legend-dot,
        .fv-legend-item .fv-dot {
            width: 12px;
            height: 12px;
            box-shadow: 0 0 0 1px rgba(0,0,0,.06)
        }

        .fv-tooltip {
            position: fixed;
            pointer-events: none;
            background: rgba(10, 20, 10, .9);
            color: white;
            font-size: 11.5px;
            padding: 5px 10px;
            border-radius: 6px;
            white-space: nowrap;
            z-index: 9999;
            opacity: 0;
            transition: opacity .1s
        }
        
        .fv-title-icon {
            width: 34px;
            height: 34px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center
        }

        .fv-title-icon svg {
            width: 26px;
            height: 26px;
            fill: var(--teal-pale);
            stroke: var(--teal)
        }
        
        #riceAlertIcon {
            width: 34px; height: 34px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
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

                    <a href="dashboard.php" class="nav-item active" data-tooltip="Dashboard"><svg viewBox="0 0 24 24">
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
            <a href="logout.php" class="signout-btn"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                    <polyline points="16 17 21 12 16 7" />
                    <line x1="21" y1="12" x2="9" y2="12" />
                </svg><span class="signout-text">Sign out</span></a>
        </div>
    </aside>
    <div class="main-content">
        <header class="header">
            <div class="header-left">
                <button class="hamburger-btn" id="hamburgerBtn" onclick="toggleSidebar()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                	<rect x="3" y="3" width="18" height="18" rx="2" />
                	<path d="M9 3v18" />
            	</svg></button>
                Dashboard
            </div>
            <div class="header-right">
                <button class="notif-btn" id="notifBtn"><svg width="21" height="21" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
                        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                    </svg></button>
                <div class="header-sep"></div>
                <div class="profile-pill" id="profileBtn">
                    <span class="profile-pill-name"><?= htmlspecialchars($user['name']) ?></span>
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
            <div class="nd-head"><span class="nd-title">Notifications</span><button class="mark-read-btn" onclick="markAllRead()">Mark all read</button></div>
            <div class="nd-list" id="ndList"></div>
            <div class="nd-foot"><a href="notifications.php">View all</a></div>
        </div>
        <div class="profile-drop" id="profileDrop">
            <h3><?= htmlspecialchars($user['name']) ?></h3>
            <p><?= htmlspecialchars($user['email']) ?></p>
            <hr>
            <a href="profile.php" class="pd-btn">My Profile</a>
            <a href="logout.php" class="pd-btn">Sign out</a>
        </div>
        <div class="page-scroll">
            <div class="welcome-banner">
            	<div class="banner-sky">
                    <svg viewBox="0 0 400 26" preserveAspectRatio="none">
                        <path d="M0,22 Q200,-6 400,22" fill="none" stroke="rgba(255,255,255,.22)" stroke-width="1" stroke-dasharray="3 5"/>
                    </svg>
                    <div class="banner-sun" id="bannerSun"></div>
                </div>
            	<svg class="banner-terraces" viewBox="0 0 800 90" preserveAspectRatio="none">
                	<path d="M0,55 C110,40 220,62 330,48 C440,34 560,58 670,44 C730,37 770,50 800,42 L800,90 L0,90 Z" fill="rgba(20,40,18,.30)"/>
                	<path d="M0,68 C130,55 260,78 400,64 C520,52 640,72 800,60 L800,90 L0,90 Z" fill="rgba(61,92,56,.42)"/>
                	<path d="M0,80 C160,72 320,86 480,78 C610,72 700,84 800,78 L800,90 L0,90 Z" fill="rgba(200,150,62,.30)"/>
            	</svg>
            	<div class="welcome-text">
                	<div class="welcome-eyebrow">Today's Field Brief</div>
                	<h1>Welcome back, <?= htmlspecialchars($firstName) ?></h1>
                	<p>Here's how your farm looks right now.</p>
            	</div>
            	<div class="field-log-stamp">
                    <div class="fls-eyebrow">Right Now</div>
                    <div class="fls-date" id="realDate">—</div>
                    <div class="fls-time" id="realTime">—</div>
                </div>
        	</div>
            <div id="severeWeatherBanner" style="display:none;align-items:center;gap:14px;padding:13px 18px;background:linear-gradient(135deg, var(--gold-pale), #f5e2b8);border:1px solid var(--gold);border-radius:var(--radius-sm);flex-shrink:0;">
            	<div style="width:34px;height:34px;border-radius:10px;background:rgba(255,255,255,.55);display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 3px 8px rgba(200,150,62,.18);">
                	<svg id="severeWeatherIcon" viewBox="0 0 24 24" fill="none" stroke="var(--sage-dark)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                    	<path d="M20 16.58A5 5 0 0 0 18 7h-1.26A8 8 0 1 0 4 15.25" />
                	</svg>
            	</div>
            	<span id="severeWeatherText" style="font-size:13.5px;font-weight:600;color:var(--sage-dark);flex:1;line-height:1.4;"></span>
            	<button onclick="document.getElementById('severeWeatherBanner').style.display='none'" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:16px;flex-shrink:0;padding:0 4px;">×</button>
        	</div>
            <div class="top-row">
                <div class="card profit-card" onclick="openModal('profitModal')">
                    <div class="profit-card-header">
                        <h3>Net Profit</h3><span class="live-badge">Live</span>
                    </div>
                    <div class="profit-amount" id="profitAmount"><span class="php-sign">₱</span><span id="profitAmountValue">—</span></div>
                    <div class="profit-meta"><span class="profit-change" id="profitChange">—</span><span class="profit-sub">estimated ROI</span></div>
                    <div class="profit-chart-wrap">
                    	<div class="profit-chart-tag" id="profitChartTag">
                        	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            	<polyline points="4 15 10 9 14 13 20 5" /><polyline points="14 5 20 5 20 11" />
                        	</svg>
                        	<span id="profitChartTagText"></span>
                    	</div>
                    	<canvas id="profitCanvas"></canvas>
                	</div>
                </div>
                <div class="top-row-right">
                    <div class="card info-card" onclick="openModal('weatherModal')">
                    	<div class="info-card-head" style="justify-content:space-between;align-items:flex-start;">
                        	<div style="display:flex;align-items:center;gap:11px;">
                            	<div class="icon-box icon-weather">
                                    <svg id="weatherBoxIcon" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M19 18H6a4 4 0 0 1 0-8 5 5 0 0 1 9.9-1.5 3.5 3.5 0 0 1 4.1 5.3A3.5 3.5 0 0 1 19 18z" />
                                    </svg>
                                </div>
                            	<div>
                                	<div class="card-lbl">Weather Today</div>
                                	<div class="card-ttl" id="weatherLoc"><?= htmlspecialchars($locationTown . ', ' . $locationProv) ?></div>
                            	</div>
                        	</div>
                        	<div class="weather-temp" id="dashTemp" style="margin-bottom:0;margin-right:20px;">—°C</div>
                    	</div>
                    	<div class="weather-desc" id="dashDesc">
                        	<svg id="dashDescIcon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"></svg>
                        	<span id="dashDescText">Loading weather…</span>
                    	</div>
                    	<div class="mini-chart"><canvas id="weatherCanvas"></canvas></div>
                	</div>
                    <div class="card info-card" onclick="openModal('riceModal')">
                            <div class="info-card-head">
                                <div class="icon-box icon-rice">
                                    <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 17l6-6 4 4 8-8v10H3z" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="card-lbl">Market</div>
                                    <div class="card-ttl">Rice Trends</div>
                                </div>
                            </div>
                            <div class="price-blocks">
                                <div class="price-block is-current">
                                    <div class="pb-label">Current</div>
                                    <div class="pb-value" id="pbCurrent"><span class="php-sign-sm">₱</span>45/kg</div>
                                </div>
                                <div class="price-block is-standard">
                                    <div class="pb-label">DA Standard</div>
                                    <div class="pb-value" id="pbMsrp"><span class="php-sign-sm">₱</span>49/kg</div>
                                </div>
                            </div>
                            <div class="mini-chart"><canvas id="marketCanvas"></canvas></div>
                        </div>
                </div>
            </div>
            <div class="bottom-row">
                <div class="card activity-card" onclick="openModal('activityModal')">
                    <div class="info-card-head" style="justify-content:space-between;align-items:flex-start;">
                        <div style="display:flex;align-items:center;gap:11px;">
                            <div class="icon-box icon-activity">
                                <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 22V12" /><path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8" /><path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8" />
                                </svg>
                            </div>
                            <div style="text-align:left">
                                <div class="card-lbl">Process</div>
                                <div class="card-ttl">Planting Process</div>
                            </div>
                        </div>
                        <span class="live-badge" id="activityLiveBadge">Active</span>
                    </div>
                    <div class="activity-ring-center">
                            <button class="act-nav-btn" id="actPrevBtn" onclick="navActivity(-1); event.stopPropagation()" aria-label="Previous field" style="display:none">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                            </button>
                            <div class="ring-wrap" style="width:104px;height:104px">
                                <svg width="104" height="104" viewBox="0 0 104 104">
                                    <defs>
                                        <linearGradient id="ringGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                            <stop offset="0%" stop-color="#8aab82" />
                                            <stop offset="100%" stop-color="#3d5c38" />
                                        </linearGradient>
                                    </defs>
                                    <circle class="ring-bg" cx="52" cy="52" r="42" stroke-width="11" />
                                    <circle class="ring-fill" id="ringFill" cx="52" cy="52" r="42" stroke-width="11" stroke-dasharray="264" stroke-dashoffset="264" />
                                </svg>
                                <div class="ring-label" id="ringText" style="font-size:22px">—</div>
                            </div>
                            <button class="act-nav-btn" id="actNextBtn" onclick="navActivity(1); event.stopPropagation()" aria-label="Next field" style="display:none">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                            </button>
                        </div>
                    <div class="activity-info-center">
                    	<h3 id="activityHeading">Loading…</h3>
                    	<div class="activity-dots" id="activityDots"></div>
                    	<div class="activity-chips" id="activityStatsMini"></div>
                	</div>
                </div>
                <div class="card recent-card" onclick="openModal('recentModal')">
                    <div class="recent-head">
                    	<div class="icon-box icon-recent"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
                        </svg></div>
                    	<div class="recent-title">Recent Activity</div>
                	</div>
                    <?php
                        $actIcons = [
                            'Planting' => ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--sage)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22V12"/><path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8"/><path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8"/></svg>', 'var(--sage-pale)'],
                            'Fertilizing' => ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--sage-dark)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3h6l1 4H8z"/><path d="M8 7h8l1 13a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1z"/></svg>', '#e8f0e4'],
                            'Irrigation' => ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--sky)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>', 'var(--sky-pale)'],
                            'Pest Control' => ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="M8.5 8.5 16 16"/></svg>', 'var(--gold-pale)'],
                            'Harvesting' => ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 8-8v10H3z"/></svg>', 'var(--gold-pale)'],
                            'Sales' => ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--sage)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 9.5c0-1.5 1.3-2.5 3-2.5s3 1 3 2.5S13.5 12 12 12s-3 1-3 2.5 1.3 2.5 3 2.5 3-1 3-2.5"/></svg>', 'var(--sage-pale)'],
                        ];
                        $defaultIcon = ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>', 'var(--cream-2)'];
                        ?>
                        <div id="recentList">
                            <?php if ($records): foreach (array_slice($records, 0, 3) as $r):
                                    $ic = $actIcons[$r['activity']] ?? $defaultIcon;
                                    $rd = new DateTime($r['record_date']);
                            ?>
                                    <div class="act-row">
                                        <div class="act-icon-badge" style="background:<?= $ic[1] ?>"><?= $ic[0] ?></div>
                                        <div class="act-row-main">
                                            <div class="act-row-title"><?= htmlspecialchars($r['activity']) ?></div>
                                            <div class="act-row-sub"><?= htmlspecialchars($r['location']) ?></div>
                                        </div>
                                        <div class="act-row-date"><?= $rd->format('M j') ?></div>
                                    </div>
                                <?php endforeach;
                            else: ?>
                                <div class="act-row">
                                    <div class="act-icon-badge" style="background:<?= $defaultIcon[1] ?>"><?= $defaultIcon[0] ?></div>
                                    <div class="act-row-main"><span class="act-row-title" style="font-weight:500;color:var(--text-muted)">No recent activities</span></div>
                                </div>
                            <?php endif; ?>
                            <?php if ($records): ?>
                                <a href="records.php" class="recent-card-foot" onclick="event.stopPropagation()">View all activities</a>
                            <?php endif; ?>
                        </div>
                </div>
                <div class="card reminders-card" onclick="openModal('remindersModal')">
                    <div class="recent-head">
                        <div class="icon-box icon-remind"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round">
                                <path d="M12 7v5l3 3" />
                                <circle cx="12" cy="12" r="10" />
                            </svg></div>
                        <div>
                            <div class="card-lbl" style="margin-bottom:1px;">Upcoming</div>
                            <div class="card-ttl">Reminders</div>
                        </div>
                    </div>
                    <?php
                    $remIcons = [
                        'Harvest'           => ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 8-8v10H3z"/></svg>', 'var(--gold-pale)'],
                        'Spray Insecticide' => ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--sky)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>', 'var(--sky-pale)'],
                        'Spray Fungicide'   => ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--sage)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="M8.5 8.5 16 16"/></svg>', 'var(--sage-pale)'],
                    ];
                    $defaultRemIcon = ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--plum)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v5l3 3"/><circle cx="12" cy="12" r="10"/></svg>', 'var(--plum-pale)'];

                    function remIconFor($title, $iconMap, $default) {
                        foreach ($iconMap as $key => $icon) {
                            if (stripos($title, $key) !== false) return $icon;
                        }
                        return $default;
                    }

                    function stripEmoji($str) {
                        return trim(preg_replace('/^[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]+\s*/u', '', $str));
                    }
                    ?>
                    <div id="remindersList">
                            <?php if ($reminders): foreach ($reminders as $rem):
                                    $d = new DateTime($rem['event_date']);
                                    $now = new DateTime();
                                    $diff = (int)$now->diff($d)->days;
                                    $tag = $diff === 0 ? 'Today' : ($diff === 1 ? 'Tomorrow' : $diff . 'd');
                                    $cleanTitle = stripEmoji($rem['title']);
                            ?>
                                    <div class="reminder-item">
                                        <div class="reminder-main">
                                            <div class="reminder-title-wrap">
                                                <div class="reminder-title"><?= htmlspecialchars($cleanTitle) ?></div>
                                                <div class="reminder-date"><?= $d->format('M j') ?></div>
                                            </div>
                                            <span class="reminder-badge"><?= $tag ?></span>
                                        </div>
                                    </div>
                                <?php endforeach;
                            else: ?>
                                <div class="no-reminders">No upcoming reminders</div>
                            <?php endif; ?>
                        </div>
                </div>
            </div>
            <div class="fv-card">
                <div class="fv-head">
                            <div>
                                <div class="fv-title">
                                    <div class="fv-title-icon">
                                        <svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="3" width="7" height="7" rx="1" />
                                            <rect x="14" y="3" width="7" height="7" rx="1" />
                                            <rect x="3" y="14" width="7" height="7" rx="1" />
                                            <rect x="14" y="14" width="7" height="7" rx="1" />
                                        </svg>
                                    </div>
                                    Rice Field Visualizer
                                </div>
                                <div class="fv-sub">Design your paddy layout, plot by plot</div>
                            </div>
                            <div class="fv-chips" id="fvChips"></div>
                        </div>
                <div class="fv-body">
                    <div class="fv-controls">
                        <div>
                            <div class="fv-group-label">Plot Dimensions</div>
                            <div class="fv-dims-row">
                                <div class="fv-dim-box">
                                    <div class="fv-dim-sub">Columns</div>
                                    <input type="number" class="fv-dim-input" id="fvCols" min="1" max="12">
                                </div>
                                <div class="fv-dim-box">
                                    <div class="fv-dim-sub">Rows</div>
                                    <input type="number" class="fv-dim-input" id="fvRows" min="1" max="12">
                                </div>
                            </div>
                        </div>
                        <div>
                            <div class="fv-group-label">Cell Size — <span id="fvCellSizeLabel">52</span>px</div>
                            <input type="range" class="fv-range" id="fvCellSize" min="28" max="80" value="52">
                        </div>
                        <div>
                            <div class="fv-group-label">Crop Stage</div>
                            <div class="fv-stage-grid" id="fvStageGrid"></div>
                        </div>
                        <div>
                            <div class="fv-group-label">Paint Mode</div>
                            <div class="fv-mode-grid" id="fvModeGrid"></div>
                        </div>
                        <div>
                            <div class="fv-summary-grid" id="fvSummaryGrid"></div>
                        </div>
                        <div style="display:flex;gap:8px">
                            <button class="fv-reset-btn" id="fvResetBtn" style="flex:1">
                                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>
                                    <span>Reset Field</span>
                                </button>
                                <button class="fv-reset-btn" id="fvSyncBtn" style="flex:1;border-color:var(--sage-light);color:var(--sage-dark)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg>
                                    <span>Sync from Records</span>
                                </button>
                        </div>
                    </div>
                    <div class="fv-map-wrap">
                        <div class="fv-map-label">Field Map — hover a cell to inspect · click or drag to paint</div>
                        <div class="fv-map-frame">
                            <div class="fv-grid" id="fvGrid"></div>
                        </div>
                        <div class="fv-legend" id="fvLegend"></div>
                    </div>
                </div>
            </div>
        </div><!-- end page-scroll -->
    </div><!-- end main-content -->
    <div class="fv-tooltip" id="fvTooltip"></div>
    <!-- FIELD ASSIGNMENT MODAL -->
    <div class="modal" id="fvAssignModal">
        <div class="modal-box" style="max-width:380px">
            <div class="modal-head">
                <div class="modal-title">Assign Field to Cell</div>
                <button class="modal-close" onclick="fvCloseAssignModal()">×</button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px">Link this cell to one of your real fields. Once linked, "Sync from Records" will update its color based on that field's latest logged activity.</p>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:6px">Field</div>
                <select id="fvAssignSelect" style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:14px;font-family:inherit;color:var(--text-primary);background:var(--cream)">
                    <option value="">— No assignment (decorative cell) —</option>
                </select>
            </div>
            <div class="modal-foot"><button class="modal-btn" style="background:var(--cream-2);color:var(--text-secondary)" onclick="fvCloseAssignModal()">Cancel</button><button class="modal-btn" onclick="fvSaveAssignment()">Save</button></div>
        </div>
    </div>
    <!-- MODALS -->
    <div id="profitModal" class="modal">
            <div class="modal-box">
                <div class="pm-header">
                    <div class="pm-header-top">
                        <div class="pm-header-icon">
                            <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="9" />
                                <path d="M12 7v10M9 9.5c0-1.5 1.3-2.5 3-2.5s3 1 3 2.5S13.5 12 12 12s-3 1-3 2.5 1.3 2.5 3 2.5 3-1 3-2.5" />
                            </svg>
                        </div>
                        <div>
                            <div class="wm-hdr-sup">Farm Profit Analysis · PSA Rice Prices</div>
                            <div class="wm-hdr-title">Net Profit Overview</div>
                        </div>
                    </div>
                    <button class="wm-close-btn" onclick="closeModal('profitModal')">×</button>
                </div>
                <div class="pm-stats">
                    <div class="pm-stat">
                        <div class="pm-stat-val" id="pmNetProfit" style="color:var(--sage-dark);">…</div>
                        <div class="pm-stat-lbl">Est. Net Profit</div>
                    </div>
                    <div class="pm-stat">
                        <div class="pm-stat-val" id="pmGrossRev" style="color:var(--gold);">…</div>
                        <div class="pm-stat-lbl">Gross Revenue</div>
                    </div>
                    <div class="pm-stat">
                        <div class="pm-stat-val" id="pmTotalCost" style="color:#c0392b;">…</div>
                        <div class="pm-stat-lbl">Total Cost</div>
                    </div>
                    <div class="pm-stat">
                        <div class="pm-stat-val" id="pmMargin">…</div>
                        <div class="pm-stat-lbl">Profit Margin</div>
                    </div>
                </div>
                <div id="pmNoticeBar" class="pm-notice-bar">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" /><line x1="12" y1="16" x2="12" y2="12" /><line x1="12" y1="8" x2="12.01" y2="8" /></svg>
                    <span id="pmNoticeText">Calculating…</span>
                </div>
                <div class="pm-body">
                    <div class="pm-left">
                        <div class="wm-extra-inline">
                            <div class="wm-extra-item-inline">
                            	<div class="wm-extra-ico ico-sage"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--sage)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 8-8v10H3z" /></svg></div>
                            	<div><div class="wm-extra-lbl">Yield / Ha</div><div class="wm-extra-val" id="pmYieldHa">…</div></div>
                        	</div>
                        	<div class="wm-extra-item-inline">
                            	<div class="wm-extra-ico ico-plum"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--sage)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.3 15.3a2.4 2.4 0 0 1 0 3.4l-2.6 2.6a2.4 2.4 0 0 1-3.4 0L2.7 8.7a2.4 2.4 0 0 1 0-3.4l2.6-2.6a2.4 2.4 0 0 1 3.4 0Z" /><path d="m14.5 12.5 2-2M11.5 9.5l2-2M8.5 6.5l2-2M17.5 15.5l2-2" /></svg></div>
                            	<div><div class="wm-extra-lbl">Hectares</div><div class="wm-extra-val" id="pmHaVal">…</div></div>
                        	</div>
                        	<div class="wm-extra-item-inline">
                            	<div class="wm-extra-ico ico-gold"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="var(--gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17" /><polyline points="16 7 22 7 22 13" /></svg></div>
                            	<div><div class="wm-extra-lbl">Farmgate Price</div><div class="wm-extra-val" id="pmFarmgate">…</div></div>
                        	</div>
                        </div>
                        <div>
                            <div class="pm-sec-lbl"><svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10" /><line x1="18" y1="20" x2="18" y2="4" /><line x1="6" y1="20" x2="6" y2="16" /></svg>Quarterly Profit Trend</div>
                            <div style="position:relative;height:230px;margin-bottom:4px;"><canvas id="profitModalChart"></canvas></div>
                        </div>
                        <div>
                            <div class="pm-sec-lbl"><svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17" /><polyline points="16 7 22 7 22 13" /></svg>Revenue vs. Cost</div>
                            <div id="pmRevenueBar" style="display:flex;flex-direction:column;gap:8px;"></div>
                        </div>
                        <div>
                            <div class="pm-sec-lbl"><svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18" /><path d="m7 14 3-3 3 3 5-6" /></svg>Profit Forecast</div>
                            <div id="pmForecastCards" style="display:flex;gap:8px;flex-wrap:wrap;"></div>
                            <div id="pmForecastHistory" style="margin-top:16px"></div>
                        </div>
                        <div style="background:var(--sky-pale);border-radius:var(--radius-sm);padding:12px 15px;display:flex;gap:10px;flex-shrink:0;align-items:flex-start;">
                            <span style="flex-shrink:0;width:28px;height:28px;border-radius:50%;background:var(--sky);display:flex;align-items:center;justify-content:center;">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 18H6a4 4 0 0 1 0-8 5 5 0 0 1 9.9-1.5 3.5 3.5 0 0 1 4.1 5.3A3.5 3.5 0 0 1 19 18z" /></svg>
                            </span>
                            <div id="pmWeatherNoteText" style="font-size:12.5px;color:var(--text-secondary);line-height:1.6;">Checking weather…</div>
                        </div>
                    </div>
                    <div class="pm-right">
                        <div id="pmSeasonAlert" class="pm-verdict-card">
                            <div class="pm-verdict-icon" id="pmAlertIcon">
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            </div>
                            <div class="pm-verdict-body">
                                <div class="pm-verdict-top">
                                    <div class="pm-verdict-title" id="pmAlertTitle">Calculating…</div>
                                    <span class="pm-verdict-badge" id="pmVerdictBadge">—</span>
                                </div>
                                <div class="pm-verdict-desc" id="pmAlertDesc">Estimating based on PSA data.</div>
                            </div>
                        </div>
                        <div>
                            <div class="pm-sec-lbl"><svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M4 3h16v18l-3-2-3 2-3-2-3 2-3-2-1 2z" /><line x1="8" y1="8" x2="16" y2="8" /><line x1="8" y1="12" x2="16" y2="12" /></svg>Input Cost Breakdown</div>
                            <div class="pm-cost-list" id="pmCostList"></div>
                        </div>
                        <div>
                            <div class="pm-sec-lbl"><svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 8-8v10H3z" /></svg>Rice Price Impact</div>
                            <div class="pm-price-impact" id="pmPriceImpact"></div>
                        </div>
                        <div>
                            <div class="pm-sec-lbl"><svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6M10 22h4M12 2a6 6 0 0 0-4 10.5c.6.6 1 1.4 1 2.5h6c0-1.1.4-1.9 1-2.5A6 6 0 0 0 12 2Z" /></svg>Profit Advisory</div>
                            <div class="wm-adv-list" id="pmAdvisory"></div>
                        </div>
                    </div>
                </div>
			<div class="modal-foot" style="justify-content:center;"><span style="font-size:11.5px;color:var(--text-muted);">* Estimates based on PhilRice, PSA, and DA data.</span></div>            </div>
        </div>
        
    <div id="activityModal" class="modal">
            <div class="modal-box" style="max-width:600px;">
                <div class="am-header">
                    <div class="am-header-top">
                        <div class="am-header-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22V12"/><path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8"/><path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8"/>
                            </svg>
                        </div>
                        <div>
                            <div class="am-header-sup">Field Progress</div>
                            <div class="am-header-title">Planting Process</div>
                        </div>
                    </div>
                    <button class="wm-close-btn" onclick="closeModal('activityModal')">×</button>
                </div>
                <div class="am-stats">
                    <div class="am-stat">
                        <div class="am-stat-val" id="actStatFields" style="color:var(--sage-dark)">0</div>
                        <div class="am-stat-lbl">Active Fields</div>
                    </div>
                    <div class="am-stat">
                        <div class="am-stat-val" id="actStatAvg" style="color:var(--gold)">0%</div>
                        <div class="am-stat-lbl">Avg. Progress</div>
                    </div>
                    <div class="am-stat">
                        <div class="am-stat-val" id="actStatCompleted" style="color:var(--text-secondary)">0</div>
                        <div class="am-stat-lbl">Completed Cycles</div>
                    </div>
                </div>
                <div class="modal-body" style="padding-top:18px">
                    <div class="am-sec-lbl">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/></svg>
                        Active Fields
                    </div>
                    <div id="cycleSummaryList"></div>
                </div>
            </div>
        </div>
    <div id="weatherModal" class="modal">
        <div class="modal-box">
        <div class="wm-header">
            <div class="wm-header-top">
                <div class="wm-header-icon">
                    <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17.5 19a4.5 4.5 0 1 0-1.44-8.77A6 6 0 1 0 7 19h10.5Z"/>
                    </svg>
                </div>
                <div>
                    <div class="wm-hdr-sup">Live Weather Forecast</div>
                    <div class="wm-hdr-title" id="weatherModalTitle">☁️ Loading…</div>
                </div>
            </div>
            <button class="wm-close-btn" onclick="closeModal('weatherModal')">×</button>
        </div>
            <div class="wm-stats">
                <div class="wm-stat">
                    <div class="wm-stat-val" id="wmTemp" style="color:#2a5c8a;">—</div>
                    <div class="wm-stat-lbl">Temperature</div>
                </div>
                <div class="wm-stat">
                    <div class="wm-stat-val" id="wmHumidity" style="color:#4a90d9;">—</div>
                    <div class="wm-stat-lbl">Humidity</div>
                </div>
                <div class="wm-stat">
                    <div class="wm-stat-val" id="wmWind" style="color:#5a9050;">—</div>
                    <div class="wm-stat-lbl">Wind km/h</div>
                </div>
                <div class="wm-stat">
                    <div class="wm-stat-val" id="wmRain" style="color:#c8963e;">—</div>
                    <div class="wm-stat-lbl">Rain Chance</div>
                </div>
            </div>
            <div id="wmLoadingBar" style="display:flex;align-items:center;gap:8px;padding:7px 20px;background:var(--sky-pale);border-bottom:1px solid var(--border-light);font-size:12px;color:var(--sky);font-weight:600;flex-shrink:0;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--sky);animation:wmPulse 1.1s infinite;flex-shrink:0;"></span><span id="wmLoadingText">Fetching live weather…</span></div>
            <div class="wm-body">
                <div class="wm-left">
                    <div class="wm-sec-lbl"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 6v16l7-4 8 4 7-4V2l-7 4-8-4z"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>Live Weather Map</div>
                    <div class="wm-layer-tabs">
                    	<button class="wm-layer-btn active" onclick="setWindyLayer('rain')" data-layer="rain" style="--layer-color:#4a90d9">
                        	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>
                        	Rain
                    	</button>
                    	<button class="wm-layer-btn" onclick="setWindyLayer('wind')" data-layer="wind" style="--layer-color:#6b8a9e">
                        	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"/></svg>
                        	Wind
                    	</button>
                    	<button class="wm-layer-btn" onclick="setWindyLayer('temp')" data-layer="temp" style="--layer-color:#d9724a">
                        	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"/></svg>
                        	Temp
                    	</button>
                    	<button class="wm-layer-btn" onclick="setWindyLayer('clouds')" data-layer="clouds" style="--layer-color:#8a94a3">
                        	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.5 19a4.5 4.5 0 1 0-1.44-8.77A6 6 0 1 0 7 19h10.5Z"/></svg>
                        	Clouds
                    	</button>
                    	<button class="wm-layer-btn" onclick="setWindyLayer('waves')" data-layer="waves" style="--layer-color:#2f6f6a">
                        	<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 6c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 2.6 0 2.4 2 5 2 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1M2 12c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 2.6 0 2.4 2 5 2 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1M2 18c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 2.6 0 2.4 2 5 2 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1"/></svg>
                        	Waves
                    	</button>
                	</div>
                    <div class="wm-map-frame"><iframe id="weatherMapIframe" src="" width="100%" height="100%" style="border:0;display:block;" allowfullscreen loading="lazy"></iframe></div>
                    <div class="wm-map-footer"><span class="wm-map-coord" id="weatherMapCoords">📍 Loading…</span><a class="wm-map-link" id="weatherMapLink" href="#" target="_blank">Open Windy ↗</a></div>
                </div>
                <div class="wm-right">
                    <div class="wm-extra-inline">
                    	<div class="wm-extra-item-inline">
                        	<div class="wm-extra-ico ico-sunset"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 18a5 5 0 0 0-10 0"/><line x1="12" y1="9" x2="12" y2="2"/><line x1="4.22" y1="10.22" x2="5.64" y2="11.64"/><line x1="1" y1="18" x2="3" y2="18"/><line x1="21" y1="18" x2="23" y2="18"/><line x1="18.36" y1="11.64" x2="19.78" y2="10.22"/><line x1="23" y1="22" x2="1" y2="22"/><polyline points="16 5 12 9 8 5"/></svg></div>
                        	<div>
                            	<div class="wm-extra-lbl">Sunrise / Sunset</div>
                            	<div class="wm-extra-val" id="wmSunriseSunset">Loading…</div>
                        	</div>
                    	</div>
                    	<div class="wm-extra-item-inline">
                        	<div class="wm-extra-ico ico-uv"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg></div>
                        	<div>
                            	<div class="wm-extra-lbl">UV Index</div>
                            	<div class="wm-extra-val" id="wmUV">Loading…</div>
                        	</div>
                    	</div>
                    	<div class="wm-extra-item-inline">
                        	<div class="wm-extra-ico ico-wind"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"/></svg></div>
                        	<div>
                            	<div class="wm-extra-lbl">Wind Direction</div>
                            	<div class="wm-extra-val" id="wmWindDir">Loading…</div>
                        	</div>
                    	</div>
                	</div>
                    <div>
                        <div class="wm-sec-lbl" style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                            <div class="wm-sec-lbl"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>15-Day Forecast</div>
                            <span id="wmRiskChip" style="display:none;align-items:center;gap:4px;font-size:10.5px;font-weight:700;padding:3px 10px;border-radius:20px;white-space:nowrap;"></span>
                        </div>
                        <div class="wm-fc-scroll">
                            <div class="wm-fc-strip" id="wmForecastStrip"></div>
                        </div>
                        <div id="wmRiskNote" style="font-size:10.5px;color:var(--text-muted);margin-top:7px;line-height:1.5;"></div>
                        <div id="wmForecastHistory" style="margin-top:16px"></div>
                    </div>
                    <div>
                        <div class="wm-sec-lbl"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22V12"/><path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8"/><path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8"/></svg>Farming Advisory</div>
                        <div class="wm-adv-list" id="wmFarmAdvisory"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div id="riceModal" class="modal">
        <div class="modal-box">
            <div class="rm-header">
            	<div class="rm-header-top">
                	<div class="rm-header-icon">
                    	<svg viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                	</div>
                	<div>
                    	<div class="wm-hdr-sup">Philippines · DA Bantay Presyo · PSA</div>
                    	<div class="wm-hdr-title">Rice Market Trends</div>
                	</div>
            	</div>
            	<button class="wm-close-btn" onclick="closeModal('riceModal')">×</button>
        	</div>
            <div class="rm-stats">
                <div class="rm-stat">
                    <div class="rm-stat-val" id="riceCurrentVal" style="color:var(--gold);">…</div>
                    <div class="rm-stat-lbl">Avg. Retail</div>
                </div>
                <div class="rm-stat">
                    <div class="rm-stat-val" id="riceMsrpVal" style="color:var(--sage-dark);">…</div>
                    <div class="rm-stat-lbl">DA MSRP</div>
                </div>
                <div class="rm-stat">
                    <div class="rm-stat-val" id="riceWmVal" style="color:#4a90d9;">…</div>
                    <div class="rm-stat-lbl">Well-Milled</div>
                </div>
                <div class="rm-stat">
                    <div class="rm-stat-val" id="riceChangeVal">…</div>
                    <div class="rm-stat-lbl">Year-on-Year</div>
                </div>
            </div>
            <div id="riceLiveBar" style="display:flex;align-items:center;gap:8px;padding:7px 20px;background:var(--gold-pale);border-bottom:1px solid var(--border-light);font-size:12px;color:var(--gold);font-weight:600;flex-shrink:0;"><span id="riceBarDot" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--gold);animation:wmPulse 1.1s infinite;flex-shrink:0;"></span><span id="riceBarText">Checking DA website…</span></div>
            <div class="rm-body">
                <div class="rm-left">
                    <div id="riceStatusAlert" style="display:flex;align-items:flex-start;gap:11px;padding:13px 15px;border-radius:var(--radius-sm);background:var(--gold-pale);flex-shrink:0;">
                    	<span id="riceAlertIcon" style="display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--gold);">
                        	<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    	</span>
                    	<div>
                        	<div id="riceAlertTitle" style="font-size:14px;font-weight:700;color:var(--text-primary);margin-bottom:3px;">Loading…</div>
                        	<div id="riceAlertDesc" style="font-size:12.5px;color:var(--text-secondary);line-height:1.55;">Retrieving current DA price data.</div>
                    	</div>
                	</div>
                    <div>
                        <div class="rm-sec-lbl"><svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>Price Trend (₱/kg)</div>
                        <div class="rm-chart-tab-row"><button class="rm-chart-tab active" onclick="setRiceChartView('all')" data-view="all">All Prices</button><button class="rm-chart-tab" onclick="setRiceChartView('regular')" data-view="regular">Regular Only</button><button class="rm-chart-tab" onclick="setRiceChartView('msrp')" data-view="msrp">vs. MSRP</button></div>
                        <div class="rm-chart-wrap"><canvas id="riceModalChart"></canvas></div>
                    </div>
                    <div>
                        <div class="rm-sec-lbl"><svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>Price Forecast (Next 3 Months)</div>
                        <div id="riceForecastCards" style="display:flex;gap:8px;flex-wrap:wrap;"></div>
                        <div id="rmForecastHistory" style="margin-top:16px"></div>
                    </div>
                    <div style="background:var(--sage-pale);border-radius:var(--radius-sm);padding:13px 15px;display:flex;gap:10px;flex-shrink:0;">
                        <div id="riceSellingTipText" style="font-size:12.5px;color:var(--text-secondary);line-height:1.6;">Loading market insight…</div>
                    </div>
                    <div id="riceSourceLine" style="font-size:11px;color:var(--text-muted);flex-shrink:0;"></div>
                </div>
                <div class="rm-right">
                    <div class="wm-extra-inline">
                            <div class="wm-extra-item-inline">
                                <div class="wm-extra-ico ico-gold"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="21" x2="21" y2="21"/><line x1="5" y1="21" x2="5" y2="10"/><line x1="19" y1="21" x2="19" y2="10"/><polygon points="12 2 21 8 3 8"/></svg></div>
                                <div>
                                    <div class="wm-extra-lbl">Current MSRP</div>
                                    <div class="wm-extra-val" id="rmQMsrp">Loading…</div>
                                </div>
                            </div>
                            <div class="wm-extra-item-inline">
                                <div class="wm-extra-ico ico-sage"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
                                <div>
                                    <div class="wm-extra-lbl">Effective Since</div>
                                    <div class="wm-extra-val" id="rmQDate">Loading…</div>
                                </div>
                            </div>
                            <div class="wm-extra-item-inline">
                                <div class="wm-extra-ico ico-sky"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></div>
                                <div>
                                    <div class="wm-extra-lbl">6-Month Change</div>
                                    <div class="wm-extra-val" id="rmQ6mo">Loading…</div>
                                </div>
                            </div>
                        </div>
                    <div>
                        <div class="rm-sec-lbl"><svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="12" height="16" rx="2"/><path d="M9 4V2h6v2"/><line x1="9" y1="9" x2="15" y2="9"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="12" y2="17"/></svg>DA MSRP History</div>
                        <div class="rm-msrp-list" id="riceMsrpTimeline"></div>
                    </div>
                    <div>
                        <div class="rm-sec-lbl"><svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 8-8v10H3z"/></svg>Rice Variety Prices</div>
                        <div class="rm-variety-grid" id="riceVarietyGrid"></div>
                    </div>
                    <div>
                        <div class="rm-sec-lbl"><svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>Market Advisory</div>
                        <div class="wm-adv-list" id="riceAdvisory"></div>
                    </div>
                </div>
            </div>
            <div class="modal-foot" style="justify-content:center;">
                <span style="font-size:11.5px;color:var(--text-muted);">* Estimates based on DA, PSA, and PhilRice data.</span>
            </div>
        </div>
    </div>
    <div id="recentModal" class="modal">
            <div class="modal-box">
                <div class="am-header">
                    <div class="am-header-top">
                        <div class="am-header-icon am-header-icon-gold">
                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
                            </svg>
                        </div>
                        <div>
                            <div class="am-header-sup">Farm Log</div>
                            <div class="am-header-title">Recent Activities</div>
                        </div>
                    </div>
                    <button class="wm-close-btn" onclick="closeModal('recentModal')">×</button>
                </div>
                <div class="modal-body" id="recentModalBody"></div>
            </div>
        </div>
    <div id="remindersModal" class="modal">
            <div class="modal-box">
                <div class="am-header">
                    <div class="am-header-top">
                        <div class="am-header-icon am-header-icon-plum">
                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round">
                                <path d="M12 7v5l3 3" />
                                <circle cx="12" cy="12" r="10" />
                            </svg>
                        </div>
                        <div>
                            <div class="am-header-sup">Upcoming</div>
                            <div class="am-header-title">Reminders</div>
                        </div>
                    </div>
                    <button class="wm-close-btn" onclick="closeModal('remindersModal')">×</button>
                </div>
                <div class="modal-body" id="remindersModalBody"></div>
            </div>
        </div>
    <script>
        const PHP_USER = <?= $jsUser ?>;
        const PHP_RECORDS = <?= $jsRecords ?>;
        const PHP_REMINDERS = <?= $jsReminders ?>;
        const PHP_ACTIVE_CYCLES = <?= $jsActiveCycles ?>;
        const PHP_FIELD_LAYOUT = <?= $jsFieldLayout ?>;
        const PHP_KNOWN_FIELDS_VIZ = <?= $jsKnownFieldsViz ?>;

        function updateSunPosition(n) {
            const sun = document.getElementById('bannerSun');
            if (!sun) return;
            const hour = n.getHours() + n.getMinutes() / 60;
            const dawn = 5.5, dusk = 18.25;
            const isDay = hour >= dawn && hour <= dusk;
            const span = isDay ? (dusk - dawn) : (24 - (dusk - dawn));
            const t = isDay ? (hour - dawn) / span : ((hour > dusk ? hour - dusk : hour + 24 - dusk) / span);
            const clamped = Math.min(Math.max(t, 0), 1);
            sun.style.left = `calc(${(clamped * 100).toFixed(1)}% - 8px)`;
            sun.style.top = (26 - Math.sin(clamped * Math.PI) * 20) + 'px';
            sun.classList.toggle('is-night', !isDay);
        }

        function tick() {
            const n = new Date();
            document.getElementById('realDate').textContent = n.toLocaleDateString('en-US', {
                month: 'short', day: 'numeric', year: 'numeric'
            });
            document.getElementById('realTime').textContent = n.toLocaleTimeString('en-US', {
                hour: '2-digit', minute: '2-digit', hour12: true
            });
            updateSunPosition(n);
        }
        tick();
        setInterval(tick, 1000);
        const isMobile = () => window.innerWidth <= 768;

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
                setTimeout(rebuildCharts, 340)
            }
        }

        function openSidebar() {
            document.getElementById('sidebar').classList.add('open');
            document.getElementById('sidebarOverlay').classList.add('show');
            document.body.classList.add('nav-open')
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('show');
            document.body.classList.remove('nav-open')
        }
        document.querySelectorAll('.nav-item').forEach(el => el.addEventListener('click', () => {
            if (isMobile()) closeSidebar()
        }));
        window.addEventListener('resize', () => {
            if (!isMobile()) closeSidebar();
            setTimeout(rebuildCharts, 120)
        });
        document.addEventListener('keydown', e => {
            if (e.key === '[' && !e.target.closest('input,textarea')) toggleSidebar()
        });
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

        const BELL_ICON_TYPES = ['plant', 'weather', 'reminder', 'harvest', 'pest', 'water'];

        function renderBellNotifs() {
            const list = document.getElementById('ndList');
            if (!list) return;
            if (!_bellNotifs.length) {
                list.innerHTML = '<div style="padding:24px 16px;text-align:center;font-size:12.5px;color:var(--text-muted);">No notifications yet.</div>';
                return;
            }
            list.innerHTML = _bellNotifs.map(n => {
                const iconClass = BELL_ICON_TYPES.includes(n.type) ? n.type : 'default';
                return `
                <div class="nd-item ${n.status}${parseInt(n.starred, 10) ? ' starred' : ''}">
                    <div class="nd-icon nd-ic-${iconClass}">${bellNotifIcon(n.type)}</div>
                    <div>
                        <div class="nd-item-title">${parseInt(n.starred, 10) ? '⭐ ' : ''}${bellEscapeHtml(n.title)}</div>
                        <div class="nd-item-desc">${bellEscapeHtml(n.description || '')}</div>
                        <div class="nd-item-date">${n.notif_date || ''}</div>
                    </div>
                </div>`;
            }).join('');
        }

        function fetchBellNotifs() {
            return fetch('notif_list.php').then(res => res.json()).then(json => {
                    if (json.success) {
                        _bellNotifs = json.notifications;
                        _bellLoaded = true;
                        renderBellNotifs();
                    }
                })
                .catch(() => {
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

        function getUser() {
            return PHP_USER
        }
        const STEP_TEMPLATE = [{
                name: "🚜 Land Preparation",
                startOffset: 0,
                endOffset: 5
            },
            {
                name: "🌱 Planting Seedlings",
                startOffset: 6,
                endOffset: 10
            },
            {
                name: "🌿 Weeding",
                startOffset: 11,
                endOffset: 15
            },
            {
                name: "⏱️ Growth Stage",
                startOffset: 16,
                endOffset: 38
            },
            {
                name: "🧪 Fertilizer",
                startOffset: 23,
                endOffset: 26
            },
            {
                name: "🐛 Insecticide",
                startOffset: 28,
                endOffset: 30
            },
            {
                name: "💧 Spray Insecticide",
                startOffset: 33,
                endOffset: 36
            },
            {
                name: "🍄 Spray Fungicide",
                startOffset: 36,
                endOffset: 40
            },
            {
                name: "🌾 Harvest",
                startOffset: 50,
                endOffset: 54
            }
        ];

        function addDays(dateStr, days) {
            const [y, m, d] = dateStr.split('-').map(Number);
            const dt = new Date(y, m - 1, d);
            dt.setDate(dt.getDate() + days);
            return dt;
        }

        function cycleProgress(cycle) {
            const now = new Date();
            const doneSteps = cycle.done_steps || [];
            let done = 0,
                activeStep = null;
            STEP_TEMPLATE.forEach((t, i) => {
                const start = addDays(cycle.start_date, t.startOffset),
                    end = addDays(cycle.start_date, t.endOffset);
                const isManual = doneSteps.includes(i);
                const isDone = isManual || now > end;
                const isActive = !isDone && now >= start;
                if (isDone) done++;
                if (isActive && !activeStep) activeStep = t.name;
            });
            return {
                pct: Math.round(done / STEP_TEMPLATE.length * 100),
                currentStep: activeStep,
                done,
                total: STEP_TEMPLATE.length
            };
        }

        let activityCycleIndex = 0;

        function navActivity(dir) {
            if (!PHP_ACTIVE_CYCLES.length) return;
            const n = PHP_ACTIVE_CYCLES.length;
            activityCycleIndex = (activityCycleIndex + dir + n) % n;
            calcStats();
        }

        function calcStats() {
            const ringFill = document.getElementById('ringFill');
            const circ = 2 * Math.PI * 42;
            ringFill.style.strokeDasharray = circ;
            const statsEl = document.getElementById('activityStatsMini');   // viewBtn line removed
            const badgeEl = document.getElementById('activityLiveBadge');
            const prevBtn = document.getElementById('actPrevBtn');
            const nextBtn = document.getElementById('actNextBtn');
            const dotsEl = document.getElementById('activityDots');

            if (!PHP_ACTIVE_CYCLES.length) {
                document.getElementById('ringText').textContent = '—';
                document.getElementById('activityHeading').textContent = 'No active planting process';
                ringFill.style.strokeDashoffset = circ;
                // viewBtn lines removed
                if (statsEl) statsEl.innerHTML = '';
                if (badgeEl) badgeEl.style.display = 'none';
                if (prevBtn) prevBtn.style.display = 'none';
                if (nextBtn) nextBtn.style.display = 'none';
                if (dotsEl) dotsEl.innerHTML = '';
                return;
            }

            if (activityCycleIndex >= PHP_ACTIVE_CYCLES.length) activityCycleIndex = 0;
            const current = PHP_ACTIVE_CYCLES[activityCycleIndex];
            const prog = cycleProgress(current);
            document.getElementById('ringText').textContent = prog.pct + '%';
            ringFill.style.strokeDashoffset = circ - (prog.pct / 100) * circ;
            document.getElementById('activityHeading').textContent =
                prog.currentStep ? `${current.field_location}: ${prog.currentStep}` : `${current.field_location}: ${prog.pct}% done`;
            // viewBtn lines removed

            const daysActive = Math.floor((new Date() - new Date(current.start_date)) / 86400000);
            if (statsEl) {
                statsEl.innerHTML = `
                    <span class="fv-chip" style="background:var(--sage-pale);border-color:var(--sage-light);color:var(--sage-dark)">${prog.done}/${prog.total} steps</span>
                    <span class="fv-chip">Day ${daysActive}</span>
                `;
            }
            if (badgeEl) {
                badgeEl.style.display = 'inline-flex';
                badgeEl.textContent = prog.pct >= 100 ? 'Ready' : 'Active';
            }

            const multi = PHP_ACTIVE_CYCLES.length > 1;
            if (prevBtn) prevBtn.style.display = multi ? 'flex' : 'none';
            if (nextBtn) nextBtn.style.display = multi ? 'flex' : 'none';
            if (dotsEl) {
                dotsEl.innerHTML = multi ? PHP_ACTIVE_CYCLES.map((_, i) =>
                    `<span class="act-dot-nav${i === activityCycleIndex ? ' active' : ''}"></span>`
                ).join('') : '';
            }
        }
        function buildActivityModal() {
            const statsGrid = document.getElementById('cycleSummaryList');
            const avgEl = document.getElementById('actStatAvg'),
                fieldsEl = document.getElementById('actStatFields');
            if (!PHP_ACTIVE_CYCLES.length) {
                fieldsEl.textContent = '0';
                avgEl.textContent = '—';
                statsGrid.innerHTML = `<div class="no-cycles-modal">
                    <div class="no-cycles-modal-icon">🌾</div>
                    <div class="no-cycles-modal-text">No active planting process yet. Start one from the Planting Process page to track it here.</div>
                    <a href="process.php" class="view-btn" style="text-decoration:none;">+ Start New Process</a>
                </div>`;
                return;
            }
            fieldsEl.textContent = PHP_ACTIVE_CYCLES.length;
            const progresses = PHP_ACTIVE_CYCLES.map(c => cycleProgress(c));
            const avgPct = Math.round(progresses.reduce((s, p) => s + p.pct, 0) / progresses.length);
            avgEl.textContent = avgPct + '%';
            statsGrid.innerHTML = PHP_ACTIVE_CYCLES.map((c, i) => {
                const prog = progresses[i];
                const circ2 = 2 * Math.PI * 18;
                const offset = circ2 - (prog.pct / 100) * circ2;
                const stepText = prog.currentStep ? stepLabelText(prog.currentStep) : (prog.pct >= 100 ? 'Ready to complete' : 'Upcoming');
                const stepIcon = prog.currentStep ? stepIconSvg(prog.currentStep) : '';

                return `<a href="process.php?cycle=${c.id}" class="cycle-summary-row">
                    <div class="cycle-summary-ring">
                        <svg width="44" height="44" viewBox="0 0 44 44">
                            <circle class="bg" cx="22" cy="22" r="18" />
                            <circle class="fill" cx="22" cy="22" r="18" stroke-dasharray="${circ2}" stroke-dashoffset="${offset}" />
                        </svg>
                        <div class="cycle-summary-pct">${prog.pct}%</div>
                    </div>
                    <div class="cycle-summary-info">
                        <div class="cycle-summary-field">${c.field_location}${c.crop_type ? ' · ' + c.crop_type : ''}</div>
                        <div class="cycle-summary-step">
                            ${stepIcon ? `<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="var(--sage-dark)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:4px">${stepIcon}</svg>` : ''}${stepText}
                        </div>
                    </div>
                    <div class="cycle-summary-arrow">›</div>
                </a>`;
            }).join('');
        }
        const quarterlyProfit = [38500, 42300, 47800, 52050];
        const quarterLabels = ['Q1 Apr–Jun', 'Q2 Jul–Sep', 'Q3 Oct–Dec', 'Q4 Jan–Mar'];
        let pCh = null,
            wCh = null,
            mCh = null;

        function fadeGridColor(rgb) {
            return (ctx) => {
                const scale = ctx.scale;
                const range = (scale.max - scale.min) || 1;
                const ratioFromBottom = (ctx.tick.value - scale.min) / range;
                const opacity = 0.05 + (1 - ratioFromBottom) * 0.22;
                return `rgba(${rgb},${opacity.toFixed(3)})`;
            };
        }
        
        function rebuildCharts() {
            const pc = document.getElementById('profitCanvas');
        	if (pc) {
            	if (pCh) pCh.destroy();
            	const ctx = pc.getContext('2d');
            	const g = ctx.createLinearGradient(0, 0, 0, 200);
            	g.addColorStop(0, 'rgba(61,92,56,0.52)');
            	g.addColorStop(1, 'rgba(90,122,82,0.0)');
            	const currentQd = window._latestNetProfitQd || quarterlyProfit;
            	pCh = new Chart(pc, {
                	type: 'line',
                	data: {
                    	labels: window._latestNetProfitLabels || quarterLabels,
                    	datasets: [{
                        	data: currentQd,
                            borderColor: '#3d5c38',
                            backgroundColor: g,
                            fill: true,
                            borderWidth: 3,
                            tension: 0.42,
                            pointRadius: [3, 3, 3, 7],
                            pointBackgroundColor: '#fff',
                            pointBorderColor: '#3d5c38',
                            pointBorderWidth: 2.5,
                            pointHoverRadius: 7
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: { label: c => ' ₱' + Math.round(c.raw).toLocaleString() } }
                        },
                        scales: {
                            x: {
                                display: true,
                                grid: { display: false },
                                border: { display: false },
                                ticks: { color: '#94a18e', font: { size: 10 }, maxRotation: 0 }
                            },
                            y: {
                                    display: true,
                                    ticks: { display: false },
                                    border: { display: false },
                                    grid: {
                                        color: fadeGridColor('61,92,56'),
                                        lineWidth: 1
                                    }
                                }
                        }
                    }
                });

                const tag = document.getElementById('profitChartTag'), tagText = document.getElementById('profitChartTagText');
                if (tag && tagText) {
                    const last = currentQd[currentQd.length - 1];
                    const prev = currentQd[currentQd.length - 2];
                    if (prev) {
                        const pct = Math.round(((last - prev) / prev) * 100);
                        tagText.textContent = `${pct >= 0 ? '+' : ''}${pct}% vs last qtr`;
                        tag.classList.toggle('is-down', pct < 0);
                        tag.style.display = 'flex';
                    }
                }
            }
            const wc = document.getElementById('weatherCanvas');
        	if (wc) {
            	if (wCh) wCh.destroy();
            	const wctx = wc.getContext('2d');
            	const wg = wctx.createLinearGradient(0, 0, 0, 60);
            	wg.addColorStop(0, 'rgba(74,144,217,.85)');
            	wg.addColorStop(1, 'rgba(74,144,217,.35)');
            	wCh = new Chart(wc, {
                	type: 'bar',
                	data: {
                    	labels: ['6am', '9am', '12pm', '3pm', '6pm', '9pm'],
                    	datasets: [{
                        	data: [26, 28, 32, 34, 31, 27],
                        	backgroundColor: wg,
                        	borderRadius: { topLeft: 6, topRight: 6 },
                        	borderSkipped: false,
                        	barPercentage: 0.62
                    	}]
                	},
                	options: {
                    	responsive: true,
                    	maintainAspectRatio: false,
                    	plugins: {
                        	legend: { display: false },
                        	tooltip: { callbacks: { label: c => c.raw + '°C' } }
                    	},
                    	scales: {
                        	x: { ticks: { color: '#94a18e', font: { size: 10 } }, grid: { display: false }, border: { display: false } },
                        	y: {
                                    display: true,
                                    ticks: { display: false },
                                    border: { display: false },
                                    grid: {
                                        color: fadeGridColor('74,144,217'),
                                        lineWidth: 1
                                    }
                                }
                    	}
                	}
            	})
        	}
            const mc = document.getElementById('marketCanvas');
                if (mc) {
                    if (mCh) mCh.destroy();
                    mCh = new Chart(mc, {
                        type: 'line',
                        data: {
                            labels: ['Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan'],
                            datasets: [{
                                label: 'Regular Milled',
                                data: [40, 40, 41, 42, 42, 45],
                                borderColor: 'rgba(200,150,62,.88)',
                                backgroundColor: 'rgba(200,150,62,.1)',
                                fill: true,
                                borderWidth: 2,
                                pointRadius: [0, 0, 0, 0, 0, 5],
                                pointBackgroundColor: '#fff',
                                pointBorderColor: 'rgba(200,150,62,.9)',
                                pointBorderWidth: 2,
                                tension: .4
                            }, {
                                label: 'DA Standard',
                                data: [52, 52, 52, 52, 52, 52],
                                borderColor: 'rgba(90,122,82,.4)',
                                backgroundColor: 'transparent',
                                borderWidth: 1.5,
                                borderDash: [5, 4],
                                pointRadius: 0
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'bottom',
                                    labels: { font: { size: 9 }, padding: 8, boxWidth: 12 }
                                },
                                tooltip: { callbacks: { label: c => `${c.dataset.label}: ₱${c.raw}/kg` } }
                            },
                            scales: {
                                x: { ticks: { color: '#94a18e', font: { size: 10 } }, grid: { display: false }, border: { display: false } },
                                y: {
    display: true,
    ticks: { display: false, count: 4 },
    border: { display: false },
    grid: {
        color: fadeGridColor('200,150,62'),
        lineWidth: 1
    }
}
                            }
                        }
                    })
                }
        }
            
        function wmoIconSvg(code) {
            if (code === 0) return '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>';
            if (code <= 2) return '<path d="M12 3v1M18.36 5.64l-.7.7M21 12h-1M6.34 6.34l-.7-.7"/><path d="M17.5 19a4.5 4.5 0 1 0-1.44-8.77A6 6 0 1 0 7 19h10.5Z"/>';
            if (code <= 3) return '<path d="M17.5 19a4.5 4.5 0 1 0-1.44-8.77A6 6 0 1 0 7 19h10.5Z"/>';
            if (code <= 48) return '<path d="M5 5a4 4 0 0 1 7.94-.7A5 5 0 0 1 20 9a5 5 0 0 1-.28 1.66"/><path d="M2 13h20M2 17h20M2 21h20"/>';
            if (code <= 67) return '<path d="M16 13v8M8 13v8M12 15v8M20 16.58A5 5 0 0 0 18 7h-1.26A8 8 0 1 0 4 15.25"/>';
            if (code <= 77) return '<path d="M20 17.58A5 5 0 0 0 18 8h-1.26A8 8 0 1 0 4 16.25"/><path d="M8 16h.01M8 20h.01M12 18h.01M12 22h.01M16 16h.01M16 20h.01"/>';
            if (code <= 82) return '<path d="M16 13v8M8 13v8M12 15v8M20 16.58A5 5 0 0 0 18 7h-1.26A8 8 0 1 0 4 15.25"/>';
            if (code <= 99) return '<path d="M6 16.326A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="m13 12-3 5h4l-3 5"/>';
            return '<path d="M17.5 19a4.5 4.5 0 1 0-1.44-8.77A6 6 0 1 0 7 19h10.5Z"/>';
        }

        function wmoIcon(c) {
            if (c === 0) return '☀️';
            if (c <= 2) return '🌤️';
            if (c <= 3) return '☁️';
            if (c <= 48) return '🌫️';
            if (c <= 57) return '🌦️';
            if (c <= 67) return '🌧️';
            if (c <= 77) return '🌨️';
            if (c <= 82) return '🌦️';
            if (c <= 99) return '⛈️';
            return '🌡️'
        }

        function wmoLabel(c) {
            if (c === 0) return 'Clear Sky';
            if (c <= 2) return 'Partly Cloudy';
            if (c <= 3) return 'Overcast';
            if (c <= 48) return 'Foggy';
            if (c <= 57) return 'Drizzle';
            if (c <= 67) return 'Rainy';
            if (c <= 77) return 'Snowy';
            if (c <= 82) return 'Rain Showers';
            if (c <= 99) return 'Thunderstorm';
            return 'Unknown'
        }

        function uvLabel(u) {
            if (u <= 2) return 'Low';
            if (u <= 5) return 'Moderate';
            if (u <= 7) return 'High';
            if (u <= 10) return 'Very High';
            return 'Extreme'
        }

        function windDirLabel(d) {
            return ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'][Math.round(d / 45) % 8]
        }

        function fmtTime(iso) {
            if (!iso) return '—';
            return new Date(iso).toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            })
        }

        function setEl(id, val) {
            const e = document.getElementById(id);
            if (e) e.textContent = val
        }
        // ===== FORECAST HELPERS (RandomForest + LSTM ensemble via forecast-algorithms.js) =====
        function renderForecastCards(containerId, labels, fc, formatFn, color) {
            const el = document.getElementById(containerId);
            if (!el) return;
            el.innerHTML = fc.blended.map((v, idx) => `
                <div style="flex:1;min-width:100px;background:var(--cream-2);border:1px solid var(--border-light);border-radius:var(--radius-sm);padding:9px 10px;text-align:center">
                    <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px">${labels[idx] || ('+' + (idx + 1))}</div>
                    <div style="font-family:'DM Mono',monospace;font-size:16px;font-weight:700;color:${color}">${formatFn(v)}</div>
                    <div style="font-size:9.5px;color:var(--text-muted);margin-top:3px">LSTM ${formatFn(fc.lstm[idx])} · RF ${formatFn(fc.randomForest[idx])}</div>
                </div>`).join('');
        }

        function wmRiskLevel(code, rain) {
            if ((code !== null && code !== undefined && code >= 95) || rain >= 70) return 'high';
            if (rain >= 50) return 'moderate';
            return 'low';
        }
        const WM_RISK_STYLE = {
            high: {
                text: '#c0392b',
                bg: '#fdecea',
                  icon: '<path d="M6 16.326A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="m13 12-3 5h4l-3 5"/>',
                label: 'High risk'
            },
            moderate: {
                text: '#8a6100',
                bg: '#fff3cd',
                icon: '<path d="M16 13v8M8 13v8M12 15v8M20 16.58A5 5 0 0 0 18 7h-1.26A8 8 0 1 0 4 15.25"/>',
                label: 'Moderate risk'
            },
            low: {
                text: 'var(--sage-dark)',
                bg: 'var(--sage-pale)',
                icon: '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
                label: 'Low risk'
            }
        };
        // ===== WEATHER HISTORY LOG (weather_history table) =====
        // Saves one snapshot per user per calendar day so RiceWise builds a
        // real local time series over time. Fire-and-forget — a failed save
        // should never block the weather modal from working.
        function logWeatherSnapshot(cur, daily, lat, lng) {
            try {
                return fetch('weather_log_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        latitude: lat,
                        longitude: lng,
                        temp_max: daily?.temperature_2m_max?.[daily.temperature_2m_max.length - 1] ?? cur.temperature_2m ?? null,
                        temp_min: daily?.temperature_2m_min?.[daily.temperature_2m_min.length - 1] ?? cur.temperature_2m ?? null,
                        humidity: cur.relative_humidity_2m ?? null,
                        wind_speed: cur.wind_speed_10m ?? null,
                        wind_direction: cur.wind_direction_10m ?? null,
                        precipitation_probability: cur.precipitation_probability ?? daily?.precipitation_probability_max?.[daily.precipitation_probability_max.length - 1] ?? null,
                        weather_code: cur.weather_code ?? null
                    })
                }).then(r => r.ok ? r.json().catch(() => ({ success: true })) : { success: false })
                  .then(j => j.success !== false)
                  .catch(() => false);
            } catch (e) { return Promise.resolve(false); }
        }
            
                // ===== DB-backed prediction persistence (closes the loop) =====
        function getCurrentQuarterLabel(d) {
            d = d || new Date();
            const y = d.getFullYear(), m = d.getMonth() + 1;
            const q = Math.ceil(m / 3);
            return `${y}-Q${q}`;
        }

        function localISO(d) {
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }

        function firstOfMonthISO(offsetMonths) {
            const d = new Date();
            return localISO(new Date(d.getFullYear(), d.getMonth() + offsetMonths, 1));
        }

        function getNextQuarterLabel() {
            const d = new Date();
            const q = Math.floor(d.getMonth() / 3) + 1;
            return q === 4 ? (d.getFullYear() + 1) + '-Q1' : d.getFullYear() + '-Q' + (q + 1);
        }

        async function saveProfitSnapshotAndForecast(net, gross, totalCost, ha, fg) {
            const periodLabel = getCurrentQuarterLabel();
            const periodDate = (() => {
                const d = new Date();
                const q = Math.ceil((d.getMonth() + 1) / 3);
                return `${d.getFullYear()}-${String((q - 1) * 3 + 1).padStart(2, '0')}-01`;
            })();
            try {
                await fetch('profit_history_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        period_label: periodLabel,
                        period_date: periodDate,
                        net_profit: net,
                        gross_revenue: gross,
                        total_cost: totalCost,
                        hectares: ha,
                        farmgate_price: fg
                    })
                });
            } catch (e) {}
            let qd = [+(net * .88), +(net * 1.05), +(net * .82), +(net * 1.10)];
            let labels = quarterLabels;
            let estimated = true;
            try {
                const res = await fetch('profit_history_list.php');
                const json = await res.json();
                if (json.success && json.history && json.history.length >= 2) {
                    qd = json.history.map(h => parseFloat(h.net_profit));
                    labels = json.history.map(h => h.period_label);
                    estimated = false;
                }
            } catch (e) {}
            return { qd, labels, estimated };
        }

        async function fetchSavedWeatherHistory() {
            try {
                const res = await fetch('weather_history_list.php');
                const json = await res.json();
                if (json.success && json.history) return json.history;
            } catch (e) {}
            return null;
        }

        function saveWeatherForecast(days) {
            try {
                fetch('weather_forecast_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ days })
                }).then(() => { if (window.FH) FH.reload('weather'); }).catch(() => {});
            } catch (e) {}
        }
            
        function saveProfitForecast(fc, basedOnQuarters, estimated) {
            try {
                fetch('profit_forecast_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        forecast_period: getNextQuarterLabel(),
                        blended_value: fc.blended[0],
                        lstm_value: fc.lstm[0],
                        rf_value: fc.randomForest[0],
                        based_on_quarters: basedOnQuarters,
                        is_estimated: estimated ? 1 : 0
                    })
                }).then(() => { if (window.FH) FH.reload('profit'); }).catch(() => {});
            } catch (e) {}
        }

        function saveRiceForecast(fc) {
            try {
                const months = fc.blended.map((v, idx) => ({
                    forecast_month: firstOfMonthISO(idx + 1),
                    blended_price: v,
                    lstm_price: fc.lstm[idx],
                    rf_price: fc.randomForest[idx]
                }));
                fetch('rice_forecast_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ months })
                }).then(() => { if (window.FH) FH.reload('rice'); }).catch(() => {});
            } catch (e) {}
        }

        function buildPmForecast(qd, estimated) {
            const el = document.getElementById('pmForecastCards');
            if (!el) return;
            el.innerHTML = '<div style="font-size:11.5px;color:var(--text-muted)">Calculating…</div>';
            setTimeout(() => {
                try {
                    const fc = ForecastAI.ensembleForecast(qd, {
                        window: 2,
                        stepsAhead: 1,
                        hiddenSize: 3,
                        epochs: 60
                    });
                    saveProfitForecast(fc, qd.length, estimated);
                    const val = fc.blended[0];
                    const color = val >= 0 ? 'var(--sage-dark)' : '#c0392b';
                    const fmt = v => '₱' + Math.round(v).toLocaleString();
                    el.innerHTML = `
                        <div class="pm-forecast-hero">
                            <div class="pm-forecast-hero-top">
                                <span class="pm-forecast-hero-badge">
                                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10H12V2Z"/><path d="M20 8.5a10 10 0 0 0-8.5-6.5"/></svg>
                                    Forecast
                                </span>
                            </div>
                            <div class="pm-forecast-hero-label">Next Quarter</div>
                            <div class="pm-forecast-hero-val" style="color:${color}">${fmt(val)}</div>
                            <div class="pm-forecast-hero-sub">LSTM ${fmt(fc.lstm[0])} · RF ${fmt(fc.randomForest[0])}</div>
                        </div>
                        <div style="width:100%;font-size:10.5px;color:var(--text-muted);margin-top:8px;text-align:center">Modeled on ${qd.length} recent quarters — directional, not exact.</div>
                    `;
                } catch (e) {
                    el.innerHTML = '<div style="font-size:11.5px;color:var(--text-muted)">Not enough history yet for a forecast.</div>';
                }
            }, 20);
        }
                function buildRiceForecast() {
            const el = document.getElementById('riceForecastCards');
            if (!el) return;
            el.innerHTML = '<div style="font-size:11.5px;color:var(--text-muted)">Calculating…</div>';
            setTimeout(() => {
                try {
                    const series = PSA_RETAIL_HISTORY.map(r => r.regular);
                    const fc = ForecastAI.ensembleForecast(series, {
                        window: 4,
                        stepsAhead: 3,
                        hiddenSize: 4,
                        epochs: 80
                    });
                    renderForecastCards('riceForecastCards', ['+1 mo', '+2 mo', '+3 mo'], fc, v => '₱' + v.toFixed(2) + '/kg', 'var(--gold)');
                    saveRiceForecast(fc);
                } catch (e) {
                    el.innerHTML = '<div style="font-size:11.5px;color:var(--text-muted)">Forecast unavailable.</div>';
                }
            }, 20);
        }
            
        // Save today's weather once a day per browser as soon as the dashboard loads,
        // so history no longer depends on opening the weather panel.
        function logDailyWeather() {
            const u = getUser();
            const lat = parseFloat(u.locationLat) || 14.937936,
                lng = parseFloat(u.locationLng) || 120.927572;
            const key = 'rw_weather_logged_' + new Date().toLocaleDateString('en-CA');
            try { if (localStorage.getItem(key)) return; } catch (e) {}
            fetchOpenMeteo(lat, lng).then(data => logWeatherSnapshot(data.current, data.daily, lat, lng)).then(ok => {
                if (ok) { try { localStorage.setItem(key, '1'); } catch (e) {} }
            }).catch(() => {});
        }
            
        function fetchDashboardWeather() {
            const u = getUser();
            const lat = parseFloat(u.locationLat) || 14.937936,
                lng = parseFloat(u.locationLng) || 120.927572;
            fetch(`https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lng}&current=temperature_2m,weather_code,precipitation_probability&timezone=Asia%2FManila&forecast_days=1`)
                .then(r => r.json()).then(d => {
                    const c = d.current;
                    setEl('dashTemp', Math.round(c.temperature_2m) + '°C');
                    document.getElementById('dashDescIcon').innerHTML = wmoIconSvg(c.weather_code);
                	setEl('dashDescText', wmoLabel(c.weather_code) + ' · Rain: ' + (c.precipitation_probability ?? '—') + '%');
                    checkSevereWeatherBanner(c, null);
                }).catch(() => {});
        }
        let _windyLat = 14.937936,
            _windyLng = 120.927572,
            _windyLayer = 'rain';

        function buildWindyUrl(layer, lat, lng) {
            const o = {
                rain: 'rain',
                wind: 'wind',
                temp: 'temp',
                clouds: 'clouds',
                waves: 'waves'
            };
            return `https://embed.windy.com/embed2.html?lat=${lat}&lon=${lng}&detailLat=${lat}&detailLon=${lng}&zoom=7&level=surface&overlay=${o[layer] || 'rain'}&product=ecmwf&menu=&message=true&marker=true&calendar=now&pressure=&type=map&location=coordinates&detail=&metricWind=km%2Fh&metricTemp=%C2%B0C&radarRange=-1`
        }

        function setWindyLayer(layer) {
            _windyLayer = layer;
            document.querySelectorAll('.wm-layer-btn').forEach(b => b.classList.toggle('active', b.dataset.layer === layer));
            const iframe = document.getElementById('weatherMapIframe');
            if (iframe) iframe.src = buildWindyUrl(layer, _windyLat, _windyLng)
        }

        function openModal(id) {
            document.getElementById(id).classList.add('show');
            if (id === 'profitModal') buildProfitModal();
            if (id === 'activityModal') buildActivityModal();
            if (id === 'weatherModal') buildWeatherModal();
                    if (id === 'riceModal') buildRiceModal();
            if (id === 'recentModal') buildRecentModal();
            if (id === 'remindersModal') buildRemindersModal()
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show')
        }
        window.addEventListener('click', e => {
            if (e.target.classList.contains('modal')) e.target.classList.remove('show')
        });
        let PSA_RETAIL_HISTORY = [];
        let DA_MSRP_HISTORY = [];

        function loadPriceHistory() {
            return fetch('price_history_list.php')
                .then(r => r.json())
                .then(json => {
                    if (!json.success) return;
                    PSA_RETAIL_HISTORY = json.prices.map(p => ({
                        label: new Date(p.price_month + '-01').toLocaleDateString('en-US', { month: 'short', year: 'numeric' }),
                        regular: parseFloat(p.regular_milled),
                        wellMilled: p.well_milled !== null ? parseFloat(p.well_milled) : null,
                        special: p.special !== null ? parseFloat(p.special) : null
                    }));
                    DA_MSRP_HISTORY = json.msrps.map(m => ({
                        date: m.effective_date,
                        msrp: m.msrp !== null ? parseFloat(m.msrp) : null,
                        label: new Date(m.effective_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }),
                        source: m.source,
                        suspended: !!parseInt(m.is_suspended, 10),
                        scheduled: !!parseInt(m.is_scheduled, 10)
                    }));
                })
                .catch(() => {}); // arrays stay empty; guarded below
        }

        function getCurrentMsrp() {
            const today = new Date();
            let cur = null;
            for (const e of DA_MSRP_HISTORY) {
                if (!e.suspended && new Date(e.date) <= today) cur = e
            }
            return cur || DA_MSRP_HISTORY.find(e => e.msrp !== null)
        }
            
        function setElHTML(id, html) {
            const e = document.getElementById(id);
            if (e) e.innerHTML = html;
        }

        function pmAlertIconSvg(type) {
            const icons = {
                good: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
                warn: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
                bad: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
            };
            return icons[type] || icons.good;
        }
            
        function riceAlertIconSvg(type) {
            const icons = {
                info: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
                good: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
                warn: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
                down: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/></svg>'
            };
            return icons[type] || icons.info;
        }
            
        const STEP_ICONS = [
            { match: 'Land Preparation', icon: '<path d="M4 20V10l8-6 8 6v10"/><path d="M9 20v-6h6v6"/>' },
            { match: 'Planting Seedlings', icon: '<path d="M12 22V12"/><path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8"/><path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8"/>' },
            { match: 'Weeding', icon: '<path d="M12 22V12"/><path d="M9 15c0-3 1-5 3-7 2 2 3 4 3 7"/>' },
            { match: 'Growth Stage', icon: '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/>' },
            { match: 'Fertilizer', icon: '<path d="M9 3h6l1 4H8z"/><path d="M8 7h8l1 13a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1z"/>' },
            { match: 'Insecticide', icon: '<path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="M8.5 8.5 16 16"/>' },
            { match: 'Fungicide', icon: '<path d="M9 18h6M10 22h4M12 2a6 6 0 0 0-4 10.5c.6.6 1 1.4 1 2.5h6c0-1.1.4-1.9 1-2.5A6 6 0 0 0 12 2Z"/>' },
            { match: 'Harvest', icon: '<path d="M3 17l6-6 4 4 8-8v10H3z"/>' }
        ];
        const STEP_ICON_DEFAULT = '<circle cx="12" cy="12" r="9"/>';

        function stepIconSvg(rawName) {
            if (!rawName) return STEP_ICON_DEFAULT;
            const clean = rawName.replace(/^[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{2300}-\u{23FF}\u{2B00}-\u{2BFF}\u{FE0F}\u{200D}]+\s*/u, '').trim();
            const found = STEP_ICONS.find(s => clean.includes(s.match));
            return found ? found.icon : STEP_ICON_DEFAULT;
        }

        function stepLabelText(rawName) {
            return rawName ? rawName.replace(/^[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{2300}-\u{23FF}\u{2B00}-\u{2BFF}\u{FE0F}\u{200D}]+\s*/u, '').trim() : '';
        }

        const ICON_COST = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>';
        const ICON_STORAGE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/></svg>';
        const ICON_RULER = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.3 15.3a2.4 2.4 0 0 1 0 3.4l-2.6 2.6a2.4 2.4 0 0 1-3.4 0L2.7 8.7a2.4 2.4 0 0 1 0-3.4l2.6-2.6a2.4 2.4 0 0 1 3.4 0Z"/><path d="m14.5 12.5 2-2M11.5 9.5l2-2M8.5 6.5l2-2M17.5 15.5l2-2"/></svg>';

        function buildProfitModal() {
            const YIELD = 3.9,
                KG = 3900,
                FGRAT = 0.60;
            const COSTS = [{
                label: 'Seeds & Seedbed',
                amount: 2800,
                type: 'major'
            }, {
                label: 'Land Preparation',
                amount: 5500,
                type: 'major'
            }, {
                label: 'Fertilizer',
                amount: 7200,
                type: 'major'
            }, {
                label: 'Pesticides',
                amount: 3100,
                type: 'minor'
            }, {
                label: 'Irrigation',
                amount: 2400,
                type: 'minor'
            }, {
                label: 'Labor (planting)',
                amount: 4800,
                type: 'major'
            }, {
                label: 'Labor (weeding)',
                amount: 2200,
                type: 'minor'
            }, {
                label: 'Labor (harvesting)',
                amount: 4500,
                type: 'major'
            }, {
                label: 'Post-harvest / misc.',
                amount: 1800,
                type: 'minor'
            }];
            const u = getUser(),
                ha = parseFloat(u.hectares) || 1;
            const psa = PSA_RETAIL_HISTORY[PSA_RETAIL_HISTORY.length - 1].regular,
                fg = +(psa * FGRAT).toFixed(2);
            const totalKg = KG * ha,
                totalCost = COSTS.reduce((s, c) => s + c.amount, 0) * ha;
            const gross = +(totalKg * fg),
                net = +(gross - totalCost),
                margin = +((net / gross) * 100).toFixed(1),
                roi = +((net / totalCost) * 100).toFixed(1);
            const fmt = v => '₱' + Math.round(v).toLocaleString();
            setEl('pmNetProfit', fmt(net));
            setEl('pmGrossRev', fmt(gross));
            setEl('pmTotalCost', fmt(totalCost));
            const me = document.getElementById('pmMargin');
            if (me) {
                me.textContent = margin + '%';
                me.style.color = margin >= 20 ? 'var(--sage-dark)' : margin >= 10 ? 'var(--gold)' : '#c0392b'
            }
            const paEl = document.getElementById('profitAmountValue');
			if (paEl) paEl.textContent = Math.round(net).toLocaleString();
            setEl('profitChange', (roi >= 0 ? '+' : '') + roi + '% ROI');
            setEl('pmYieldHa', YIELD + ' MT/ha');
            setEl('pmHaVal', ha + ' ha');
            setEl('pmFarmgate', '₱' + fg + '/kg');
            const nb = document.getElementById('pmNoticeText');
            if (nb) nb.innerHTML = `Assuming <strong>${ha} ha</strong> · PSA retail ₱${psa}/kg · Farmgate ≈ ₱${fg}/kg`;
            let aiType, aiColor, aiBg, at, ad;
            if (net > 0 && margin >= 20) {
                aiType = 'good';
                aiColor = 'var(--sage-dark)';
                aiBg = 'var(--sage-pale)';
                at = 'Profitable';
                ad = `Net profit ${fmt(net)} at ₱${fg}/kg. ROI ${roi}%.`
            } else if (net > 0) {
                aiType = 'warn';
                aiColor = '#a06a1e';
                aiBg = '#fff3d6';
                at = 'Thin Margin';
                ad = `Net profit ${fmt(net)} at ₱${fg}/kg. Consider reducing input costs.`
            } else {
                aiType = 'bad';
                aiColor = '#c0392b';
                aiBg = '#fdecea';
                at = 'Below Break-Even';
                ad = `At ₱${fg}/kg, costs exceed revenue by ${fmt(Math.abs(net))}.`
            }
        const ae = document.getElementById('pmSeasonAlert');
        if (ae) {
            ae.style.setProperty('--pm-verdict-color', aiColor);
            ae.style.setProperty('--pm-verdict-bg', aiBg);
        }
        const alertIconEl = document.getElementById('pmAlertIcon');
            if (alertIconEl) alertIconEl.innerHTML = pmAlertIconSvg(aiType);
        setEl('pmVerdictBadge', margin + '% margin');
        setEl('pmAlertTitle', at);
        setEl('pmAlertDesc', ad);
            const rb = document.getElementById('pmRevenueBar');
                if (rb) rb.innerHTML = [{
                    label: 'Gross Revenue',
                    val: gross,
                    pct: 100,
                    color: 'var(--gold)',
                    bg: 'var(--cream-2)',
                    icon: '<path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'
                }, {
                    label: 'Total Input Cost',
                    val: totalCost,
                    pct: (totalCost / gross) * 100,
                    color: '#c0392b',
                    bg: 'var(--cream-2)',
                    icon: '<path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/>'
                }, {
                    label: 'Net Profit',
                    val: net,
                    pct: Math.max(0, (net / gross) * 100),
                    color: 'var(--gold)',
                    bg: 'var(--cream-2)',
                    icon: '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>'
                }].map(b => `
                    <div class="pm-rev-bar-row">
                        <div class="pm-rev-bar-icon" style="background:${b.bg};--rb-color:${b.color}">
                            <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">${b.icon}</svg>
                        </div>
                        <div class="pm-rev-bar-main">
                            <div class="pm-rev-bar-label"><span>${b.label}</span><span style="color:${b.color}">${fmt(b.val)}</span></div>
                            <div class="pm-rev-bar-track"><div class="pm-rev-bar-fill" style="width:${b.pct.toFixed(1)}%;background:${b.color}"></div></div>
                        </div>
                    </div>`).join('');
                const cl = document.getElementById('pmCostList');
                if (cl) cl.innerHTML = COSTS.map(c => `
                    <div class="pm-cost-row">
                        <div>
                            <div class="pm-cost-label">${c.label}</div>
                            <div class="pm-cost-sub">₱${c.amount.toLocaleString()}/ha</div>
                        </div>
                        <span class="pm-cost-amt">₱${(c.amount * ha).toLocaleString()}</span>
                    </div>`).join('') +
                    `<div class="pm-cost-row total">
                        <div class="pm-cost-label">Total</div>
                        <span class="pm-cost-amt">${fmt(totalCost)}</span>
                    </div>`;            
            const pi = document.getElementById('pmPriceImpact');
                if (pi) {
                    const ICON_DOWN = '<path d="M17 7 7 17M7 7v10h10"/>';
                    const ICON_DASH = '<circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>';
                    const ICON_UP = '<path d="M7 17 17 7M17 17V7H7"/>';
                    const scenarios = [
                        { label: 'Farmgate drops ₱3/kg', priceSub: `₱${fg - 3}/kg`, fg: fg - 3, icon: ICON_DOWN, col: '#c0392b', bg: '#fdecea' },
                        { label: 'Current price', priceSub: `₱${fg}/kg`, fg: fg, cur: true, icon: ICON_DASH, col: 'var(--sage-dark)', bg: 'var(--sage-pale)' },
                        { label: 'Farmgate rises ₱3/kg', priceSub: `₱${fg + 3}/kg`, fg: fg + 3, icon: ICON_UP, col: 'var(--gold)', bg: 'var(--gold-pale)' }
                    ];
                    const baselineNet = (totalKg * fg) - totalCost;
                    pi.innerHTML = scenarios.map(s => {
                        const r = +(totalKg * s.fg),
                            n = r - totalCost,
                            m = +((n / r) * 100).toFixed(1),
                            delta = n - baselineNet,
                            netStr = (n >= 0 ? '+' : '-') + '₱' + Math.abs(Math.round(n)).toLocaleString();
                        const rightExtra = s.cur
                            ? '<span class="pm-price-baseline-tag">Baseline</span>'
                            : `<span class="pm-price-delta ${delta >= 0 ? 'up' : 'down'}">${delta >= 0 ? '+' : '-'}₱${Math.abs(Math.round(delta)).toLocaleString()} vs. current</span>`;
                        return `<div class="pm-price-row${s.cur ? ' current' : ''}">
                            <div class="pm-price-icon" style="background:${s.col};--pi-color:${s.col}">
                            	<svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">${s.icon}</svg>
                        	</div>
                            <div class="pm-price-label-wrap">
                                <div class="pm-price-label">${s.label}</div>
                                <div class="pm-price-sub">${s.priceSub}</div>
                            </div>
                            <div class="pm-price-right">
                                <div class="pm-price-net">${netStr}</div>
                                <div class="pm-price-margin">${m}% margin</div>
                                ${rightExtra}
                            </div>
                        </div>`;
                    }).join('');
                }
            const adv = document.getElementById('pmAdvisory');
            if (adv) {
                const items = margin < 20 ? [{
                    icon: ICON_COST,
                    col: '#c0392b',
                    title: 'Reduce Input Costs',
                    text: `Margin is ${margin}%. Certified seeds from PhilRice reduce costs 20–30%.`
                }] : [{
                    icon: ICON_STORAGE,
                    col: 'var(--sage-dark)',
                    title: 'Good Margin — Consider Storage',
                    text: `${margin}% margin is healthy. Post-harvest storage can add ₱3–5/kg.`
                }];
                items.push({
                    icon: ICON_RULER,
                    col: 'var(--plum)',
                    title: 'Set Your Field Size',
                    text: `Estimates assume ${ha} ha. Update in Profile for accurate figures.`
                });
                    adv.innerHTML = items.map(a => `
                        <div class="pm-adv-item">
                            <span class="pm-adv-icon-badge" style="background:${a.col};--adv-color:${a.col}">
                            	<svg viewBox="0 0 24 24">${a.icon}</svg>
                        	</span>
                            <div>
                                <div class="pm-adv-title">${a.title}</div>
                                <div class="pm-adv-text">${a.text}</div>
                            </div>
                        </div>`).join('')
                }
            const wn = document.getElementById('pmWeatherNoteText');
                if (wn) {
                    const dd = document.getElementById('dashDesc'),
                        dt = document.getElementById('dashTemp');
                    const ts = dt ? dt.textContent : '30°C',
                        ds = dd ? dd.textContent : 'Partly cloudy';
                    const rainy = ds.toLowerCase().includes('rain') || ds.includes('🌧') || ds.includes('⛈');
                    wn.innerHTML = rainy ? `<strong>Rainy (${ts}).</strong> Heavy rain can reduce yield 15–30%. Ensure drainage is clear.` : `<strong>Current: ${ts}.</strong> Conditions suitable for field activities.`
                }
                        saveProfitSnapshotAndForecast(net, gross, totalCost, ha, fg).then(({ qd, labels: qdLabels, estimated }) => {
                window._latestNetProfitQd = qd;
                window._latestNetProfitLabels = qdLabels;
                rebuildCharts();
                buildPmForecast(qd, estimated);
                setTimeout(() => {
                        const c = document.getElementById('profitModalChart');
                        if (!c) return;
                        const ex = Chart.getChart(c);
                        if (ex) ex.destroy();
                        const ctx2d = c.getContext('2d');
                        const maxVal = Math.max(...qd);
                        const bestIdx = qd.indexOf(maxVal);
                        const barColors = qd.map(v => {
                                const g = ctx2d.createLinearGradient(0, 0, 0, 230);
                                if (v < 0) {
                                    g.addColorStop(0, 'rgba(192,57,43,.85)');
                                    g.addColorStop(1, 'rgba(192,57,43,.45)');
                                } else {
                                    g.addColorStop(0, '#6fae5f');
                                    g.addColorStop(1, '#3d5c38');
                                }
                                return g;
                            });
                        new Chart(c, {
                            type: 'bar',
                            data: {
                                labels: qdLabels,
                                datasets: [{
                                    label: 'Est. Net Profit',
                                    data: qd,
                                    backgroundColor: barColors,
                                    hoverBackgroundColor: qd.map(v => v < 0 ? '#c0392b' : '#2f4a2b'),
                                    borderRadius: { topLeft: 8, topRight: 8, bottomLeft: 0, bottomRight: 0 },
                                    borderSkipped: false,
                                    barPercentage: 0.85,
                                    categoryPercentage: 0.9
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                layout: {
                                    padding: { top: 12 }
                                },
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        backgroundColor: '#1e2a1a',
                                        padding: 10,
                                        titleFont: { size: 11.5, weight: '700' },
                                        bodyFont: { size: 12.5 },
                                        displayColors: false,
                                        callbacks: {
                                            label: c => ' ₱' + Math.round(c.raw).toLocaleString() + (c.dataIndex === bestIdx ? '  🏆 Best quarter' : '')
                                        }
                                    }
                                },
                                scales: {
                                    x: {
                                        grid: { display: false },
                                        border: { display: false },
                                        ticks: { color: '#5a6655', font: { size: 11, weight: '600' } }
                                    },
                                    y: {
                                        beginAtZero: false,
                                        grace: '18%',
                                        grid: { color: 'rgba(0,0,0,.05)' },
                                        border: { display: false },
                                        ticks: {
                                            color: '#94a18e',
                                            font: { size: 10.5 },
                                            callback: v => '₱' + (v / 1000).toFixed(0) + 'k'
                                        }
                                    }
                                }
                            }
                        })
                    }, 80);
            });
        }
        // past_days gives real recorded history (not a forecast); forecast_days=1
        // gives today. Every day beyond today is generated by RiceWise's own
        // forecasting model (RandomForest + LSTM ensemble), trained on this
        // real history, instead of relying on Open-Meteo's raw forward forecast.
        async function fetchOpenMeteo(lat, lng) {
            const r = await fetch(`https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lng}&current=temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m,wind_direction_10m,uv_index,precipitation_probability&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset,uv_index_max&timezone=Asia%2FManila&past_days=30&forecast_days=1`);
            if (!r.ok) throw new Error('err');
            return r.json()
        }

        function buildWeatherModal() {
            const u = getUser(),
                town = u.locationTown || 'Bustos',
                prov = u.locationProvince || 'Bulacan';
            const lat = parseFloat(u.locationLat) || 14.937936,
                lng = parseFloat(u.locationLng) || 120.927572;
            _windyLat = lat;
            _windyLng = lng;
            setEl('weatherModalTitle', town + ', ' + prov);
            const iframe = document.getElementById('weatherMapIframe');
            if (iframe) iframe.src = buildWindyUrl(_windyLayer, lat, lng);
            document.querySelectorAll('.wm-layer-btn').forEach(b => b.classList.toggle('active', b.dataset.layer === _windyLayer));
            setEl('weatherMapCoords', town + ', ' + prov + ', Philippines');
            const ml = document.getElementById('weatherMapLink');
            if (ml) {
                ml.href = `https://www.windy.com/?${lat},${lng},9`;
                ml.textContent = 'Open Windy ↗'
            }
            const bar = document.getElementById('wmLoadingBar'),
                barTxt = document.getElementById('wmLoadingText');
            if (bar) bar.style.display = 'flex';
            if (barTxt) barTxt.textContent = 'Fetching live weather…';
            ['wmTemp', 'wmHumidity', 'wmWind', 'wmRain'].forEach(id => setEl(id, '…'));
            fetchOpenMeteo(lat, lng).then(data => {
                const c = data.current,
                    d = data.daily;
                const lastIdx = d.time.length - 1;
                setEl('wmTemp', Math.round(c.temperature_2m) + '°C');
                setEl('wmHumidity', c.relative_humidity_2m + '%');
                setEl('wmWind', Math.round(c.wind_speed_10m));
                setEl('wmRain', (c.precipitation_probability ?? d.precipitation_probability_max[lastIdx] ?? '—') + '%');
                setEl('wmSunriseSunset', fmtTime(d.sunrise[lastIdx]) + ' · ' + fmtTime(d.sunset[lastIdx]));
                const uv = Math.round(c.uv_index ?? d.uv_index_max[lastIdx] ?? 0);
                setEl('wmUV', uvLabel(uv) + ' · ' + uv);
                setEl('wmWindDir', windDirLabel(c.wind_direction_10m) + ' · ' + Math.round(c.wind_direction_10m) + '°');
                setEl('dashTemp', Math.round(c.temperature_2m) + '°C');
                document.getElementById('dashDescIcon').innerHTML = wmoIconSvg(c.weather_code);
				setEl('dashDescText', wmoLabel(c.weather_code) + ' · Rain: ' + (c.precipitation_probability ?? d.precipitation_probability_max[lastIdx] ?? '—') + '%');
                if (bar) {
                    bar.style.background = '#e8f0e4'
                }
                if (barTxt) {
                    barTxt.style.color = 'var(--sage-dark)';
                    barTxt.textContent = '✓ Live data from Open-Meteo'
                }
                buildWmForecast(d, c);
                buildWmAdvisory(c, d);
                logWeatherSnapshot(c, d, lat, lng);
                checkSevereWeatherBanner(c, d);
            }).catch(() => {
                if (barTxt) barTxt.textContent = '⚠️ Could not fetch live data.';
                buildWmForecast(null);
                buildWmAdvisory(null, null);
            });
        }

        // Renders the full 15-day strip: "Today" uses real current conditions;
        // the following 14 days are generated by the forecasting model,
        // trained on the last 14 real recorded days from Open-Meteo's history
        // (past_days) — grounded in this location's actual recent weather
        // rather than a raw forward forecast.
        async function buildWmForecast(daily, current) {
            const strip = document.getElementById('wmForecastStrip');
            const chip = document.getElementById('wmRiskChip');
            const note = document.getElementById('wmRiskNote');
            if (!strip) return;
            if (!daily || !daily.time || daily.time.length < 6) {
                strip.innerHTML = '<div style="padding:20px;color:var(--text-muted);font-size:12px">Loading forecast…</div>';
                if (chip) chip.style.display = 'none';
                if (note) note.textContent = '';
                return;
            }
            if (note) note.textContent = 'Building the 15-day forecast from recent local readings…';

            // Prefer our own saved weather_history over Open-Meteo's past_days,
            // so the model trains on what RiceWise actually logged for this farm.
            const savedHistory = await fetchSavedWeatherHistory();
            let tMaxArr = daily.temperature_2m_max;
            let tMinArr = daily.temperature_2m_min;
            let rainArr = daily.precipitation_probability_max;
            if (savedHistory && savedHistory.length >= 20) {
                tMaxArr = savedHistory.map(h => parseFloat(h.temp_max));
                tMinArr = savedHistory.map(h => parseFloat(h.temp_min));
                rainArr = savedHistory.map(h => parseFloat(h.precipitation_probability));
            }

            setTimeout(() => {
                try {
                    const lastIdx = daily.time.length - 1; // today
                    const opts = {
                        window: 6,
                        stepsAhead: 14,
                        hiddenSize: 4,
                        epochs: 50
                    };
                    const hiFc = ForecastAI.ensembleForecast(tMaxArr, opts);
                    const loFc = ForecastAI.ensembleForecast(tMinArr, opts);
                    const rainFc = ForecastAI.ensembleForecast(rainArr, opts);
                    const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                    const todayDate = new Date(daily.time[lastIdx] + 'T00:00:00');
                    let worstRisk = 'low',
                        worstLabel = null;

                    const todayRain = current?.precipitation_probability ?? daily.precipitation_probability_max[lastIdx] ?? 0;
                    const todayCode = current?.weather_code ?? daily.weather_code[lastIdx];
                    const todayRisk = wmRiskLevel(todayCode, todayRain);
                    if (todayRisk !== 'low') {
                        worstRisk = todayRisk;
                        worstLabel = 'Today';
                    }
                    const cards = [`<div class="wm-fc-card today"><div class="wm-fc-dname" style="color:white;">Today</div><div class="wm-fc-ddate" style="color:white;">${todayDate.toLocaleDateString('en-US',{month:'short',day:'numeric'})}</div><span class="wm-fc-icon">${wmoIcon(todayCode)}</span><div class="wm-fc-hi" style="color:white;">${Math.round(daily.temperature_2m_max[lastIdx])}°</div><div class="wm-fc-lo" style="color:white;">${Math.round(daily.temperature_2m_min[lastIdx])}°</div><div class="wm-fc-rain" style="background:rgba(255,255,255,.2);color:white;">💧${todayRain}%</div></div>`];

                                        const forecastDaysToSave = [];
                    for (let k = 0; k < 14; k++) {
                        const dte = new Date(todayDate);
                        dte.setDate(dte.getDate() + k + 1);
                        const dn = k === 0 ? 'Tmrw' : DAYS[dte.getDay()];
                        const dd2 = dte.toLocaleDateString('en-US', {
                            month: 'short',
                            day: 'numeric'
                        });
                        const rain = Math.max(0, Math.min(100, Math.round(rainFc.blended[k])));
                        const hi = Math.round(hiFc.blended[k]),
                            lo = Math.round(loFc.blended[k]);
                        const risk = wmRiskLevel(null, rain);
                        if (risk !== 'low' && (worstRisk === 'low' || risk === 'high')) {
                            worstRisk = risk;
                            worstLabel = dn;
                        }
                        const style = WM_RISK_STYLE[risk];
                        const icon = rain >= 70 ? '⛈️' : rain >= 50 ? '🌧️' : rain >= 20 ? '🌦️' : '🌤️';
                        const ring = risk !== 'low' ? `box-shadow:inset 0 0 0 1.5px ${style.text};` : '';
                        cards.push(`<div class="wm-fc-card" style="position:relative;${ring}"><div class="wm-fc-dname">${dn}</div><div class="wm-fc-ddate">${dd2}</div><span class="wm-fc-icon">${icon}</span><div class="wm-fc-hi">${hi}°</div><div class="wm-fc-lo">${lo}°</div><div class="wm-fc-rain" style="background:${style.bg};color:${style.text}">💧${rain}%</div></div>`);
                        forecastDaysToSave.push({
                            forecast_date: localISO(dte),
                            temp_max: hi,
                            temp_min: lo,
                            rain_chance: rain
                        });
                    }
                    strip.innerHTML = cards.join('');
                    saveWeatherForecast(forecastDaysToSave);
                    if (chip) {
                    	chip.style.display = 'inline-flex';
                    	chip.style.background = WM_RISK_STYLE[worstRisk].bg;
                    	chip.style.color = WM_RISK_STYLE[worstRisk].text;
                    	const labelText = worstRisk === 'low' ? 'Low risk' : `${WM_RISK_STYLE[worstRisk].label} (${worstLabel})`;
                    	chip.innerHTML = `<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">${WM_RISK_STYLE[worstRisk].icon}</svg>${labelText}`;
                	}
                    if (note) note.textContent = 'Today shows live conditions; the remaining 14 days are generated by RiceWise\'s forecasting model, trained on the last 30 days of real local weather — a trend signal, not an exact forecast.';
                } catch (e) {
                    strip.innerHTML = '<div style="padding:20px;color:var(--text-muted);font-size:12px">Forecast unavailable — showing today only.</div>';
                    if (note) note.textContent = '';
                }
            }, 20);
        }

        function buildWmAdvisory(cur, daily) {
            const el = document.getElementById('wmFarmAdvisory');
            if (!el) return;
            const ICON_DROPLET = '<path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/>';
            const ICON_SPROUT = '<path d="M12 22V12"/><path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8"/><path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8"/>';
            const ICON_PEST = '<path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="M8.5 8.5 16 16"/>';
            const ICON_SUN = '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>';
            let advs = [];
            if (cur && daily) {
                const lastIdx = daily.time.length - 1;
                const rain = cur.precipitation_probability ?? daily.precipitation_probability_max[lastIdx] ?? 0;
                const hum = cur.relative_humidity_2m ?? 75,
                    uv = Math.round(cur.uv_index ?? daily.uv_index_max[lastIdx] ?? 3);
                const temp = Math.round(cur.temperature_2m ?? 30);
                const favorable = hum >= 60 && hum <= 85 && temp >= 25 && temp <= 35 && rain < 70;
                advs = [{
                        icon: ICON_DROPLET,
                        col: '#4a90d9',
                        title: 'Irrigation Advisory',
                        text: rain >= 60 ? `${rain}% chance of rain today — consider holding off on irrigation.` : `Only ${rain}% chance of rain today. Irrigation is likely still needed.`
                    },
                    {
                        icon: ICON_SPROUT,
                        col: '#5a7a52',
                        title: 'Planting Conditions',
                        text: favorable ? `Current readings favor planting — ${temp}°C, ${hum}% humidity, ${rain}% rain chance. Forecasts can shift, so it's worth rechecking closer to your planting date.` : `Current readings are less favorable for planting — ${temp}°C, ${hum}% humidity, ${rain}% rain chance. Worth monitoring and rechecking before committing to a date.`
                    },
                    {
                        icon: ICON_PEST,
                        col: '#c0392b',
                        title: 'Pest & Disease Risk',
                        text: hum > 80 ? `Humidity is high (${hum}%), which raises fungal risk — worth inspecting paddies more closely this week.` : `Humidity is moderate (${hum}%) — typical risk level, but still worth regular monitoring.`
                    },
                    {
                        icon: ICON_SUN,
                        col: '#c8963e',
                        title: 'UV & Heat',
                        text: uv >= 6 ? `UV is ${uvLabel(uv).toLowerCase()} (index ${uv}) — consider limiting field exposure between 10am–3pm.` : `UV is ${uvLabel(uv).toLowerCase()} (index ${uv}) — generally fine for field activities.`
                    }
                ];
            } else {
                advs = [{
                        icon: ICON_DROPLET,
                        title: 'Irrigation Advisory',
                        text: 'Forecast data is unavailable right now — check back before irrigating.'
                    },
                    {
                        icon: ICON_SPROUT,
                        title: 'Planting Conditions',
                        text: 'Forecast data is unavailable right now — recheck humidity and temperature before transplanting.'
                    },
                    {
                        icon: ICON_PEST,
                        title: 'Pest & Disease Risk',
                        text: 'Keep monitoring paddies for early signs of disease regardless of forecast availability.'
                    }
                ];
            }
            el.innerHTML = advs.map(a => `<div class="wm-adv-item"><span class="wm-adv-icon-badge" style="--adv-color:${a.col}"><svg viewBox="0 0 24 24">${a.icon}</svg></span><div><div class="wm-adv-title">${a.title}</div><div class="wm-adv-text">${a.text}</div></div></div>`).join('');
        }

        function checkSevereWeatherBanner(cur, daily) {
            const banner = document.getElementById('severeWeatherBanner');
            if (!banner || !cur) return;
            const lastIdx = daily ? daily.time.length - 1 : 0;
            const rain = cur.precipitation_probability ?? daily?.precipitation_probability_max?.[lastIdx] ?? 0;
            const code = cur.weather_code ?? 0;
            const isStorm = code >= 95;
            const isHeavyRain = rain >= 70 || (code >= 65 && code <= 82);
            if (isStorm || isHeavyRain) {
                banner.style.display = 'flex';
                const icon = document.getElementById('severeWeatherIcon');
                if (icon) {
                    icon.innerHTML = isStorm
                        ? '<path d="M6 16.326A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="m13 12-3 5h4l-3 5"/>'
                        : '<path d="M16 13v8"/><path d="M8 13v8"/><path d="M12 15v8"/><path d="M20 16.58A5 5 0 0 0 18 7h-1.26A8 8 0 1 0 4 15.25"/>';
                }
                banner.querySelector('#severeWeatherText').textContent = isStorm
                    ? 'Thunderstorms likely today — field activities may need to be postponed.'
                    : `${rain}% chance of rain today — plan irrigation and field work accordingly.`;
            } else {
                banner.style.display = 'none';
            }
        }
        let _riceView = 'all',
            _riceChartData = null;

        function setRiceChartView(v) {
            _riceView = v;
            document.querySelectorAll('.rm-chart-tab').forEach(b => b.classList.toggle('active', b.dataset.view === v));
            if (_riceChartData) renderRiceChart(_riceChartData)
        }
        async function buildRiceModal() {
            if (!PSA_RETAIL_HISTORY.length) { await loadPriceHistory(); }
            const bar = document.getElementById('riceLiveBar'),
                barTxt = document.getElementById('riceBarText'),
                barDot = document.getElementById('riceBarDot');
            if (barDot) {
                barDot.style.background = 'var(--gold)';
                barDot.style.animation = 'wmPulse 1.1s infinite'
            }
            if (barTxt) {
                barTxt.style.color = 'var(--gold)';
                barTxt.textContent = 'Checking DA website…'
            }
            ['riceCurrentVal', 'riceMsrpVal', 'riceWmVal', 'riceChangeVal'].forEach(id => setEl(id, '…'));
            renderRiceChart(null);
            renderMsrpTimeline();
            renderVarietyGrid(null);
            renderRiceAdvisory(null);
            buildRiceForecast();
            let liveData = null;
            try {
                const r = await fetch('https://api.allorigins.win/get?url=' + encodeURIComponent('https://www.da.gov.ph/price-monitoring/'), {
                    signal: AbortSignal.timeout(7000)
                });
                if (r.ok) {
                    const j = await r.json();
                    const ms = [...((j.contents || '').matchAll(/(?:MSRP|maximum suggested retail price)[^₱P0-9]*(?:₱|PHP?\s*)(\d{2})/gi))];
                    if (ms.length) {
                        const px = ms.map(m => parseInt(m[1])).filter(p => p >= 30 && p <= 100);
                        if (px.length) liveData = {
                            msrp: px[0],
                            source: 'Live · da.gov.ph'
                        }
                    }
                }
            } catch (e) {}
            const sm = getCurrentMsrp(),
                lp = PSA_RETAIL_HISTORY[PSA_RETAIL_HISTORY.length - 1],
                yp = PSA_RETAIL_HISTORY[0],
                s6 = PSA_RETAIL_HISTORY[Math.max(0, PSA_RETAIL_HISTORY.length - 7)];
            let mv = sm ? sm.msrp : 49,
                ml = sm ? sm.label : '—',
                isLive = false;
            if (liveData && liveData.msrp >= 30 && liveData.msrp <= 100) {
                mv = liveData.msrp;
                ml = 'Latest';
                isLive = true
            }
            const reg = lp.regular,
                wm = lp.wellMilled,
                yoy = +(reg - yp.regular).toFixed(2),
                s6c = +(reg - s6.regular).toFixed(2);
            setEl('riceCurrentVal', `₱${reg.toFixed(2)}/kg`);
            setEl('riceMsrpVal', mv ? `₱${mv}/kg` : 'N/A');
            setEl('riceWmVal', `₱${wm.toFixed(2)}/kg`);
            const ce = document.getElementById('riceChangeVal');
            if (ce) {
                ce.textContent = `${yoy >= 0 ? '+' : ''}₱${Math.abs(yoy).toFixed(2)}`;
                ce.style.color = yoy <= 0 ? 'var(--sage-dark)' : '#c0392b'
            }
            setEl('rmQMsrp', mv ? `₱${mv}/kg` : 'Suspended');
            setEl('rmQDate', ml);
            const s6e = document.getElementById('rmQ6mo');
            
            const ICON_CHECK_SM = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:3px"><polyline points="20 6 9 17 4 12"/></svg>';
			const ICON_CLIPBOARD_SM = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:3px"><rect x="6" y="4" width="12" height="16" rx="2"/><line x1="9" y1="9" x2="15" y2="9"/><line x1="9" y1="13" x2="15" y2="13"/></svg>';
            if (s6e) {
                s6e.textContent = `${s6c >= 0 ? '+' : ''}₱${Math.abs(s6c).toFixed(2)}/kg`;
                s6e.style.color = s6c <= 0 ? 'var(--sage-dark)' : '#c0392b'
            }
            let ai, at, ad, abg, ab;
                if (!mv) {
                    ai = 'info';
                    abg = 'var(--sky-pale)';
                    ab = 'var(--sky)';
                    at = 'MSRP Currently Suspended';
                    ad = 'Monitor da.gov.ph for announcements.'
                } else {
                    const diff = +(reg - mv).toFixed(2);
                    if (Math.abs(diff) <= 3) {
                        ai = 'good';
                        abg = 'var(--sage-pale)';
                        ab = 'var(--sage)';
                        at = 'Price Near DA MSRP';
                        ad = `Retail ₱${reg.toFixed(2)}/kg is within ₱3 of MSRP ₱${mv}/kg.`
                    } else if (diff > 3) {
                        ai = 'warn';
                        abg = '#fff3cd';
                        ab = '#e67e22';
                        at = `₱${diff.toFixed(2)}/kg above MSRP`;
                        ad = 'Report to DA Bantay Presyo: (02) 8920-4261.'
                    } else {
                        ai = 'down';
                        abg = 'var(--sky-pale)';
                        ab = 'var(--sky)';
                        at = 'Price Below MSRP';
                        ad = 'Favorable buying conditions.'
                    }
                }
                const ae = document.getElementById('riceStatusAlert');
                if (ae) ae.style.background = abg;
                const aIconEl = document.getElementById('riceAlertIcon');
                if (aIconEl) {
                    aIconEl.innerHTML = riceAlertIconSvg(ai);
                    aIconEl.style.background = 'none';   // CHANGED — no more filled box behind the icon
                    aIconEl.style.color = ab;            // CHANGED — icon color now comes from currentColor
                }
                setEl('riceAlertTitle', at);
                setEl('riceAlertDesc', ad);
            if (isLive) {
                if (bar) bar.style.background = '#e8f0e4';
                if (barDot) {
                    barDot.style.background = 'var(--sage)';
                    barDot.style.animation = 'none'
                }
                if (barTxt) {
                    barTxt.style.color = 'var(--sage-dark)';
                    barTxt.innerHTML = `${ICON_CHECK_SM}Live · MSRP ₱${mv}/kg`
                }
            } else {
                if (barDot) barDot.style.animation = 'none';
				if (barTxt) barTxt.innerHTML = mv ? `${ICON_CLIPBOARD_SM}Verified DA records · MSRP ₱${mv}/kg · ${ml}` : `${ICON_CLIPBOARD_SM}MSRP suspended`
            }
            _riceChartData = {
                msrpVal: mv
            };
            renderRiceChart({
                msrpVal: mv
            });
            renderMsrpTimeline();
            renderVarietyGrid({
                regular: reg,
                wellMilled: wm,
                special: lp.special
            });
            renderRiceAdvisory({
                retailRegular: reg,
                msrpVal: mv,
                yoyChange: yoy,
                sixMoChange: s6c
            });
            const mn = new Date().getMonth(),
                tip = mn <= 2 ? '<strong style="color:var(--sage-dark)">Lean Season:</strong> Jan–Mar farmgate prices are highest. Hold stocks if storage allows.' : mn >= 9 ? '<strong style="color:var(--sage-dark)">Harvest Season:</strong> Oct–Dec prices dip. Sell early or use post-harvest storage.' : '<strong style="color:var(--sage-dark)">Market Note:</strong> Monitor DA MSRP adjustments.';
            const tip2 = document.getElementById('riceSellingTipText');
            if (tip2) tip2.innerHTML = tip;
            setEl('riceSourceLine', `Sources: DA Official Portal · PSA · Updated: ${new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`);
            const pc = document.getElementById('pbCurrent'),
                pm = document.getElementById('pbMsrp');
            if (pc) pc.innerHTML = `<span class="php-sign-sm">₱</span>${Math.round(reg)}/kg`;
			if (pm && mv) pm.innerHTML = `<span class="php-sign-sm">₱</span>${mv}/kg`;
        }

        function renderRiceChart(live) {
            setTimeout(() => {
                const c = document.getElementById('riceModalChart');
                if (!c) return;
                const ex = Chart.getChart(c);
                if (ex) ex.destroy();
                const labels = PSA_RETAIL_HISTORY.map(r => r.label);
                const reg = PSA_RETAIL_HISTORY.map(r => r.regular),
                    wm = PSA_RETAIL_HISTORY.map(r => r.wellMilled),
                    sp = PSA_RETAIL_HISTORY.map(r => r.special);
                const msrpLine = PSA_RETAIL_HISTORY.map(r => {
                    const d = new Date(r.label.replace(' ', ' 1, '));
                    let m = null;
                    for (const e of DA_MSRP_HISTORY)
                        if (!e.suspended && new Date(e.date) <= d) m = e.msrp;
                    return m
                });
                if (live && live.msrpVal) msrpLine[msrpLine.length - 1] = live.msrpVal;
                const v = _riceView || 'all';
                let ds = [];
                if (v === 'all') ds = [{
                    label: 'Regular Milled',
                    data: reg,
                    borderColor: '#c8963e',
                    backgroundColor: 'rgba(200,150,62,.14)',
                    fill: true,
                    borderWidth: 2.5,
                    pointRadius: 3.5,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#c8963e',
                    pointBorderWidth: 2,
                    pointHoverRadius: 7,
                    pointHoverBackgroundColor: '#c8963e',
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 2,
                    hoverBorderWidth: 3.5,
                    tension: .35
                }, {
                    label: 'Well-Milled',
                    data: wm,
                    borderColor: '#2f6f6a',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    pointRadius: 2.5,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#2f6f6a',
                    pointBorderWidth: 2,
                    pointHoverRadius: 6,
                    pointHoverBackgroundColor: '#2f6f6a',
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 2,
                    hoverBorderWidth: 3,
                    tension: .35
                }, {
                    label: 'DA MSRP',
                    data: msrpLine,
                    borderColor: '#8a5a8f',
                    backgroundColor: 'transparent',
                    borderWidth: 2.5,
                    borderDash: [7, 4],
                    pointRadius: 0,
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: '#8a5a8f',
                    pointHoverBorderColor: '#fff',
                    hoverBorderWidth: 3.5,
                    tension: 0
                }];
                else if (v === 'regular') ds = [{
                    label: 'Regular Milled',
                    data: reg,
                    borderColor: '#c8963e',
                    backgroundColor: 'rgba(200,150,62,.18)',
                    fill: true,
                    borderWidth: 2.5,
                    pointRadius: 4,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#c8963e',
                    pointBorderWidth: 2,
                    pointHoverRadius: 7,
                    pointHoverBackgroundColor: '#c8963e',
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 2,
                    hoverBorderWidth: 3.5,
                    tension: .35
                }];
                else ds = [{
                    label: 'Regular Milled',
                    data: reg,
                    borderColor: '#c8963e',
                    backgroundColor: 'rgba(200,150,62,.14)',
                    fill: true,
                    borderWidth: 2.5,
                    pointRadius: 3.5,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#c8963e',
                    pointBorderWidth: 2,
                    pointHoverRadius: 7,
                    pointHoverBackgroundColor: '#c8963e',
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 2,
                    hoverBorderWidth: 3.5,
                    tension: .35
                }, {
                    label: 'DA MSRP',
                    data: msrpLine,
                    borderColor: '#8a5a8f',
                    backgroundColor: 'transparent',
                    borderWidth: 2.5,
                    borderDash: [7, 4],
                    pointRadius: 0,
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: '#8a5a8f',
                    pointHoverBorderColor: '#fff',
                    hoverBorderWidth: 3.5,
                    tension: 0
                }];
                new Chart(c, {
                    type: 'line',
                    data: {
                        labels,
                        datasets: ds
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    font: {
                                        size: 10.5
                                    },
                                    padding: 12,
                                    boxWidth: 22
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: c => `${c.dataset.label}: ₱${c.raw}/kg`
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    color: '#94a18e',
                                    font: {
                                        size: 9.5
                                    },
                                    maxRotation: 40
                                }
                            },
                            y: {
                                beginAtZero: false,
                                grid: {
                                    color: 'rgba(0,0,0,.04)'
                                },
                                border: {
                                    display: false
                                },
                                ticks: {
                                    color: '#94a18e',
                                    callback: v => '₱' + v
                                }
                            }
                        }
                    }
                })
            }, 60)
        }

        function renderMsrpTimeline() {
            const el = document.getElementById('riceMsrpTimeline');
            if (!el) return;
            el.innerHTML = [...DA_MSRP_HISTORY].reverse().slice(0, 8).map((e, i) => {
                const isL = i === 0 && !e.suspended,
                    isSu = !!e.suspended,
                    isSc = !!e.scheduled;
                let rc = 'rm-msrp-row';
                if (isL) rc += ' current';
                else if (isSu) rc += ' suspended';
                else if (isSc) rc += ' scheduled';
                const badge = isL ? '<span style="background:var(--sage);color:white;font-size:9.5px;font-weight:700;padding:2px 7px;border-radius:10px;margin-left:7px">Current</span>' : '';
                const price = isSu ? '<span style="color:var(--text-muted);font-size:13px;font-style:italic">Suspended</span>' : `<span style="font-family:'DM Mono',monospace;font-size:17px;font-weight:700;color:${isL ? 'var(--sage-dark)' : 'var(--text-primary)'}">₱${e.msrp}/kg</span>`;
                return `<div class="${rc}"><div><div style="font-size:13px;font-weight:600">${e.label}${badge}</div><div style="font-size:11.5px;color:var(--text-muted);margin-top:3px">${e.source}</div></div>${price}</div>`
            }).join('')
        }

        function renderVarietyGrid(live) {
            const el = document.getElementById('riceVarietyGrid');
            if (!el) return;
            const r = live ? live.regular : 45, w = live ? live.wellMilled : 47, s = live ? live.special : 59, mx = Math.max(r, w, s);
            const ICON_GRAIN = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2c3 3 5 7 5 11a5 5 0 0 1-10 0c0-4 2-8 5-11z"/></svg>';
            el.innerHTML = [
                {name:'Regular Milled', val:r, col:'var(--gold)'},
                {name:'Well-Milled', val:w, col:'#4a90d9'},
                {name:'Special (local)', val:s, col:'#9b59b6'},
                {name:'Imported (5% brk)', val:w-1, col:'var(--sage-dark)'}
            ].map(v => `<div class="rm-variety-item">
                <div style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px">
                    <span style="color:${v.col}">${ICON_GRAIN}</span>${v.name}
                </div>
                <div style="font-family:'DM Mono',monospace;font-size:22px;font-weight:700;color:${v.col};line-height:1">₱${v.val.toFixed ? v.val.toFixed(2) : v.val}<span style="font-size:12px;font-family:'DM Sans',sans-serif;color:var(--text-muted);font-weight:400">/kg</span></div>
                <div class="rm-variety-bar"><div class="rm-variety-bar-fill" style="width:${Math.round((v.val/mx)*100)}%;background:${v.col}"></div></div>
            </div>`).join('')
        }

        function renderRiceAdvisory(data) {
            const el = document.getElementById('riceAdvisory');
            if (!el) return;
            let advs = [];
            if (data && data.retailRegular !== undefined) {
                const {
                    retailRegular: rr,
                    msrpVal: mv,
                    yoyChange: yoy
                } = data, mn = new Date().getMonth(), diff = mv ? +(rr - mv).toFixed(2) : null;
                if (diff !== null && diff > 3) advs.push({
                    icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
                    col: '#e67e22',
                    bg: '#fff3cd',
                    title: 'Price Exceeds MSRP',
                    text: `Retail ₱${rr.toFixed(2)}/kg is ₱${diff.toFixed(2)} above MSRP. Report to DA Bantay Presyo: (02) 8920-4261.`
                });
                else advs.push({
                    icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
                    col: 'var(--sage)',
                    bg: 'var(--sage-pale)',
                    title: 'Price Aligned with MSRP',
                    text: 'Market pricing is aligned with DA guidelines.'
                });
                if (mn <= 2) advs.push({
                    icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 8-8v10H3z"/></svg>',
                    col: 'var(--gold)',
                    bg: 'var(--gold-pale)',
                    title: 'Lean Season (Jan–Mar)',
                    text: 'Supply is tighter. Farmgate prices typically hold or rise.'
                });
                else if (mn >= 9) advs.push({
                    icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 8-8v10H3z"/></svg>',
                    col: '#c8963e',
                    bg: '#fdf6ec',
                    title: 'Harvest Season (Oct–Dec)',
                    text: 'Peak harvest — prices dip. Consider storage to maximize returns.'
                });
                if (Math.abs(yoy) >= 2) advs.push({
                    icon: yoy > 0
                        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/></svg>'
                        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>',
                    col: yoy > 0 ? '#c0392b' : 'var(--sage-dark)',
                    bg: yoy > 0 ? '#fdecea' : 'var(--sage-pale)',
                    title: `Prices ${yoy > 0 ? 'Up' : 'Down'} Year-on-Year`,
                    text: `Regular milled is ₱${Math.abs(yoy).toFixed(2)}/kg ${yoy > 0 ? 'higher' : 'lower'} vs. a year ago.`
                });
            } else {
                    advs = [{
                        icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="21" x2="21" y2="21"/><line x1="5" y1="21" x2="5" y2="10"/><line x1="19" y1="21" x2="19" y2="10"/><polygon points="12 2 21 8 3 8"/></svg>',
                        col: 'var(--gold)',
                        bg: 'var(--gold-pale)',
                        title: 'DA MSRP Ceiling',
                        text: 'MSRP is set by DA to protect consumers. Overpricing can be reported to DA Bantay Presyo.'
                    }, {
                        icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 8-8v10H3z"/></svg>',
                        col: '#5a7a52',
                        bg: '#e8f0e4',
                        title: 'Seasonal Patterns',
                        text: 'Lean season (Jan–Mar) = higher prices, harvest season = lower prices.'
                    }]
                }
            el.innerHTML = advs.map(a => `<div class="wm-adv-item" style="background:${a.bg};border-color:${a.col}"><span class="wm-adv-icon-badge" style="--adv-color:${a.col}">${a.icon}</span><div><div class="wm-adv-title">${a.title}</div><div class="wm-adv-text">${a.text}</div></div></div>`).join('')
        }
            
        const actIconsJS = {
            'Planting': ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--sage)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22V12"/><path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8"/><path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8"/></svg>', 'var(--sage-pale)'],
            'Fertilizing': ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--sage-dark)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3h6l1 4H8z"/><path d="M8 7h8l1 13a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1z"/></svg>', '#e8f0e4'],
            'Irrigation': ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--sky)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>', 'var(--sky-pale)'],
            'Pest Control': ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="M8.5 8.5 16 16"/></svg>', 'var(--gold-pale)'],
            'Harvesting': ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 8-8v10H3z"/></svg>', 'var(--gold-pale)'],
            'Sales': ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--sage)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 9.5c0-1.5 1.3-2.5 3-2.5s3 1 3 2.5S13.5 12 12 12s-3 1-3 2.5 1.3 2.5 3 2.5 3-1 3-2.5"/></svg>', 'var(--sage-pale)'],
            '__default': ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>', 'var(--cream-2)']
        };

        function buildRecentModal() {
            const b = document.getElementById('recentModalBody');
            if (!PHP_RECORDS.length) {
                b.innerHTML = '<p style="color:var(--text-muted);font-size:13px;text-align:center;padding:16px 0">No activities yet. Add some in Records!</p>';
                return;
            }
            b.innerHTML = PHP_RECORDS.map(r => {
                const ic = actIconsJS[r.activity] || actIconsJS.__default;
                const d = new Date(r.record_date);
                const dateStr = d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
                return `<div class="act-row">
                    <div class="act-icon-badge" style="background:${ic[1]}">${ic[0]}</div>
                    <div class="act-row-main">
                        <div class="act-row-title">${r.activity}</div>
                        <div class="act-row-sub">${r.location}</div>
                    </div>
                    <div class="act-row-date">${dateStr}</div>
                </div>`;
            }).join('');
        }

        const remIconsJS = {
            'Harvest': ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 8-8v10H3z"/></svg>', 'var(--gold-pale)'],
            'Spray Insecticide': ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--sky)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>', 'var(--sky-pale)'],
            'Spray Fungicide': ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--sage)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="M8.5 8.5 16 16"/></svg>', 'var(--sage-pale)'],
            '__default': ['<svg viewBox="0 0 24 24" fill="none" stroke="var(--plum)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v5l3 3"/><circle cx="12" cy="12" r="10"/></svg>', 'var(--plum-pale)']
        };

        function remIconForJS(title) {
            for (const key in remIconsJS) {
                if (key !== '__default' && title.toLowerCase().includes(key.toLowerCase())) return remIconsJS[key];
            }
            return remIconsJS.__default;
        }

        function buildRemindersModal() {
            const b = document.getElementById('remindersModalBody');
            if (!PHP_REMINDERS.length) {
                b.innerHTML = '<p style="color:var(--text-muted);font-size:13.5px;text-align:center;padding:20px 0">No upcoming reminders. Add some in the Calendar!</p>';
                return;
            }
            b.innerHTML = PHP_REMINDERS.map(e => {
                const d = new Date(e.event_date), now = new Date();
                const days = Math.ceil((d - now) / 86400000);
                const tag = days === 0 ? 'Today' : days === 1 ? 'Tomorrow' : days + 'd';
                const cleanTitle = e.title.replace(/^[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]+\s*/u, '').trim();
                const ic = remIconForJS(cleanTitle);
                const dateStr = d.toLocaleDateString('en-US', { month: 'long', day: 'numeric' });
                return `<div class="act-row">
                    <div class="act-icon-badge" style="background:${ic[1]}">${ic[0]}</div>
                    <div class="act-row-main">
                        <div class="act-row-title">${cleanTitle}</div>
                        <div class="act-row-sub">${dateStr}</div>
                    </div>
                    <span class="reminder-badge">${tag}</span>
                </div>`;
            }).join('');
        }
            
        const FV_STAGES = [{
                key: 'bare',
                label: 'Bare soil',
                color: '#c9bfa8',
                icon: ''
            },
            {
                key: 'seedling',
                label: 'Seedling',
                color: '#6fae5f',
                icon: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="#1f3a1c" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20V10"/><path d="M12 10c-2-4-7-4-7-4 0 5 4 6 7 6"/><path d="M12 10c2-4 7-4 7-4 0 5-4 6-7 6"/></svg>'
            },
            {
                key: 'growing',
                label: 'Growing',
                color: '#3d8a3a',
                icon: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="#0f2a0d" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21V9"/><path d="M12 9c-3-5-9-5-9-5 0 6 5 8 9 8"/><path d="M12 9c3-5 9-5 9-5 0 6-5 8-9 8"/></svg>'
            },
            {
                key: 'mature',
                label: 'Mature',
                color: '#e0a53c',
                icon: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="#5a3d10" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22V13"/><path d="M9 13c0-3 1-5 3-7 2 2 3 4 3 7"/><path d="M9 9c0-2 1-3 3-5 2 2 3 3 3 5"/></svg>'
            },
            {
                key: 'harvested',
                label: 'Harvested',
                color: '#a5673f',
                icon: ''
            },
            {
                key: 'flooded',
                label: 'Flooded',
                color: '#3d8fd6',
                icon: ''
            },
        ];
        const FV_EXTRA = [{
                key: 'water',
                label: 'Water',
                color: '#9cc9ec'
            },
            {
                key: 'path',
                label: 'Path',
                color: '#d8c9a8'
            },
        ];
        const FV_ALL_TYPES = [...FV_STAGES, ...FV_EXTRA];
        const FV_ACTIVITY_TO_TYPE = {
            'Planting': 'seedling',
            'Fertilizing': 'growing',
            'Irrigation': 'flooded',
            'Pest Control': 'growing',
            'Harvesting': 'harvested',
            'Sales': 'harvested',
        };
        let fvCols = PHP_FIELD_LAYOUT.columns;
        let fvRows = PHP_FIELD_LAYOUT.rows;
        let fvCellSize = PHP_FIELD_LAYOUT.cell_size;
        let fvGridState = PHP_FIELD_LAYOUT.grid.slice();
        let fvSelectedStage = 'seedling';
        let fvPaintMode = 'stage';
        let fvIsPainting = false;
        let fvSaveTimer = null;
        let fvAssignTargetIdx = null;

        function fvTypeInfo(key) {
            return FV_ALL_TYPES.find(t => t.key === key) || FV_ALL_TYPES[0];
        }

        function fvEscapeHtml(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c]));
        }

        function fvEnsureGridSize() {
            const needed = fvCols * fvRows;
            if (fvGridState.length < needed) {
                fvGridState = fvGridState.concat(Array(needed - fvGridState.length).fill(null).map(() => ({
                    type: 'bare',
                    field: null
                })));
            } else if (fvGridState.length > needed) {
                fvGridState = fvGridState.slice(0, needed);
            }
        }

        function fvRenderControls() {
            document.getElementById('fvCols').value = fvCols;
            document.getElementById('fvRows').value = fvRows;
            document.getElementById('fvCellSize').value = fvCellSize;
            document.getElementById('fvCellSizeLabel').textContent = fvCellSize;
            document.getElementById('fvStageGrid').innerHTML = FV_STAGES.map(s => `
                <button class="fv-stage-btn${fvSelectedStage === s.key ? ' active' : ''}" style="--fv-color:${s.color}" data-stage="${s.key}">
                    <span class="fv-dot" style="background:${s.color}"></span>${s.label}
                </button>`).join('');
            document.querySelectorAll('.fv-stage-btn').forEach(btn => btn.addEventListener('click', () => {
                fvSelectedStage = btn.dataset.stage;
                fvPaintMode = 'stage';
                fvRenderControls();
            }));
            const modes = [
                    { key: 'stage', label: 'Stage', icon: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v.01M16 9v.01M8 9v.01M9 15v.01M15 15v.01"/></svg>' },
                    { key: 'water', label: 'Water', icon: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>' },
                    { key: 'path', label: 'Path', icon: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h14M5 4v6M19 4v6M9 20l3-6 3 6M9 20H7M15 20h2"/></svg>' },
                    { key: 'field', label: 'Field', icon: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.07 0l2.83-2.83a5 5 0 0 0-7.07-7.07L11.5 4.5"/><path d="M14 11a5 5 0 0 0-7.07 0L4.1 13.83a5 5 0 0 0 7.07 7.07L12.5 19.5"/></svg>' },
                    { key: 'erase', label: 'Erase', icon: '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' }
                ];
                document.getElementById('fvModeGrid').innerHTML = modes.map(m => `<button class="fv-mode-btn${fvPaintMode === m.key ? ' active' : ''}" data-mode="${m.key}">${m.icon}<span>${m.label}</span></button>`).join('');
                document.querySelectorAll('.fv-mode-btn').forEach(btn => btn.addEventListener('click', () => {
                    fvPaintMode = btn.dataset.mode;
                    fvRenderControls();
                }));
            document.getElementById('fvLegend').innerHTML = FV_ALL_TYPES.map(t => `<div class="fv-legend-item"><span class="fv-dot" style="background:${t.color}"></span>${t.label}</div>`).join('') +
                `<div class="fv-legend-item"><span style="font-size:11px">🔗</span> Linked to a real field</div>`;
        }

        function fvRenderSummary() {
            const total = fvGridState.length;
            const count = key => fvGridState.filter(v => v.type === key).length;
            const linked = fvGridState.filter(v => v.field).length;
            const stats = [
                { label: 'Total', val: total, color: 'var(--text-muted)' },
                { label: 'Mature', val: count('mature'), color: '#e0a53c' },
                { label: 'Seedling', val: count('seedling'), color: '#6fae5f' },
                { label: 'Flooded', val: count('flooded'), color: '#3d8fd6' },
                { label: 'Linked', val: linked, color: 'var(--plum)' },
            ];
            document.getElementById('fvChips').innerHTML = stats.map(s => `
                    <span class="fv-chip">
                        <span class="fv-chip-dot" style="background:${s.color}"></span>
                        ${s.label} <span class="fv-chip-val" style="color:${s.color}">${s.val}</span>
                    </span>
                `).join('');
        }

        function fvColLabel(c) {
            return 'C' + (c + 1);
        }

        function fvRowLabel(r) {
            return 'R' + (r + 1);
        }

        function fvRenderGrid() {
            const grid = document.getElementById('fvGrid');
            grid.style.gridTemplateColumns = `repeat(${fvCols}, ${fvCellSize}px)`;
            grid.style.gridTemplateRows = `repeat(${fvRows}, ${fvCellSize}px)`;
            grid.innerHTML = '';
            for (let r = 0; r < fvRows; r++) {
                for (let c = 0; c < fvCols; c++) {
                    const idx = r * fvCols + c;
                    const cellData = fvGridState[idx] || {
                        type: 'bare',
                        field: null
                    };
                    const info = fvTypeInfo(cellData.type);
                    const cell = document.createElement('div');
                    cell.className = 'fv-cell';
                    cell.style.background = info.color;
                    cell.style.width = fvCellSize + 'px';
                    cell.style.height = fvCellSize + 'px';
                    cell.dataset.idx = idx;
                    const linkBadge = cellData.field ? '<span style="position:absolute;top:1px;right:2px;font-size:9px">🔗</span>' : '';
                    cell.innerHTML = `<span class="fv-cell-coord">${fvColLabel(c)},${fvRowLabel(r)}</span>${linkBadge}${info.icon || ''}`;
                    cell.addEventListener('mousedown', (e) => {
                        if (fvPaintMode === 'field') {
                            fvOpenAssignModal(idx);
                            e.preventDefault();
                            return;
                        }
                        fvIsPainting = true;
                        fvPaintCell(idx);
                        e.preventDefault();
                    });
                    cell.addEventListener('mouseenter', (e) => {
                        if (fvIsPainting && fvPaintMode !== 'field') fvPaintCell(idx);
                        fvShowTooltip(e, r, c, cellData);
                    });
                    cell.addEventListener('mousemove', (e) => fvMoveTooltip(e));
                    cell.addEventListener('mouseleave', fvHideTooltip);
                    grid.appendChild(cell);
                }
            }
        }

        function fvPaintCell(idx) {
            const current = fvGridState[idx] || {
                type: 'bare',
                field: null
            };
            const newType = fvPaintMode === 'stage' ? fvSelectedStage : fvPaintMode === 'erase' ? 'bare' : fvPaintMode;
            if (current.type === newType) return;
            fvGridState[idx] = {
                ...current,
                type: newType
            };
            fvRenderGrid();
            fvRenderSummary();
            fvScheduleSave();
        }

        function fvOpenAssignModal(idx) {
            fvAssignTargetIdx = idx;
            const sel = document.getElementById('fvAssignSelect');
            sel.innerHTML = '<option value="">— No assignment (decorative cell) —</option>' +
                PHP_KNOWN_FIELDS_VIZ.map(f => `<option value="${fvEscapeHtml(f)}">${fvEscapeHtml(f)}</option>`).join('');
            sel.value = (fvGridState[idx] && fvGridState[idx].field) || '';
            document.getElementById('fvAssignModal').classList.add('show');
        }

        function fvCloseAssignModal() {
            document.getElementById('fvAssignModal').classList.remove('show');
            fvAssignTargetIdx = null;
        }

        function fvSaveAssignment() {
            if (fvAssignTargetIdx === null) return;
            const field = document.getElementById('fvAssignSelect').value || null;
            const current = fvGridState[fvAssignTargetIdx] || {
                type: 'bare',
                field: null
            };
            fvGridState[fvAssignTargetIdx] = {
                ...current,
                field
            };
            fvCloseAssignModal();
            fvRenderGrid();
            fvRenderSummary();
            fvScheduleSave();
        }

        function fvShowTooltip(e, r, c, cellData) {
            const tip = document.getElementById('fvTooltip');
            const label = fvTypeInfo(cellData.type).label + (cellData.field ? ` · 🔗 ${fvEscapeHtml(cellData.field)}` : '');
            tip.textContent = `${fvColLabel(c)}, ${fvRowLabel(r)} — ${label}`;
            tip.style.opacity = '1';
            fvMoveTooltip(e);
        }

        function fvMoveTooltip(e) {
            const tip = document.getElementById('fvTooltip');
            tip.style.left = (e.clientX + 14) + 'px';
            tip.style.top = (e.clientY + 14) + 'px';
        }

        function fvHideTooltip() {
            document.getElementById('fvTooltip').style.opacity = '0';
        }

        function fvScheduleSave() {
            clearTimeout(fvSaveTimer);
            fvSaveTimer = setTimeout(fvSaveLayout, 700);
        }

        function fvSaveLayout() {
            fetch('field_layout_save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    columns: fvCols,
                    rows: fvRows,
                    cell_size: fvCellSize,
                    grid: fvGridState
                })
            }).catch(() => {});
        }

        function fvSyncFromRecords() {
            const btn = document.getElementById('fvSyncBtn');
            btn.disabled = true;
            btn.textContent = 'Syncing…';
            fetch('field_layout_sync.php').then(res => res.json()).then(json => {
                btn.disabled = false;
                btn.textContent = '🔄 Sync from Records';
                if (!json.success) {
                    return;
                }
                const fieldStatus = json.field_status || {};
                let updated = 0;
                fvGridState = fvGridState.map(cell => {
                    if (!cell.field || !fieldStatus[cell.field]) return cell;
                    const newType = FV_ACTIVITY_TO_TYPE[fieldStatus[cell.field]];
                    if (!newType || newType === cell.type) return cell;
                    updated++;
                    return {
                        ...cell,
                        type: newType
                    };
                });
                fvRenderGrid();
                fvRenderSummary();
                if (updated > 0) fvScheduleSave();
            }).catch(() => {
                btn.disabled = false;
                btn.textContent = '🔄 Sync from Records';
            });
        }

        function fvInit() {
            fvEnsureGridSize();
            fvRenderControls();
            fvRenderGrid();
            fvRenderSummary();
            document.addEventListener('mouseup', () => {
                fvIsPainting = false;
            });
            document.getElementById('fvCols').addEventListener('change', function() {
                fvCols = Math.max(1, Math.min(12, parseInt(this.value, 10) || 1));
                fvEnsureGridSize();
                fvRenderGrid();
                fvRenderSummary();
                fvScheduleSave();
            });
            document.getElementById('fvRows').addEventListener('change', function() {
                fvRows = Math.max(1, Math.min(12, parseInt(this.value, 10) || 1));
                fvEnsureGridSize();
                fvRenderGrid();
                fvRenderSummary();
                fvScheduleSave();
            });
            document.getElementById('fvCellSize').addEventListener('input', function() {
                fvCellSize = parseInt(this.value, 10);
                document.getElementById('fvCellSizeLabel').textContent = fvCellSize;
                fvRenderGrid();
            });
            document.getElementById('fvCellSize').addEventListener('change', fvScheduleSave);
            document.getElementById('fvResetBtn').addEventListener('click', function() {
                if (!confirm('Reset the entire field to bare soil? This also clears all field links.')) return;
                fvGridState = fvGridState.map(() => ({
                    type: 'bare',
                    field: null
                }));
                fvRenderGrid();
                fvRenderSummary();
                fvScheduleSave();
            });
            document.getElementById('fvSyncBtn').addEventListener('click', fvSyncFromRecords);
        }
        (function() {
            if (!isMobile() && localStorage.getItem('sidebarCollapsed') === '1') document.body.classList.add('sidebar-collapsed');
            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    document.documentElement.classList.remove('sidebar-pre-collapse');
                });
            });
        })();
        document.addEventListener('DOMContentLoaded', () => {
            calcStats();
            rebuildCharts();
            fetchDashboardWeather();
            logDailyWeather();
            fvInit();
            loadPriceHistory().then(() => {
            buildProfitModal();
            });
        });
    </script>
    <script src="forecast-history.js"></script>
</body>

</html>

