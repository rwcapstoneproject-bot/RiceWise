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
// ── Planting cycles: field-scoped, repeatable ──────────────────────────
// Each cycle belongs to one field. A field can have many cycles over time
// (one finishes, another starts) and a user can have several *active*
// cycles at once (multiple fields being worked in parallel), matching how
// real farm_records is already structured.
$rs = $conn->prepare(
    "SELECT id, field_location, crop_type, start_date, status, completed_at
     FROM planting_cycles WHERE user_id=? ORDER BY start_date DESC, id DESC"
);
$rs->bind_param('i', $uid);
$rs->execute();
$allCycles = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
$rs->close();

// ── Auto-mark the calendar (process.php <-> calendar.php link) ─────────
// Any cycle that doesn't yet have a linked calendar entry — a brand-new
// cycle just created, OR an older cycle that already existed before this
// feature shipped — gets one now, dated on its start_date. Guarded by
// calendar_marked so this only ever runs ONCE per cycle: if the user later
// deletes that calendar entry themselves via calendar.php, it will NOT be
// silently recreated the next time they load this page.
$bf = $conn->prepare(
    "SELECT id, field_location, crop_type, start_date
     FROM planting_cycles WHERE user_id=? AND calendar_marked=0"
);
$bf->bind_param('i', $uid);
$bf->execute();
$toBackfill = $bf->get_result()->fetch_all(MYSQLI_ASSOC);
$bf->close();

$STEP_TEMPLATE = [
    ['name'=>'🚜 Land Preparation',    'desc'=>'Prepare field and soil leveling.',     'startOffset'=>0,  'endOffset'=>5,  'category'=>'other'],
    ['name'=>'🌱 Planting Seedlings',  'desc'=>'Transplant rice seedlings.',           'startOffset'=>6,  'endOffset'=>10, 'category'=>'planting'],
    ['name'=>'🌿 Weeding',             'desc'=>'Remove unwanted grass.',               'startOffset'=>11, 'endOffset'=>15, 'category'=>'other'],
    ['name'=>'⏱️ Growth Stage',        'desc'=>'Vegetative growth phase.',             'startOffset'=>16, 'endOffset'=>38, 'category'=>'other'],
    ['name'=>'🧪 Fertilizer',          'desc'=>'Apply nutrients.',                     'startOffset'=>23, 'endOffset'=>26, 'category'=>'fertilize'],
    ['name'=>'🐛 Insecticide',         'desc'=>'Prevent pest damage.',                 'startOffset'=>28, 'endOffset'=>30, 'category'=>'pest'],
    ['name'=>'💧 Spray Insecticide',   'desc'=>'Liquid spray pest control.',           'startOffset'=>33, 'endOffset'=>36, 'category'=>'pest'],
    ['name'=>'🍄 Spray Fungicide',     'desc'=>'Prevent fungal disease.',              'startOffset'=>36, 'endOffset'=>40, 'category'=>'pest'],
    ['name'=>'🌾 Harvest',             'desc'=>'Collect mature rice crops.',           'startOffset'=>50, 'endOffset'=>54, 'category'=>'harvest'],
];

if ($toBackfill) {
    $delOld = $conn->prepare(
        "DELETE FROM calendar_events WHERE cycle_id=? AND step_index IS NOT NULL"
    );
    $insEvt = $conn->prepare(
        "INSERT INTO calendar_events
            (user_id, cycle_id, step_index, title, description, event_date, event_end_date, event_time, category, reminder_type)
         VALUES (?, ?, ?, ?, ?, ?, ?, '08:00:00', ?, 'same-day')"
    );
    $markCycle = $conn->prepare("UPDATE planting_cycles SET calendar_marked=1 WHERE id=?");

    foreach ($toBackfill as $c) {
        // Clear any leftover auto-generated events for this cycle first,
        // so re-running the backfill (e.g. after a start_date change) never
        // stacks duplicate rows on top of stale ones.
        $delOld->bind_param('i', $c['id']);
        $delOld->execute();

        foreach ($STEP_TEMPLATE as $idx => $t) {
            $startDate = (new DateTime($c['start_date']))->modify("+{$t['startOffset']} days")->format('Y-m-d');
            $endDate   = $startDate; // pin auto-generated step events to their start date only
            $title = $t['name'] . ' — ' . $c['field_location'];
            $desc  = $t['desc'] . (!empty($c['crop_type']) ? ' (' . $c['crop_type'] . ')' : '');
            $insEvt->bind_param(
                    'iiisssss',
                    $uid, $c['id'], $idx, $title, $desc, $startDate, $endDate, $t['category']
                );
            $insEvt->execute();
        }
        $markCycle->bind_param('i', $c['id']);
        $markCycle->execute();
    }
    $delOld->close();
    $insEvt->close();
    $markCycle->close();
}

$activeCycles    = array_values(array_filter($allCycles, fn($c) => $c['status'] === 'active'));
$completedCycles = array_values(array_filter($allCycles, fn($c) => $c['status'] === 'completed'));
// Distinct field locations the user has actually logged in farm_records,
// used to populate the "Start New Process" field picker (plus a free-text
// fallback in the UI for a field not yet in farm_records).
$fl = $conn->prepare("SELECT DISTINCT location FROM farm_records WHERE user_id=? AND location IS NOT NULL AND location <> '' ORDER BY location ASC");
$fl->bind_param('i', $uid);
$fl->execute();
$knownFields = array_column($fl->get_result()->fetch_all(MYSQLI_ASSOC), 'location');
$fl->close();
// Which cycle is currently being viewed: ?cycle=ID in the URL, else the
// most recently started active cycle, else the most recent cycle overall,
// else none (empty state — no cycles exist yet for this user).
$selectedCycleId = isset($_GET['cycle']) ? (int)$_GET['cycle'] : 0;
$selectedCycle = null;

if ($selectedCycleId > 0) {
    foreach ($allCycles as $c) {
        if ((int)$c['id'] === $selectedCycleId) {
            $selectedCycle = $c;
            break;
        }
    }
}
if (!$selectedCycle && $activeCycles) {
    $selectedCycle = $activeCycles[0];
} elseif (!$selectedCycle && $allCycles) {
    $selectedCycle = $allCycles[0];
}
$doneSteps = [];
if ($selectedCycle) {
    $rs2 = $conn->prepare("SELECT step_index FROM planting_process_completed WHERE cycle_id=?");
    $rs2->bind_param('i', $selectedCycle['id']);
    $rs2->execute();
    $doneRows = $rs2->get_result()->fetch_all(MYSQLI_ASSOC);
    $rs2->close();
    $doneSteps = array_map(fn($r) => (int)$r['step_index'], $doneRows);
}
$jsAllCycles      = json_encode($allCycles);
$jsKnownFields    = json_encode($knownFields);
$jsSelectedCycle  = json_encode($selectedCycle);
$jsDoneSteps      = json_encode($doneSteps);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RiceWise - Planting Process</title>
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
        body.sidebar-collapsed .signout-text {
            display: none
        }
        body.sidebar-collapsed .expand-btn {
            display: none
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
            gap: 0
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
            width: 48px;
            height: 48px;
            flex-shrink: 0;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            transition: transform .2s;
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
            margin-left: auto;
            transition: background .2s
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
            background: #fcfcfb;
            box-shadow: inset 0 0 0 1px var(--sage-pale);
            color: var(--sage-dark);
            font-weight: 600;
        }
        .nav-item.active {
            background: var(--sage-pale);
            color: var(--sage-dark);
            font-weight: 600
        }
            
        .nav-item.active:hover {
            background: var(--sage-pale);
            box-shadow: inset 0 0 0 1px var(--sage-light);
            color: var(--sage-dark);
        }
        .nav-item svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0
        }
        .nav-item svg .icon-filled {
            display: none;
            transform-origin: 12px 12px;
            transform: scale(1.15)
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
            display: flex
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
        .process-scroll {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 22px 24px
        }
        .process-scroll::-webkit-scrollbar {
            width: 3px
        }
        .process-scroll::-webkit-scrollbar-thumb {
            background: var(--cream-3);
            border-radius: 2px
        }
        .progress-card {
            background: linear-gradient(135deg, var(--sage-dark) 0%, #3d6a35 45%, #5a9050 100%);
            border-radius: var(--radius);
            padding: 26px 28px;
            margin-bottom: 22px;
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(45, 82, 40, .3)
        }
        .progress-card::before {
            content: '';
            position: absolute;
            top: -60px;
            right: -50px;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, .055);
            border-radius: 50%;
            pointer-events: none
        }
        .progress-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            flex-wrap: wrap;
            gap: 10px
        }
        .progress-title {
            font-family: 'Lora', serif;
            font-size: 19px;
            font-weight: 700
        }
        .progress-status {
            font-size: 12px;
            padding: 5px 14px;
            border-radius: 20px;
            font-weight: 700
        }
        .status-ongoing {
            background: rgba(200, 150, 62, .3);
            color: #fde68a;
            border: 1px solid rgba(200, 150, 62, .4)
        }
        .status-completed {
            background: rgba(255, 255, 255, .2);
            color: white;
            border: 1px solid rgba(255, 255, 255, .3)
        }
        .status-upcoming {
            background: rgba(255, 255, 255, .12);
            color: rgba(255, 255, 255, .75);
            border: 1px solid rgba(255, 255, 255, .2)
        }
        .progress-bar-wrap {
            background: rgba(255, 255, 255, .18);
            border-radius: 50px;
            overflow: hidden;
            height: 12px;
            margin-bottom: 14px
        }
        .progress-bar-fill {
            height: 100%;
            border-radius: 50px;
            background: linear-gradient(90deg, rgba(255, 255, 255, .6), rgba(255, 255, 255, .95));
            box-shadow: 0 0 12px rgba(255, 255, 255, .5);
            transition: width .9s cubic-bezier(.4, 0, .2, 1)
        }
        .progress-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px
        }
        .progress-pct {
            font-family: 'Lora', serif;
            font-size: 26px;
            font-weight: 700
        }
        .progress-dates {
            font-size: 13px;
            opacity: .75;
            display: flex;
            flex-wrap: wrap;
            gap: 10px 20px
        }
        .progress-dates span {
            font-weight: 600;
            opacity: 1
        }
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 22px
        }
        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--border-light);
            padding: 18px 20px;
            text-align: center;
            box-shadow: var(--shadow-xs);
            transition: all .18s
        }
        .stat-card:hover {
            border-color: var(--sage-light);
            box-shadow: var(--shadow-sm);
            transform: translateY(-2px)
        }
        .stat-num {
            font-family: 'Lora', serif;
            font-size: 34px;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 5px
        }
        .stat-num.green {
            color: var(--sage-dark)
        }
        .stat-num.gold {
            color: var(--gold)
        }
        .stat-num.muted {
            color: var(--text-muted)
        }
        .stat-lbl {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .9px
        }
        .timeline-section-title {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px
        }
        .timeline-section-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border)
        }
        .timeline {
            position: relative;
            padding: 4px 0 30px
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(to bottom, var(--sage-light), var(--border-light));
            border-radius: 2px;
            z-index: 0
        }
        .timeline-row {
            display: flex;
            justify-content: flex-end;
            align-items: flex-start;
            position: relative;
            margin-bottom: 24px;
            padding-right: calc(50% + 28px)
        }
        .timeline-row.right {
            justify-content: flex-start;
            padding-right: 0;
            padding-left: calc(50% + 28px)
        }
        .timeline-row::before {
            content: '';
            position: absolute;
            left: 50%;
            top: 20px;
            transform: translateX(-50%);
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: var(--cream-3);
            border: 3px solid var(--border);
            z-index: 2;
            transition: all .3s
        }
        .timeline-row.done::before {
            background: var(--sage-light);
            border-color: var(--sage)
        }
        .timeline-row.active::before {
            background: var(--sage);
            border-color: var(--sage-dark);
            box-shadow: 0 0 0 5px rgba(90, 122, 82, .18)
        }
        .timeline-row.manually-done::before {
            background: var(--sage);
            border-color: var(--sage-dark)
        }
        .timeline-row.upcoming::before {
            background: var(--white);
            border-color: var(--border)
        }
        .timeline-row::after {
            content: '';
            position: absolute;
            top: 26px;
            height: 2px;
            background: var(--border-light);
            z-index: 1;
            right: calc(50% + 14px);
            width: 14px
        }
        .timeline-row.right::after {
            right: auto;
            left: calc(50% + 14px)
        }
        .step {
            background: var(--white);
            padding: 18px 20px;
            border-radius: var(--radius);
            border: 1px solid var(--border-light);
            cursor: pointer;
            position: relative;
            z-index: 1;
            transition: all .22s;
            width: 100%;
            border-top: 4px solid var(--sage-light);
            box-shadow: var(--shadow-xs)
        }
        .step:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
            border-color: var(--sage-light)
        }
        .step.ongoing {
            background: var(--sage-pale);
            border-color: var(--sage-light);
            border-top-color: var(--sage)
        }
        .step.done {
            opacity: .8
        }
        .step.manually-completed {
            background: #eaf5ea;
            border-color: #9fcf9a;
            border-top-color: var(--sage-dark) !important;
            opacity: 1
        }
        .step.land {
            border-top-color: #4caf50
        }
        .step.planting {
            border-top-color: #81c784
        }
        .step.weeding {
            border-top-color: #aed581
        }
        .step.growth {
            border-top-color: #4db6ac
        }
        .step.fertilizer {
            border-top-color: var(--gold)
        }
        .step.insecticide {
            border-top-color: #ff8a65
        }
        .step.spray {
            border-top-color: #29b6f6
        }
        .step.fungicide {
            border-top-color: #ab47bc
        }
        .step.harvest {
            border-top-color: var(--gold) !important
        }
        .step-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px
        }
        .step-name {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.3
        }
        .step-badge {
            font-size: 12px;
            padding: 4px 11px;
            border-radius: 20px;
            font-weight: 700;
            white-space: nowrap;
            flex-shrink: 0
        }
        .badge-done {
            background: var(--sage-pale);
            color: var(--sage-dark)
        }
        .badge-ongoing {
            background: var(--gold-pale);
            color: var(--gold)
        }
        .badge-upcoming {
            background: var(--cream-2);
            color: var(--text-muted)
        }
        .badge-manually-done {
            background: #d4edda;
            color: #1a5c2a
        }
        .step-desc {
            font-size: 13.5px;
            color: var(--text-secondary);
            margin-top: 6px;
            line-height: 1.5
        }
        .step-date {
            font-family: 'DM Mono', monospace;
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 5px
        }
        /* expandable details */
        .step-details {
            font-size: 13.5px;
            color: var(--text-secondary);
            line-height: 1.55;
            max-height: 0;
            overflow: hidden;
            transition: max-height .35s ease, margin-top .35s, padding-top .35s
        }
        .timeline-row.open .step-details {
            max-height: 200px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border-light)
        }
        .step-expand-hint {
            font-size: 11.5px;
            color: var(--text-muted);
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 4px
        }
        .step:hover .step-expand-hint {
            color: var(--sage)
        }
        .timeline-row.open .step-expand-hint {
            display: none
        }
        /* mark complete button inside expanded area */
        .step-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid var(--border-light);
            max-height: 0;
            overflow: hidden;
            opacity: 0;
            transition: max-height .35s ease, opacity .25s, margin-top .35s, padding-top .35s
        }
        .timeline-row.open .step-actions {
            max-height: 60px;
            opacity: 1
        }
        .mark-complete-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 20px;
            background: linear-gradient(135deg, var(--sage), var(--sage-dark));
            color: var(--white);
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            box-shadow: 0 3px 12px rgba(61, 92, 56, .28);
            transition: all .2s;
            white-space: nowrap
        }
        .mark-complete-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 16px rgba(61, 92, 56, .36)
        }
        .mark-complete-btn:active {
            transform: translateY(0)
        }
        .mark-complete-btn svg {
            width: 14px;
            height: 14px
        }
        .mark-complete-btn.already-done {
            background: var(--sage-pale);
            color: var(--sage-dark);
            box-shadow: none;
            cursor: default;
            pointer-events: none;
            border: 1px solid var(--sage-light)
        }
        .mark-complete-btn:disabled {
            opacity: .6;
            cursor: not-allowed
        }
        /* flash animation */
        @keyframes completedFlash {
            0% {
                background: #c8f0c8
            }
            100% {
                background: #eaf5ea
            }
        }
        .step.flash-complete {
            animation: completedFlash .6s ease forwards
        }
        @media(max-width:1024px) and (min-width:769px) {
            .timeline-row {
                padding-right: calc(50% + 22px)
            }
            .timeline-row.right {
                padding-left: calc(50% + 22px);
                padding-right: 0
            }
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
                padding: 0 16px
            }
            .hamburger-btn {
                display: flex !important
            }
            .profile-pill-name {
                display: none
            }
            .process-scroll {
                display: block !important;
                overflow: visible !important;
                height: auto !important;
                padding: 16px
            }
            .stats-row {
                gap: 10px
            }
            .stat-num {
                font-size: 26px
            }
            .timeline::before {
                left: 20px;
                transform: none
            }
            .timeline-row,
            .timeline-row.right {
                justify-content: flex-end;
                padding-right: 0;
                padding-left: 50px
            }
            .timeline-row::before {
                left: 20px;
                transform: none
            }
            .timeline-row::after {
                right: auto;
                left: 34px;
                width: 16px
            }
            .timeline-row.right::after {
                left: 34px
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
            .step-actions {
                justify-content: flex-start
            }
        }
        @media(max-width:480px) {
            .process-scroll {
                padding: 12px
            }
            .progress-card {
                padding: 20px 18px
            }
            .progress-title {
                font-size: 16px
            }
            .progress-pct {
                font-size: 22px
            }
            .stats-row {
                gap: 8px
            }
            .stat-num {
                font-size: 22px
            }
            .stat-lbl {
                font-size: 10px
            }
            .step {
                padding: 14px 16px
            }
            .step-name {
                font-size: 14px
            }
        }
        /* ===== CYCLE BAR ===== */
        .cycle-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 18px
        }
        .cycle-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap
        }
        .cycle-tab {
            padding: 8px 16px;
            border-radius: 20px;
            border: 1.5px solid var(--border);
            background: var(--white);
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all .18s;
            font-family: 'DM Sans', sans-serif;
            display: flex;
            align-items: center;
            gap: 6px
        }
        .cycle-tab:hover {
            border-color: var(--sage-light);
            color: var(--sage-dark)
        }
        .cycle-tab.active {
            background: var(--sage);
            border-color: var(--sage);
            color: white
        }
        .cycle-tab .cycle-tab-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--gold);
            flex-shrink: 0
        }
        .cycle-tab.active .cycle-tab-dot {
            background: #fde68a
        }
        .cycle-bar-actions {
            display: flex;
            align-items: center;
            gap: 10px
        }
        .cycle-history-link {
            font-size: 13px;
            font-weight: 600;
            color: var(--sage-dark);
            text-decoration: none;
            padding: 8px 6px;
            transition: color .18s
        }
        .cycle-history-link:hover {
            color: var(--sage)
        }
        .cycle-new-btn {
            padding: 9px 18px;
            background: var(--sage);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            transition: background .18s;
            white-space: nowrap
        }
        .cycle-new-btn:hover {
            background: var(--sage-dark)
        }
        .progress-field-sub {
            font-size: 13px;
            opacity: .8;
            margin-top: 3px;
            font-weight: 500
        }
        /* ===== EMPTY STATE ===== */
        .empty-cycle-state {
            background: var(--white);
            border: 1.5px dashed var(--border);
            border-radius: var(--radius);
            padding: 50px 30px;
            text-align: center
        }
        .empty-cycle-icon {
            font-size: 44px;
            margin-bottom: 12px;
            opacity: .6
        }
        .empty-cycle-title {
            font-family: 'Lora', serif;
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px
        }
        .empty-cycle-text {
            font-size: 13.5px;
            color: var(--text-muted);
            max-width: 380px;
            margin: 0 auto 20px;
            line-height: 1.6
        }
        /* ===== NEW-PROCESS MODAL (shared modal system, process.html had none originally) ===== */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            inset: 0;
            background: rgba(30, 42, 26, .4);
            backdrop-filter: blur(4px);
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
            max-width: 420px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-lg);
            animation: slideUp .22s ease;
            overflow: hidden
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
            align-items: center
        }
        .modal-head-title {
            font-family: 'Lora', serif;
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary)
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
            transition: all .2s
        }
        .modal-close:hover {
            background: var(--cream-3)
        }
        .modal-bod {
            padding: 20px 22px
        }
        .modal-foot {
            padding: 14px 22px 18px;
            border-top: 1px solid var(--border-light);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap
        }
        .mbtn {
            padding: 9px 20px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
            font-family: 'DM Sans', sans-serif
        }
        .mbtn-cancel {
            background: var(--cream-2);
            color: var(--text-secondary)
        }
        .mbtn-cancel:hover {
            background: var(--cream-3)
        }
        .mbtn-confirm {
            background: var(--sage);
            color: var(--white)
        }
        .mbtn-confirm:hover {
            background: var(--sage-dark)
        }
        .mbtn-confirm:disabled {
            opacity: .6;
            cursor: not-allowed
        }
        .form-field {
            margin-bottom: 14px
        }
        .field-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--text-muted);
            margin-bottom: 5px
        }
        .form-input {
            width: 100%;
            padding: 9px 12px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 13.5px;
            font-family: 'DM Sans', sans-serif;
            color: var(--text-primary);
            background: var(--cream)
        }
        .form-input:focus {
            outline: none;
            border-color: var(--sage-light);
            background: var(--white)
        }
        .form-error-text {
            font-size: 12px;
            color: #c0392b;
            font-weight: 600;
            display: none
        }
        .form-error-text.show {
            display: block
        }
            
        /* =====================================================================
           PLANTING PROCESS REDESIGN  (append at the very END of your <style>)
           Old .stats-row / .timeline / .timeline-row / .step rules become unused,
           you can delete them later. Nothing here depends on them.
           ===================================================================== */

        /* ---------- Hero: progress + stats + "what's happening now" ---------- */
        .hero {
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(0, 1fr);
            gap: 28px;
            background: linear-gradient(135deg, var(--sage-dark) 0%, #3d6a35 45%, #5a9050 100%);
            border-radius: var(--radius);
            padding: 26px 30px;
            margin-bottom: 20px;
            color: #fff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(45, 82, 40, .3)
        }
        .hero::before {
            content: '';
            position: absolute;
            top: -70px;
            right: -60px;
            width: 220px;
            height: 220px;
            background: rgba(255, 255, 255, .055);
            border-radius: 50%;
            pointer-events: none
        }
        .hero-main,
        .hero-side { position: relative; z-index: 1; min-width: 0 }
        .hero-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 20px
        }
        .hero-pct-row {
            display: flex;
            align-items: baseline;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 10px
        }
        .hero-day { font-size: 13.5px; font-weight: 500; opacity: .85 }
        .hero .progress-bar-wrap { margin-bottom: 12px }
        .hero-side {
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 10px
        }
        .hero-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px }
        .hero-stat {
            background: rgba(255, 255, 255, .12);
            border: 1px solid rgba(255, 255, 255, .16);
            border-radius: 12px;
            padding: 12px 6px;
            text-align: center
        }
        .hero-stat b {
            display: block;
            font-family: 'Lora', serif;
            font-size: 28px;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 5px
        }
        .hero-stat span { font-size: 12px; opacity: .82 }
        .hero-focus {
            background: rgba(0, 0, 0, .15);
            border-radius: 12px;
            padding: 11px 14px;
            font-size: 13.5px;
            line-height: 1.45
        }
        .hero-focus strong {
            display: block;
            font-size: 12px;
            font-weight: 600;
            opacity: .7;
            margin-bottom: 2px
        }

        /* ---------- Section headings ---------- */
        .sec-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 6px 16px;
            flex-wrap: wrap;
            margin-bottom: 12px
        }
        .sec-title { font-family: 'Lora', serif; font-size: 17px; font-weight: 700; color: var(--text-primary) }
        .sec-sub { font-size: 12.5px; color: var(--text-muted); max-width: 62ch; line-height: 1.5 }

        /* ---------- Season schedule (Gantt) ---------- */
        .gantt {
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 18px 20px 12px;
            margin-bottom: 26px;
            box-shadow: var(--shadow-xs)
        }
        .g-legend { display: flex; gap: 14px; flex-wrap: wrap; font-size: 12px; color: var(--text-secondary); align-items: center }
        .g-legend span { display: inline-flex; align-items: center; gap: 6px }
        .g-legend i { display: inline-block; width: 12px; height: 12px; border-radius: 3px; background: var(--sage) }
        .g-legend i.lg-done { opacity: .85 }
        .g-legend i.lg-ongoing { box-shadow: 0 0 0 2px rgba(200, 150, 62, .4) }
        .g-legend i.lg-upcoming { opacity: .35 }
        .g-legend i.lg-today { width: 2px; height: 14px; border-radius: 1px; background: #d9534f }
        .g-scroll { overflow-x: auto; padding-right: 18px; margin-top: 4px }
        .g-inner { --gl: 176px; position: relative; min-width: 660px; padding-bottom: 24px }
        .g-weeks {
            position: relative;
            height: 24px;
            margin-left: var(--gl);
            border-bottom: 1px solid var(--border)
        }
        .g-week {
            position: absolute;
            top: 3px;
            padding-left: 6px;
            font-size: 11.5px;
            color: var(--text-muted);
            font-variant-numeric: tabular-nums;
            white-space: nowrap
        }
        .g-row {
            display: grid;
            grid-template-columns: var(--gl) minmax(0, 1fr);
            align-items: center;
            height: 38px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-light)
        }
        .g-row:last-of-type { border-bottom: none }
        .g-label {
            display: flex;
            align-items: center;
            gap: 8px;
            height: 100%;
            min-width: 0;
            padding-right: 10px;
            background: var(--white);
            position: sticky;
            left: 0;
            z-index: 2
        }
        .g-num {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--cream-2);
            color: var(--text-secondary);
            font-size: 11px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0
        }
        .g-name {
            display: flex;
            align-items: center;
            gap: 7px;
            min-width: 0;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-primary);
            transition: color .15s
        }
        .g-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
        .g-row:hover .g-name { color: var(--sage-dark) }
        .g-track {
            position: relative;
            height: 100%;
            background-image: repeating-linear-gradient(to right,
                var(--border-light) 0, var(--border-light) 1px,
                transparent 1px, transparent var(--wk))
        }
        .g-bar {
            position: absolute;
            top: 9px;
            height: 20px;
            min-width: 8px;
            border-radius: 6px;
            background: var(--c);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center
        }
        .g-bar.done { opacity: .85 }
        .g-bar.upcoming { opacity: .35 }
        .g-bar.ongoing { box-shadow: 0 0 0 3px rgba(200, 150, 62, .3) }
        .g-overlay {
            position: absolute;
            top: 24px;
            bottom: 24px;
            left: var(--gl);
            right: 0;
            pointer-events: none;
            z-index: 3
        }
        .g-today {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 2px;
            margin-left: -1px;
            background: #d9534f
        }
        .g-today::after {
            content: 'Today';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            margin-top: 4px;
            background: #d9534f;
            color: #fff;
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 10px;
            white-space: nowrap
        }

        /* ---------- Step cards: 3-up grid, never more than 3 columns ---------- */
        .steps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(max(290px, calc((100% - 28px) / 3)), 1fr));
            gap: 14px;
            align-items: start;
            padding-bottom: 30px
        }
        .step-card {
            --c: var(--sage-light);
            background: var(--white);
            border: 1px solid var(--border-light);
            border-top: 4px solid var(--c);
            border-radius: var(--radius);
            padding: 15px 18px 10px;
            cursor: pointer;
            box-shadow: var(--shadow-xs);
            transition: box-shadow .2s, background .3s
        }
        .step-card:hover { box-shadow: var(--shadow-md) }
        .step-card.ongoing {
            background: var(--gold-pale);
            box-shadow: 0 0 0 2px rgba(200, 150, 62, .35), var(--shadow-sm)
        }
        .step-card.manual { background: #eaf5ea }
        .step-card.flash-complete { animation: completedFlash .6s ease forwards }
        .step-card.highlight { animation: cardPing 1.1s ease }
        @keyframes cardPing {
            0% { box-shadow: 0 0 0 0 rgba(90, 122, 82, .55) }
            100% { box-shadow: 0 0 0 16px rgba(90, 122, 82, 0) }
        }
        .sc-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px
        }
        .sc-num { font-size: 12.5px; font-weight: 700; color: var(--text-muted); font-variant-numeric: tabular-nums }
        .sc-body { display: flex; gap: 12px; align-items: flex-start }
        .sc-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--cream-2);
            background: color-mix(in srgb, var(--c) 18%, white);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0
        }
        .sc-title { font-size: 15.5px; font-weight: 700; line-height: 1.3; color: var(--text-primary) }
        .sc-desc { font-size: 13.5px; color: var(--text-secondary); margin-top: 3px; line-height: 1.5 }
        .sc-meta { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px }
        .sc-chip {
            font-size: 12px;
            font-weight: 500;
            color: var(--text-secondary);
            background: var(--cream);
            border: 1px solid var(--border-light);
            padding: 3px 10px;
            border-radius: 20px;
            font-variant-numeric: tabular-nums;
            display: inline-flex;
            align-items: center;
            gap: 5px
        }
        .step-card.ongoing .sc-chip { background: var(--white) }
        .sc-more {
            max-height: 0;
            overflow: hidden;
            opacity: 0;
            visibility: hidden;
            transition: max-height .3s ease, opacity .25s, margin-top .3s, visibility 0s .3s
        }
        .step-card.open .sc-more {
            max-height: 240px;
            opacity: 1;
            visibility: visible;
            margin-top: 12px;
            transition-delay: 0s
        }
        .sc-tip {
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.55;
            background: var(--cream);
            border-radius: 10px;
            padding: 10px 12px
        }
        .step-card.ongoing .sc-tip { background: var(--white) }
        .sc-tip strong { color: var(--text-primary) }
        .sc-actions { display: flex; justify-content: flex-end; margin-top: 10px }
        .sc-foot {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            width: 100%;
            margin-top: 12px;
            padding: 7px 0 3px;
            background: none;
            border: none;
            border-top: 1px solid var(--border-light);
            font: inherit;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer
        }
        .step-card:hover .sc-foot { color: var(--sage) }
        .sc-chevron { transition: transform .25s }
        .step-card.open .sc-chevron { transform: rotate(180deg) }
        .sc-foot:focus-visible,
        .g-row:focus-visible { outline: 2px solid var(--sage); outline-offset: 2px; border-radius: 6px }

        /* ---------- Icons (replace every emoji) ---------- */
        .ic { width: 1em; height: 1em; flex-shrink: 0; display: inline-block }
        .ic-fill { fill: currentColor }
        .sc-icon .ic {
            width: 22px;
            height: 22px;
            color: var(--sage-dark);
            color: color-mix(in srgb, var(--c) 60%, #1e2a1a)
        }
        .sc-chip .ic { width: 13px; height: 13px; color: var(--text-muted) }
        .g-name .ic { width: 15px; height: 15px; color: var(--text-muted) }
        .g-bar .ic { width: 12px; height: 12px; stroke-width: 3 }
        .sc-top .step-badge { display: inline-flex; align-items: center; gap: 5px }
        .step-badge .ic { width: 12px; height: 12px; stroke-width: 3 }
        .live-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--gold);
            animation: livePulse 1.8s ease-out infinite
        }
        @keyframes livePulse {
            0% { box-shadow: 0 0 0 0 rgba(200, 150, 62, .55) }
            100% { box-shadow: 0 0 0 7px rgba(200, 150, 62, 0) }
        }
        .hf-list { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 16px }
        .hf-item { display: inline-flex; align-items: center; gap: 7px; font-weight: 600 }
        .hf-item .ic { width: 16px; height: 16px }
        .hf-when { opacity: .75; font-size: 12.5px }
        .toast .ic { width: 18px; height: 18px }
        .cycle-history-link { display: inline-flex; align-items: center; gap: 6px }
        .cycle-history-link .ic { width: 15px; height: 15px }
        .empty-cycle-icon {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            background: var(--sage-pale);
            color: var(--sage);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: inherit;
            opacity: 1
        }
        .empty-cycle-icon .ic { width: 36px; height: 36px }
        .logo-icon .ic,
        .expand-btn .ic { width: 26px; height: 26px; color: var(--sage) }
        .star-gold { color: #e6a817; width: 12px; height: 12px; vertical-align: -1px }

        /* ---------- Toast (showToast() used this class but it had no CSS) ---------- */
        .toast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translate(-50%, 20px);
            background: var(--text-primary);
            color: #fff;
            padding: 11px 18px;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: var(--shadow-lg);
            opacity: 0;
            pointer-events: none;
            transition: opacity .25s, transform .25s;
            z-index: 3000
        }
        .toast.show { opacity: 1; transform: translate(-50%, 0) }

        /* ---------- Responsive ---------- */
        @media (max-width: 820px) {
            .hero { grid-template-columns: 1fr; gap: 18px; padding: 20px 18px }
        }
        @media (max-width: 640px) {
            .gantt { padding: 14px 12px 8px }
            .g-inner { --gl: 132px }
        }
        @media (prefers-reduced-motion: reduce) {
            .sc-more, .sc-chevron, .progress-bar-fill, .step-card { transition: none }
            .step-card.highlight { animation: none }
            .live-dot { animation: none }
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-top">
            <div class="logo-wrap" onclick="toggleSidebar()" title="Toggle sidebar">
                <div class="logo-icon"><img src="pictures/RiceWise_Logo.png" alt=""
                        onerror="window.logoFallback && logoFallback(this)"></div>
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
                    onerror="window.logoFallback && logoFallback(this)">
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

            <a href="calendar.php" class="nav-item" data-tooltip="Calendar"><svg viewBox="0 0 24 24">
                    <g class="icon-outline" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" /><line x1="16" y1="2" x2="16" y2="6" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="3" y1="10" x2="21" y2="10" />
                    </g>
                    <g class="icon-filled" fill="currentColor">
                        <rect x="3" y="4" width="18" height="18" rx="3" /><rect x="7" y="2" width="2" height="5" rx="1" /><rect x="15" y="2" width="2" height="5" rx="1" /><rect x="3" y="9.3" width="18" height="1.6" fill="var(--white)" />
                    </g>
                </svg><span class="nav-text">Calendar</span></a>

            <a href="process.php" class="nav-item active" data-tooltip="Planting Process"><svg viewBox="0 0 24 24">
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
                <button class="hamburger-btn" onclick="toggleSidebar()" aria-label="Open menu">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <rect x="3" y="3" width="18" height="18" rx="2" />
                        <path d="M9 3v18" />
                    </svg>
                </button>
                Planting Process
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
        <div class="process-scroll">
            <div class="cycle-bar">
                <div class="cycle-tabs" id="cycleTabs"></div>
                <div class="cycle-bar-actions">
                    <a href="process_history.php" class="cycle-history-link"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/></svg> History</a>
                    <button class="cycle-new-btn" id="openNewCycleBtn">+ Start New Process</button>
                </div>
            </div>
            <div id="emptyState" class="empty-cycle-state" style="display:none">
                <div class="empty-cycle-icon"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 9.536V7a4 4 0 0 1 4-4h1.5a.5.5 0 0 1 .5.5V5a4 4 0 0 1-4 4 4 4 0 0 0-4 4c0 2 1 3 1 5a5 5 0 0 1-1 3"/><path d="M4 9a5 5 0 0 1 8 4 5 5 0 0 1-8-4"/><path d="M5 21h14"/></svg></div> 
                <div class="empty-cycle-title">No active planting process yet</div>
                <div class="empty-cycle-text">Start tracking a field's planting cycle — pick a field, set a start date, and RiceWise will lay out the full 9-step timeline for you.</div>
                <button class="cycle-new-btn" id="openNewCycleBtnEmpty">+ Start New Process</button>
            </div>
                        <div id="cycleView" style="display:none">

                <!-- Hero: progress, stats and what's happening now, in one card -->
                <div class="hero">
                    <div class="hero-main">
                        <div class="hero-head">
                            <div>
                                <div class="progress-title" id="progressTitle">Planting progress</div>
                                <div class="progress-field-sub" id="progressFieldSub"></div>
                            </div>
                            <div class="progress-status status-ongoing" id="progressStatus">Ongoing</div>
                        </div>
                        <div class="hero-pct-row">
                            <div class="progress-pct" id="progressPct">0%</div>
                            <div class="hero-day" id="heroDay"></div>
                        </div>
                        <div class="progress-bar-wrap">
                            <div class="progress-bar-fill" id="progressBar" style="width:0%"></div>
                        </div>
                        <div class="progress-dates">Start: <span id="startDate">—</span> &nbsp; Est. Finish: <span id="finishDate">—</span></div>
                    </div>
                    <div class="hero-side">
                        <div class="hero-stats">
                            <div class="hero-stat"><b id="statDone">0</b><span>Completed</span></div>
                            <div class="hero-stat"><b id="statActive">0</b><span>In progress</span></div>
                            <div class="hero-stat"><b id="statUpcoming">0</b><span>Upcoming</span></div>
                        </div>
                        <div class="hero-focus" id="heroFocus"></div>
                    </div>
                </div>

                <!-- Season schedule: every step on one shared time axis -->
                <section class="gantt">
                    <div class="sec-head">
                        <div>
                            <div class="sec-title">Season schedule</div>
                            <div class="sec-sub">Some steps run at the same time. Tap a row to jump to its card.</div>
                        </div>
                        <div class="g-legend">
                            <span><i class="lg-done"></i>Done</span>
                            <span><i class="lg-ongoing"></i>In progress</span>
                            <span><i class="lg-upcoming"></i>Upcoming</span>
                            <span><i class="lg-today"></i>Today</span>
                        </div>
                    </div>
                    <div class="g-scroll"><div class="g-inner" id="ganttInner"></div></div>
                </section>

                <!-- Step cards -->
                <div class="sec-head">
                    <div class="sec-title">All steps</div>
                    <div class="sec-sub" id="stepsSub"></div>
                </div>
                <div class="steps-grid" id="stepsGrid"></div>
            </div>
        </div>
    </div>
    <!-- START NEW PROCESS MODAL -->
    <div id="newCycleModal" class="modal">
        <div class="modal-box">
            <div class="modal-head"><span class="modal-head-title">Start New Planting Process</span><button
                    class="modal-close" onclick="closeNewCycleModal()">×</button></div>
            <div class="modal-bod">
                <div class="form-field">
                    <div class="field-label">Field *</div>
                    <select class="form-input" id="newCycleFieldSelect"></select>
                    <input type="text" class="form-input" id="newCycleFieldCustom"
                        placeholder="Enter field name…" style="display:none;margin-top:8px">
                </div>
                <div class="form-field">
                    <div class="field-label">Crop Type (optional)</div>
                    <input type="text" class="form-input" id="newCycleCropType" placeholder="e.g., NSIC Rc222">
                </div>
                <div class="form-field">
                    <div class="field-label">Start Date *</div>
                    <input type="date" class="form-input" id="newCycleStartDate">
                </div>
                <div class="form-error-text" id="newCycleError"></div>
            </div>
            <div class="modal-foot"><button class="mbtn mbtn-cancel" onclick="closeNewCycleModal()">Cancel</button><button
                    class="mbtn mbtn-confirm" id="newCycleConfirmBtn" onclick="submitNewCycle()">Start
                    Process</button></div>
        </div>
    </div>
    <script>
        const isMobile = () => window.innerWidth <= 768;
        (function() {
            if (!isMobile() && localStorage.getItem('sidebarCollapsed') === '1') document.body.classList.add('sidebar-collapsed');
            document.documentElement.classList.remove('sidebar-pre-collapse');
        })();
            
        function logoFallback(img) { img.parentElement.innerHTML = svgIcon('sprout'); }
            
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
        /* ===== BELL DROPDOWN — live data, same pattern as dashboard.php / records.php / reports.php / calendar.php ===== */
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
                        <div class="nd-item-title">${parseInt(n.starred, 10) ? svgIcon('star', 'ic-fill star-gold') + ' ' : ''}${bellEscapeHtml(n.title)}</div>
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
        // ── Server data: all cycles, known field names, currently selected cycle ──
        const ALL_CYCLES = <?= $jsAllCycles ?>;
        const KNOWN_FIELDS = <?= $jsKnownFields ?>;
        let selectedCycle = <?= $jsSelectedCycle ?>;
        let manuallyDone = <?= $jsDoneSteps ?>; // step indices completed for the SELECTED cycle
        // Step template as day-offsets from a cycle's start_date, instead of
        // fixed calendar dates — so a brand-new cycle starting today gets a
        // correct, current timeline instead of a stale Feb 2026 one.
                // Step template as day-offsets from a cycle's start_date.
        // `color` drives the card accent + the Gantt bar for that step.
                /* ===== ICONS (Lucide, ISC license). Inline SVG, inherits text color via currentColor ===== */
        const ICONS = {
            tractor: '<path d="m10 11 11 .9a1 1 0 0 1 .8 1.1l-.665 4.158a1 1 0 0 1-.988.842H20"/> <path d="M16 18h-5"/> <path d="M18 5a1 1 0 0 0-1 1v5.573"/> <path d="M3 4h8.129a1 1 0 0 1 .99.863L13 11.246"/> <path d="M4 11V4"/> <path d="M7 15h.01"/> <path d="M8 10.1V4"/> <circle cx="18" cy="18" r="2"/> <circle cx="7" cy="15" r="5"/>',
            sprout: '<path d="M14 9.536V7a4 4 0 0 1 4-4h1.5a.5.5 0 0 1 .5.5V5a4 4 0 0 1-4 4 4 4 0 0 0-4 4c0 2 1 3 1 5a5 5 0 0 1-1 3"/> <path d="M4 9a5 5 0 0 1 8 4 5 5 0 0 1-8-4"/> <path d="M5 21h14"/>',
            shovel: '<path d="M21.56 4.56a1.5 1.5 0 0 1 0 2.122l-.47.47a3 3 0 0 1-4.212-.03 3 3 0 0 1 0-4.243l.44-.44a1.5 1.5 0 0 1 2.121 0z"/> <path d="M3 22a1 1 0 0 1-1-1v-3.586a1 1 0 0 1 .293-.707l3.355-3.355a1.205 1.205 0 0 1 1.704 0l3.296 3.296a1.205 1.205 0 0 1 0 1.704l-3.355 3.355a1 1 0 0 1-.707.293z"/> <path d="m9 15 7.879-7.878"/>',
            leaf: '<path d="M11 20a10 10 0 0010-10 25.9 25.9 0 00-1.04-7.281 1 1 0 00-1.755-.325C15.833 5.5 13 5.5 9.8 6.1A7 7 0 0011 20"/> <path d="M2 21a5 5 0 012.911-4.544C7.613 15.212 8.351 15.24 11 13"/>',
            'flask-conical': '<path d="M14 2v6a2 2 0 0 0 .245.96l5.51 10.08A2 2 0 0 1 18 22H6a2 2 0 0 1-1.755-2.96l5.51-10.08A2 2 0 0 0 10 8V2"/> <path d="M6.453 15h11.094"/> <path d="M8.5 2h7"/>',
            bug: '<path d="M12 20v-9"/> <path d="M14 7a4 4 0 0 1 4 4v3a6 6 0 0 1-12 0v-3a4 4 0 0 1 4-4z"/> <path d="M14.12 3.88 16 2"/> <path d="M21 21a4 4 0 0 0-3.81-4"/> <path d="M21 5a4 4 0 0 1-3.55 3.97"/> <path d="M22 13h-4"/> <path d="M3 21a4 4 0 0 1 3.81-4"/> <path d="M3 5a4 4 0 0 0 3.55 3.97"/> <path d="M6 13H2"/> <path d="m8 2 1.88 1.88"/> <path d="M9 7.13V6a3 3 0 1 1 6 0v1.13"/>',
            'spray-can': '<path d="M3 3h.01"/> <path d="M7 5h.01"/> <path d="M11 7h.01"/> <path d="M3 7h.01"/> <path d="M7 9h.01"/> <path d="M3 11h.01"/> <rect width="4" height="4" x="15" y="5"/> <path d="m19 9 2 2v10c0 .6-.4 1-1 1h-6c-.6 0-1-.4-1-1V11l2-2"/> <path d="m13 14 8-2"/> <path d="m13 19 8-2"/>',
            'shield-check': '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/> <path d="m9 12 2 2 4-4"/>',
            wheat: '<path d="M2 22 16 8"/> <path d="M3.47 12.53 5 11l1.53 1.53a3.5 3.5 0 0 1 0 4.94L5 19l-1.53-1.53a3.5 3.5 0 0 1 0-4.94Z"/> <path d="M7.47 8.53 9 7l1.53 1.53a3.5 3.5 0 0 1 0 4.94L9 15l-1.53-1.53a3.5 3.5 0 0 1 0-4.94Z"/> <path d="M11.47 4.53 13 3l1.53 1.53a3.5 3.5 0 0 1 0 4.94L13 11l-1.53-1.53a3.5 3.5 0 0 1 0-4.94Z"/> <path d="M20 2h2v2a4 4 0 0 1-4 4h-2V6a4 4 0 0 1 4-4Z"/> <path d="M11.47 17.47 13 19l-1.53 1.53a3.5 3.5 0 0 1-4.94 0L5 19l1.53-1.53a3.5 3.5 0 0 1 4.94 0Z"/> <path d="M15.47 13.47 17 15l-1.53 1.53a3.5 3.5 0 0 1-4.94 0L9 15l1.53-1.53a3.5 3.5 0 0 1 4.94 0Z"/> <path d="M19.47 9.47 21 11l-1.53 1.53a3.5 3.5 0 0 1-4.94 0L13 11l1.53-1.53a3.5 3.5 0 0 1 4.94 0Z"/>',
            calendar: '<path d="M8 2v3"/> <path d="M16 2v3"/> <rect x="3" y="3" width="18" height="18" rx="2"/> <path d="M3 9h18"/>',
            clock: '<circle cx="12" cy="12" r="10"/> <path d="M12 6v6l4 2"/>',
            check: '<path d="M20 6 9 17l-5-5"/>',
            'party-popper': '<path d="M5.8 11.3 2 22l10.7-3.79"/> <path d="M4 3h.01"/> <path d="M22 8h.01"/> <path d="M15 2h.01"/> <path d="M22 20h.01"/> <path d="m22 2-2.24.75a2.9 2.9 0 0 0-1.96 3.12c.1.86-.57 1.63-1.45 1.63h-.38c-.86 0-1.6.6-1.76 1.44L14 10"/> <path d="m22 13-.82-.33c-.86-.34-1.82.2-1.98 1.11c-.11.7-.72 1.22-1.43 1.22H17"/> <path d="m11 2 .33.82c.34.86-.2 1.82-1.11 1.98C9.52 4.9 9 5.52 9 6.23V7"/> <path d="M11 13c1.93 1.93 2.83 4.17 2 5-.83.83-3.07-.07-5-2-1.93-1.93-2.83-4.17-2-5 .83-.83 3.07.07 5 2Z"/>',
            'triangle-alert': '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/> <path d="M12 9v4"/> <path d="M12 17h.01"/>',
            history: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/> <path d="M3 3v5h5"/> <path d="M12 7v5l4 2"/>',
            star: '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>'
        };
        function svgIcon(name, extraClass) {
            return '<svg class="ic' + (extraClass ? ' ' + extraClass : '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[name] || '') + '</svg>';
        }

        // Step template as day-offsets from a cycle's start_date.
        // `icon` is a key in ICONS above. `color` drives the card accent + the Gantt bar.
        const STEP_TEMPLATE = [
            { icon: "tractor", title: "Land Preparation",  desc: "Prepare field and soil leveling.", startOffset: 0,  endOffset: 5,  color: "#4caf50", extra: "Remove weeds and ensure proper irrigation channels are clear." },
            { icon: "sprout", title: "Planting Seedlings", desc: "Transplant rice seedlings.",       startOffset: 6,  endOffset: 10, color: "#81c784", extra: "Best done early morning. Maintain 20cm spacing between rows." },
            { icon: "shovel", title: "Weeding",            desc: "Remove unwanted grass.",           startOffset: 11, endOffset: 15, color: "#8bc34a", extra: "Manual or herbicide method. Target weeds before they seed." },
            { icon: "leaf", title: "Growth Stage",       desc: "Vegetative growth phase.",         startOffset: 16, endOffset: 38, color: "#26a69a", extra: "Monitor water levels daily and check for nutrient deficiencies." },
            { icon: "flask-conical", title: "Fertilizer",         desc: "Apply nutrients.",                 startOffset: 23, endOffset: 26, color: "#b07d4b", extra: "Follow recommended NPK dosage. Apply during morning hours." },
            { icon: "bug", title: "Insecticide",        desc: "Prevent pest damage.",             startOffset: 28, endOffset: 30, color: "#ff8a65", extra: "Apply during low wind. Wear full protective gear." },
            { icon: "spray-can", title: "Spray Insecticide",  desc: "Liquid spray pest control.",       startOffset: 33, endOffset: 36, color: "#29b6f6", extra: "Use calibrated nozzle for even coverage. Avoid spraying near water." },
            { icon: "shield-check", title: "Spray Fungicide",    desc: "Prevent fungal disease.",          startOffset: 36, endOffset: 40, color: "#ab47bc", extra: "Apply during dry weather, early morning or late afternoon." },
            { icon: "wheat", title: "Harvest",            desc: "Collect mature rice crops.",       startOffset: 50, endOffset: 54, color: "#e0a526", extra: "Harvest when 80-85% of grains are golden. Use sharp sickle." }
        ];
        const TOTAL_DAYS = Math.max(...STEP_TEMPLATE.map(t => t.endOffset)) + 1;
        const MS_DAY = 86400000;
            
        function addDays(dateStr, days) {
            const [y, m, d] = dateStr.split('-').map(Number);
            const dt = new Date(y, m - 1, d);
            dt.setDate(dt.getDate() + days);
            return dt;
        }
        function buildStepsForCycle(cycle) {
            return STEP_TEMPLATE.map(t => ({
                ...t,
                start: addDays(cycle.start_date, t.startOffset),
                end: addDays(cycle.start_date, t.endOffset)
            }));
        }
        /* ===== CYCLE TABS ===== */
        function renderCycleTabs() {
            const wrap = document.getElementById('cycleTabs');
            const activeCycles = ALL_CYCLES.filter(c => c.status === 'active');
            if (!activeCycles.length) {
                wrap.innerHTML = '';
                return;
            }
            wrap.innerHTML = activeCycles.map(c => {
                const isActive = selectedCycle && +selectedCycle.id === +c.id;
                return `<button class="cycle-tab${isActive ? ' active' : ''}" data-id="${c.id}"><span class="cycle-tab-dot"></span>${escapeHtml(c.field_location)}</button>`;
            }).join('');
            wrap.querySelectorAll('.cycle-tab').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = parseInt(btn.dataset.id, 10);
                    window.location.href = 'process.php?cycle=' + id;
                });
            });
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
        /* ===== NEW CYCLE MODAL ===== */
        function openNewCycleModal() {
            const sel = document.getElementById('newCycleFieldSelect');
            sel.innerHTML = '<option value="">Select a field…</option>' +
                KNOWN_FIELDS.map(f => `<option value="${escapeHtml(f)}">${escapeHtml(f)}</option>`).join('') +
                '<option value="__custom__">+ Enter a new field name…</option>';
            document.getElementById('newCycleFieldCustom').style.display = 'none';
            document.getElementById('newCycleFieldCustom').value = '';
            document.getElementById('newCycleCropType').value = '';
            document.getElementById('newCycleStartDate').value = new Date().toISOString().slice(0, 10);
            document.getElementById('newCycleError').classList.remove('show');
            document.getElementById('newCycleModal').classList.add('show');
        }
        function closeNewCycleModal() {
            document.getElementById('newCycleModal').classList.remove('show');
        }
        document.getElementById('openNewCycleBtn').addEventListener('click', openNewCycleModal);
        document.getElementById('openNewCycleBtnEmpty').addEventListener('click', openNewCycleModal);
        document.getElementById('newCycleFieldSelect').addEventListener('change', function() {
            document.getElementById('newCycleFieldCustom').style.display = this.value === '__custom__' ? 'block' : 'none';
        });
        window.addEventListener('click', e => {
            if (e.target.classList.contains('modal')) e.target.classList.remove('show');
        });
        function submitNewCycle() {
            const errEl = document.getElementById('newCycleError');
            errEl.classList.remove('show');
            const sel = document.getElementById('newCycleFieldSelect').value;
            const custom = document.getElementById('newCycleFieldCustom').value.trim();
            const field = sel === '__custom__' ? custom : sel;
            const cropType = document.getElementById('newCycleCropType').value.trim();
            const startDate = document.getElementById('newCycleStartDate').value;
            if (!field) {
                errEl.textContent = 'Please select or enter a field.';
                errEl.classList.add('show');
                return;
            }
            if (!startDate) {
                errEl.textContent = 'Please choose a start date.';
                errEl.classList.add('show');
                return;
            }
            const btn = document.getElementById('newCycleConfirmBtn');
            btn.disabled = true;
            btn.textContent = 'Starting…';
            fetch('process_cycle_add.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    field_location: field,
                    crop_type: cropType,
                    start_date: startDate
                })
            }).then(res => res.json()).then(json => {
                btn.disabled = false;
                btn.textContent = 'Start Process';
                if (!json.success) {
                    errEl.textContent = json.message || 'Failed to start process.';
                    errEl.classList.add('show');
                    return;
                }
                window.location.href = 'process.php?cycle=' + json.id;
            }).catch(() => {
                btn.disabled = false;
                btn.textContent = 'Start Process';
                errEl.textContent = 'Network error — please try again.';
                errEl.classList.add('show');
            });
        }
                /* ===== RENDER SELECTED CYCLE ===== */
        const CHECK_SVG = svgIcon('check');
        function badgeInner(kind) {
            if (kind === 'manual') return svgIcon('check') + 'Marked complete';
            if (kind === 'done') return svgIcon('check') + 'Completed';
            if (kind === 'ongoing') return '<span class="live-dot"></span>In progress';
            return 'Upcoming';
        }
        const startOfDay = d => new Date(d.getFullYear(), d.getMonth(), d.getDate());
        const dayDiff = (a, b) => Math.round((startOfDay(b) - startOfDay(a)) / MS_DAY);
        const fmtDate = (d, withYear) => d.toLocaleDateString('en-US', withYear ?
            { month: 'short', day: 'numeric', year: 'numeric' } : { month: 'short', day: 'numeric' });
        const plural = (n, w) => n + ' ' + w + (n === 1 ? '' : 's');

        function renderCycleView() {
            renderCycleTabs();
            if (!selectedCycle) {
                document.getElementById('emptyState').style.display = 'block';
                document.getElementById('cycleView').style.display = 'none';
                return;
            }
            document.getElementById('emptyState').style.display = 'none';
            document.getElementById('cycleView').style.display = 'block';

            const isCompletedCycle = selectedCycle.status === 'completed';
            const steps = buildStepsForCycle(selectedCycle);
            const now = new Date();
            const today = startOfDay(now);
            const plantStart = steps[0].start;
            const plantEnd = steps[steps.length - 1].end;
            const plantEndExclusive = new Date(plantEnd.getFullYear(), plantEnd.getMonth(), plantEnd.getDate() + 1);
            const dayIdx = dayDiff(plantStart, today); // 0 = first day of the cycle

            // A step's last day counts as still in progress (dates are inclusive).
            function getStatus(s, e, idx) {
                if (manuallyDone.includes(idx)) return 'ManualDone';
                if (isCompletedCycle) return 'Done';
                if (today < s) return 'Upcoming';
                if (today > e) return 'Done';
                return 'Ongoing';
            }
            const statusList = () => steps.map((s, i) => getStatus(s.start, s.end, i));

            /* ---------- hero ---------- */
            document.getElementById('progressTitle').textContent = 'Planting progress';
            document.getElementById('progressFieldSub').textContent =
                selectedCycle.field_location + (selectedCycle.crop_type ? ' · ' + selectedCycle.crop_type : '') +
                (isCompletedCycle ? ' · Completed cycle (read-only)' : '');

            function recalcStats() {
                let done = 0, active = 0, upcoming = 0;
                statusList().forEach(st => {
                    if (st === 'Done' || st === 'ManualDone') done++;
                    else if (st === 'Ongoing') active++;
                    else upcoming++;
                });
                document.getElementById('statDone').textContent = done;
                document.getElementById('statActive').textContent = active;
                document.getElementById('statUpcoming').textContent = upcoming;
            }

            function renderFocus() {
                const el = document.getElementById('heroFocus');
                const st = statusList();
                const finished = st.every(x => x === 'Done' || x === 'ManualDone');
                if (isCompletedCycle || finished) {
                    el.innerHTML = '<strong>Status</strong><div class="hf-list"><span class="hf-item">' + svgIcon('check') + 'All steps are complete</span></div>';
                    return;
                }
                const nowSteps = steps.filter((s, i) => st[i] === 'Ongoing');
                if (nowSteps.length) {
                    el.innerHTML = '<strong>Working on now</strong><div class="hf-list">' +
                        nowSteps.map(s => '<span class="hf-item">' + svgIcon(s.icon) + escapeHtml(s.title) + '</span>').join('') + '</div>';
                    return;
                }
                const nextIdx = st.findIndex(x => x === 'Upcoming');
                el.innerHTML = nextIdx > -1 ?
                    '<strong>Up next</strong><div class="hf-list"><span class="hf-item">' + svgIcon(steps[nextIdx].icon) + escapeHtml(steps[nextIdx].title) +
                    '</span><span class="hf-when">starts ' + fmtDate(steps[nextIdx].start) + '</span></div>' :
                    '<strong>Status</strong>Waiting for remaining steps to be marked complete.';
            }

            let dayText;
            if (isCompletedCycle) dayText = 'Cycle finished';
            else if (dayIdx < 0) dayText = 'Starts in ' + plural(-dayIdx, 'day');
            else if (dayIdx >= TOTAL_DAYS) dayText = 'Past the planned finish date';
            else dayText = 'Day ' + (dayIdx + 1) + ' of ' + TOTAL_DAYS;
            document.getElementById('heroDay').textContent = dayText;

            document.getElementById('startDate').textContent = fmtDate(plantStart, true);
            document.getElementById('finishDate').textContent = fmtDate(plantEnd, true);
            const pct = isCompletedCycle ? 100 :
                Math.round(Math.max(0, Math.min(100, ((now - plantStart) / (plantEndExclusive - plantStart)) * 100)));
            setTimeout(() => { document.getElementById('progressBar').style.width = pct + '%'; }, 200);
            document.getElementById('progressPct').textContent = pct + '%';
            const statusEl = document.getElementById('progressStatus');
            if (isCompletedCycle || now >= plantEndExclusive) {
                statusEl.textContent = 'Completed';
                statusEl.className = 'progress-status status-completed';
            } else if (now < plantStart) {
                statusEl.textContent = 'Upcoming';
                statusEl.className = 'progress-status status-upcoming';
            } else {
                statusEl.textContent = 'Ongoing';
                statusEl.className = 'progress-status status-ongoing';
            }

            /* ---------- card helpers ---------- */
            const prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            function setOpen(card, open) {
                card.classList.toggle('open', open);
                const f = card.querySelector('.sc-foot');
                if (f) f.setAttribute('aria-expanded', open ? 'true' : 'false');
            }

            function focusCard(i) {
                const card = document.getElementById('step-card-' + i);
                if (!card) return;
                setOpen(card, true);
                card.scrollIntoView({ behavior: prefersReduced ? 'auto' : 'smooth', block: 'center' });
                card.classList.remove('highlight');
                void card.offsetWidth; // restart the ping animation
                card.classList.add('highlight');
            }

            /* ---------- season schedule (Gantt) ---------- */
            function renderGantt() {
                const inner = document.getElementById('ganttInner');
                const st = statusList();
                let weeks = '';
                for (let w = 0; w * 7 < TOTAL_DAYS; w++) {
                    weeks += '<div class="g-week" style="left:' + (w * 7 / TOTAL_DAYS * 100).toFixed(3) + '%">' +
                        fmtDate(addDays(selectedCycle.start_date, w * 7)) + '</div>';
                }
                const rows = steps.map((s, i) => {
                    const cls = st[i] === 'Ongoing' ? 'ongoing' : st[i] === 'Upcoming' ? 'upcoming' : 'done';
                    const len = s.endOffset - s.startOffset + 1;
                    const tip = s.title + ': ' + fmtDate(s.start) + ' to ' + fmtDate(s.end) + ' (' + plural(len, 'day') + ')';
                    return '<div class="g-row" data-idx="' + i + '" tabindex="0" role="button" title="' + escapeHtml(tip) + '" aria-label="' + escapeHtml(tip) + '">' +
                        '<div class="g-label"><span class="g-num">' + (i + 1) + '</span><span class="g-name">' + svgIcon(s.icon) + '<span class="g-text">' + escapeHtml(s.title) + '</span></span></div>' +
                        '<div class="g-track"><div class="g-bar ' + cls + '" style="left:' + (s.startOffset / TOTAL_DAYS * 100).toFixed(3) + '%;width:' + (len / TOTAL_DAYS * 100).toFixed(3) + '%;--c:' + s.color + '">' +
                        (cls === 'done' ? svgIcon('check') : '') + '</div></div></div>';
                }).join('');
                let overlay = '';
                if (!isCompletedCycle && dayIdx >= 0 && dayIdx < TOTAL_DAYS) {
                    const x = (dayIdx + (now - today) / MS_DAY) / TOTAL_DAYS * 100;
                    overlay = '<div class="g-overlay"><div class="g-today" style="left:' + x.toFixed(3) + '%"></div></div>';
                }
                inner.style.setProperty('--wk', (7 / TOTAL_DAYS * 100).toFixed(3) + '%');
                inner.innerHTML = '<div class="g-weeks">' + weeks + '</div>' + rows + overlay;
                inner.querySelectorAll('.g-row').forEach(r => {
                    const go = () => focusCard(+r.dataset.idx);
                    r.addEventListener('click', go);
                    r.addEventListener('keydown', e => {
                        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); }
                    });
                });
            }

            /* ---------- mark a step complete ---------- */
            function markStepComplete(idx, card, e) {
                e.stopPropagation();
                if (manuallyDone.includes(idx) || isCompletedCycle) return;
                const btn = card.querySelector('.mark-complete-btn');
                const badgeEl = card.querySelector('.step-badge');
                const resetBtn = () => {
                    if (!btn) return;
                    btn.disabled = false;
                    btn.innerHTML = CHECK_SVG + ' Mark as completed';
                };
                if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
                fetch('process_mark_complete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ cycle_id: selectedCycle.id, step_index: idx })
                }).then(res => res.json()).then(json => {
                    if (!json.success) {
                        resetBtn();
                        showToast('triangle-alert', json.message || 'Could not save this step. Please try again.');
                        return;
                    }
                    manuallyDone.push(idx);
                    card.classList.remove('upcoming', 'ongoing');
                    card.classList.add('manual', 'flash-complete');
                    setTimeout(() => card.classList.remove('flash-complete'), 700);
                    badgeEl.className = 'step-badge badge-manually-done';
                    badgeEl.innerHTML = badgeInner('manual');
                    if (btn) {
                        btn.classList.add('already-done');
                        btn.disabled = false;
                        btn.innerHTML = CHECK_SVG + ' Already completed';
                    }
                    recalcStats();
                    renderFocus();
                    renderGantt();
                    if (json.cycle_completed) {
                        selectedCycle.status = 'completed';
                        showToast('party-popper', selectedCycle.field_location + "'s planting cycle is complete!");
                        setTimeout(() => renderCycleView(), 900);
                    }
                }).catch(() => {
                    resetBtn();
                    showToast('triangle-alert', 'Network error. Please try again.');
                });
            }

            /* ---------- step cards ---------- */
            document.getElementById('stepsSub').textContent = isCompletedCycle ?
                'This cycle is finished, so steps are read-only.' :
                'Tap a card for tips and to mark the step complete.';
            const grid = document.getElementById('stepsGrid');
            grid.innerHTML = '';
            const st0 = statusList();
            steps.forEach((step, i) => {
                const status = st0[i];
                const isManual = status === 'ManualDone';
                const isDone = status === 'Done' || isManual;
                const isOngoing = status === 'Ongoing';
                const startsOpen = isOngoing && !isCompletedCycle;
                const len = step.endOffset - step.startOffset + 1;

                const card = document.createElement('article');
                card.id = 'step-card-' + i;
                card.className = 'step-card ' + (isManual ? 'manual' : isDone ? 'done' : isOngoing ? 'ongoing' : 'upcoming') + (startsOpen ? ' open' : '');
                card.style.setProperty('--c', step.color);

                const badgeCls = isManual ? 'badge-manually-done' : isDone ? 'badge-done' : isOngoing ? 'badge-ongoing' : 'badge-upcoming';
                const badgeKind = isManual ? 'manual' : isDone ? 'done' : isOngoing ? 'ongoing' : 'upcoming';

                card.innerHTML =
                    '<div class="sc-top"><span class="sc-num">Step ' + (i + 1) + ' of ' + steps.length + '</span>' +
                    '<span class="step-badge ' + badgeCls + '">' + badgeInner(badgeKind) + '</span></div>' +
                    '<div class="sc-body"><div class="sc-icon" aria-hidden="true">' + svgIcon(step.icon) + '</div>' +
                    '<div><h3 class="sc-title">' + escapeHtml(step.title) + '</h3><p class="sc-desc">' + escapeHtml(step.desc) + '</p></div></div>' +
                    '<div class="sc-meta"><span class="sc-chip">' + svgIcon('calendar') + fmtDate(step.start) + ' – ' + fmtDate(step.end, true) + '</span>' +
                    '<span class="sc-chip">' + svgIcon('clock') + plural(len, 'day') + '</span></div>' +
                    '<div class="sc-more"><div class="sc-tip"><strong>Tip:</strong> ' + escapeHtml(step.extra) + '</div><div class="sc-actions"></div></div>' +
                    '<button type="button" class="sc-foot" aria-expanded="' + (startsOpen ? 'true' : 'false') + '">Details' +
                    (isCompletedCycle ? '' : ' &amp; actions') +
                    ' <svg class="sc-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m6 9 6 6 6-6"/></svg></button>';

                if (!isCompletedCycle) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'mark-complete-btn' + (isDone ? ' already-done' : '');
                    btn.innerHTML = CHECK_SVG + ' ' + (isDone ? 'Already completed' : 'Mark as completed');
                    if (!isDone) btn.addEventListener('click', e => markStepComplete(i, card, e));
                    card.querySelector('.sc-actions').appendChild(btn);
                } else {
                    card.querySelector('.sc-actions').remove();
                }
                card.addEventListener('click', () => setOpen(card, !card.classList.contains('open')));
                grid.appendChild(card);
            });

            recalcStats();
            renderFocus();
            renderGantt();
        }
        let toastTimer2;
        function showToast(icon, msg) {
            let t = document.getElementById('processToast');
            if (!t) {
                t = document.createElement('div');
                t.id = 'processToast';
                t.className = 'toast';
                t.innerHTML = '<span id="processToastIcon"></span><span id="processToastMsg"></span>';
                document.body.appendChild(t);
            }
            document.getElementById('processToastIcon').innerHTML = svgIcon(icon);
            document.getElementById('processToastMsg').textContent = msg;
            t.classList.add('show');
            clearTimeout(toastTimer2);
            toastTimer2 = setTimeout(() => t.classList.remove('show'), 3200);
        }
        renderCycleView();
    </script>
</body>
</html>