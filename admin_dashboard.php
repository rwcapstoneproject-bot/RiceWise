<?php
require_once 'config.php';
if (!isset($_SESSION['rw_user_id'])) {
    header('Location: index.php');
    exit;
}
if ($_SESSION['rw_user_role'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

$aid = (int)$_SESSION['rw_user_id'];

$stmt = $conn->prepare("SELECT id, name, email FROM users WHERE id=? LIMIT 1");
$stmt->bind_param('i', $aid);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$admin) {
    header('Location: logout.php');
    exit;
}
$_SESSION['rw_user_name'] = $admin['name'];

// ── Platform-wide stats (across ALL non-admin users) ──
$rc = $conn->query("SELECT COUNT(*) AS c FROM farm_records");
$totalRecords = (int)$rc->fetch_assoc()['c'];

$cc = $conn->query("SELECT COUNT(*) AS c FROM farm_records WHERE status='Completed'");
$completedRecords = (int)$cc->fetch_assoc()['c'];

// NOTE: the original mock UI called this "Ongoing" but the real farm_records
// status ENUM is Completed / In Progress / Pending — no "Delayed" state
// exists in the schema, so that 4th mock status is dropped.
$oc = $conn->query("SELECT COUNT(*) AS c FROM farm_records WHERE status='In Progress'");
$ongoingRecords = (int)$oc->fetch_assoc()['c'];

// "Total Area (ha)" in the mock summed a `record.area` field that doesn't
// exist in farm_records. Swapped for a real, more meaningful platform
// metric: total registered farm size across all farmer accounts.
$ha = $conn->query("SELECT COALESCE(SUM(hectares),0) AS total_ha, COUNT(*) AS farmer_count FROM users WHERE role='user'");
$haRow = $ha->fetch_assoc();
$totalHectares = round((float)$haRow['total_ha'], 1);
$farmerCount = (int)$haRow['farmer_count'];

// ── Top Harvesters (real, Completed-only Harvesting sums, matches the
//    Completed-only convention already established in reports.php) ──
$th = $conn->query(
    "SELECT u.name AS farmer, SUM(fr.quantity) AS kg
     FROM farm_records fr
     JOIN users u ON u.id = fr.user_id
     WHERE fr.activity='Harvesting' AND fr.status='Completed'
     GROUP BY fr.user_id, u.name
     ORDER BY kg DESC LIMIT 5"
);
$topHarvesters = [];
$harvestColors = ['#5a7a52', '#8aab82', '#c8963e', '#b5cca8', '#94a18e'];
$i = 0;
while ($row = $th->fetch_assoc()) {
    $topHarvesters[] = ['farmer' => $row['farmer'], 'kg' => round((float)$row['kg']), 'fill' => $harvestColors[$i % count($harvestColors)]];
    $i++;
}

// ── Quarterly Profit / Top Farmers by Net Profit (real Sales-Expenses
//    methodology, same definition already validated in reports.php —
//    NOT the tabled static-formula profit modal issue) ──
$pf = $conn->query(
    "SELECT u.id, u.name AS farmer,
        COALESCE(SUM(CASE WHEN fr.activity='Sales' AND fr.status='Completed' THEN fr.quantity ELSE 0 END),0) AS revenue,
        COALESCE(SUM(CASE WHEN fr.activity IN ('Planting','Fertilizing','Irrigation','Pest Control') AND fr.status IN ('Completed','In Progress') THEN fr.quantity ELSE 0 END),0) AS expenses
     FROM users u
     LEFT JOIN farm_records fr ON fr.user_id = u.id
     WHERE u.role='user'
     GROUP BY u.id, u.name
     HAVING revenue > 0 OR expenses > 0
     ORDER BY
        (COALESCE(SUM(CASE WHEN fr.activity='Sales' AND fr.status='Completed' THEN fr.quantity ELSE 0 END),0)
         - COALESCE(SUM(CASE WHEN fr.activity IN ('Planting','Fertilizing','Irrigation','Pest Control') AND fr.status IN ('Completed','In Progress') THEN fr.quantity ELSE 0 END),0)) DESC
     LIMIT 5"
);
$topProfitFarmers = [];
while ($row = $pf->fetch_assoc()) {
    $topProfitFarmers[] = ['farmer' => $row['farmer'], 'profit' => round((float)$row['revenue'] - (float)$row['expenses'])];
}

// ── Activity Share (real, all statuses, all users) ──
$pie = $conn->query("SELECT activity, COUNT(*) AS c FROM farm_records GROUP BY activity ORDER BY c DESC");
$pieColors = ['Planting' => '#5a7a52', 'Fertilizing' => '#8aab82', 'Irrigation' => '#4a90d9', 'Pest Control' => '#c8963e', 'Harvesting' => '#c0392b', 'Sales' => '#ddd8cf'];
$pieData = [];
while ($row = $pie->fetch_assoc()) {
    $pieData[] = ['name' => $row['activity'], 'value' => (int)$row['c'], 'fill' => $pieColors[$row['activity']] ?? '#94a18e'];
}

// ── Latest Records across all farmers ──
$lr = $conn->query(
    "SELECT fr.activity, fr.location, fr.status, fr.record_date, u.name AS farmer
     FROM farm_records fr JOIN users u ON u.id = fr.user_id
     ORDER BY fr.record_date DESC, fr.id DESC LIMIT 8"
);
$latestRecords = $lr->fetch_all(MYSQLI_ASSOC);

$jsTopHarvesters = json_encode($topHarvesters);
$jsTopProfitFarmers = json_encode($topProfitFarmers);
$jsPieData = json_encode($pieData);
$jsLatestRecords = json_encode($latestRecords);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RiceWise Admin — Dashboard</title>
	<link rel="icon" type="image/png" href="pictures/RiceWise_Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Lora:wght@400;600;700&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&family=DM+Mono:wght@400;500&display=swap"
        rel="stylesheet">
    <script>
    if (window.innerWidth > 768 && localStorage.getItem('rw_sidebar_collapsed') === 'true') {
        document.documentElement.classList.add('sidebar-pre-collapse');
    }
        </script>
        <style>
            html.sidebar-pre-collapse body { overflow: hidden; }
            html.sidebar-pre-collapse .sidebar { width: var(--sidebar-col) !important; transition: none !important }
            html.sidebar-pre-collapse .logo-txt,
            html.sidebar-pre-collapse .abadge-txt,
            html.sidebar-pre-collapse .nl,
            html.sidebar-pre-collapse .ni-txt,
            html.sidebar-pre-collapse .lout-txt { display: none !important }
            html.sidebar-pre-collapse .s-top { padding: 16px 0 !important; justify-content: center !important }
            html.sidebar-pre-collapse .abadge { justify-content: center !important; padding: 8px 0 !important; margin: 8px auto !important; width: 48px !important }
            html.sidebar-pre-collapse .nav-sec { padding: 8px 0 !important; align-items: center !important; display: flex !important; flex-direction: column !important; gap: 2px !important }
            html.sidebar-pre-collapse .ni { width: 44px !important; height: 44px !important; justify-content: center !important; padding: 0 !important }
            html.sidebar-pre-collapse .s-bot { justify-content: center !important; padding: 10px 0 16px !important }
            html.sidebar-pre-collapse .lout { width: 44px !important; height: 44px !important; padding: 0 !important; justify-content: center !important; gap: 0 !important }
            html.sidebar-pre-collapse .tog { display: none !important }
            html.sidebar-pre-collapse .logo-icon { display: none !important }
                    html.sidebar-pre-collapse .logo-tog { display: flex !important }
        </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0
        }

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
            --gold-border: #e8d9b0;
            --red: #c0392b;
            --red-pale: #fdecea;
            --red-border: #e8b4b0;
            --blue: #4a90d9;
            --blue-pale: #eaf3fc;
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
            --sidebar-col: 68px;
            --header-h: 64px;
        }

        html {
            font-size: 15px
        }

        html,
        body {
            height: 100%;
            font-family: 'DM Sans', sans-serif;
            background: var(--cream);
            color: var(--text-primary)
        }

        body {
            display: flex;
            height: 100vh;
            overflow: hidden
        }

        ::-webkit-scrollbar {
            width: 4px;
            height: 4px
        }

        ::-webkit-scrollbar-thumb {
            background: var(--cream-3);
            border-radius: 2px
        }

        .sidebar {
            width: var(--sidebar-w);
            flex-shrink: 0;
            background: var(--white);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            transition: width .3s cubic-bezier(.4, 0, .2, 1);
            overflow: hidden;
            height: 100%;
            z-index: 300
        }

        body.sc .sidebar {
            width: var(--sidebar-col)
        }

        .s-top {
            height: var(--header-h);
            padding: 0 16px 0 8px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            flex-shrink: 0;
            transition: padding .3s cubic-bezier(.4,0,.2,1)
        }

        body.sc .s-top {
            padding: 16px 0;
            justify-content: center
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 6px;
            flex: 1;
            min-width: 0;
            cursor: pointer
        }

        body.sc .logo {
            justify-content: center;
            flex: none
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
            overflow: hidden;
            transition: transform .2s
        }

        .logo-icon img {
            width: 34px;
            height: 34px;
            object-fit: contain
        }

        .logo-tog {
            display: none;
            width: 44px;
            height: 44px;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            transition: background .2s
        }

        .logo-tog svg {
            width: 20px;
            height: 20px;
            stroke: var(--text-secondary);
            transition: stroke .2s
        }

        .logo-tog:hover {
            background: var(--sage-pale)
        }

        .logo-tog:hover svg {
            stroke: var(--sage-dark)
        }

        body.sc .logo-icon {
            display: none
        }

        body.sc .logo-tog {
            display: flex
        }

        .logo-txt {
            font-family: 'Lora', serif;
            font-size: 19px;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -.4px;
            white-space: nowrap
        }

        .tog {
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

        .tog:hover {
            background: var(--sage-pale)
        }

        .tog svg {
            width: 18px;
            height: 18px;
            stroke: var(--text-muted)
        }

        .abadge {
            margin: 8px 12px 0;
            padding: 7px 12px;
            border-radius: 10px;
            background: var(--gold-pale);
            border: 1px solid var(--gold-border);
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            color: var(--gold);
            white-space: nowrap;
            overflow: hidden;
            flex-shrink: 0
        }

        .abadge svg {
            width: 12px;
            height: 12px;
            flex-shrink: 0
        }

        .nav-sec {
            padding: 8px 12px;
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden
        }

        .nl {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: var(--text-muted);
            padding: 0 8px;
            margin: 14px 0 3px;
            white-space: nowrap
        }

        .nl:first-child {
            margin-top: 6px
        }

        .ni {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 10px;
            color: var(--text-secondary);
            border: none;
            background: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            font-weight: 500;
            position: relative;
            white-space: nowrap;
            overflow: hidden;
            width: 100%;
            text-align: left;
            transition: all .18s;
            margin-bottom: 2px
        }

        .ni:hover {
            background: #fcfcfb;
            box-shadow: inset 0 0 0 1px var(--sage-pale);
            color: var(--sage-dark);
            font-weight: 600
        }

        .ni.active {
            background: var(--sage-pale);
            color: var(--sage-dark);
            font-weight: 600
        }
            
        
        .ni.active:hover {
            background: var(--sage-pale);
            box-shadow: inset 0 0 0 1px var(--sage-light);
            color: var(--sage-dark)
        }


        .ni svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0
        }
            
        .ni svg .icon-filled {
            display: none;
        }
        .ni.active svg .icon-outline {
            display: none;
        }
        .ni.active svg .icon-filled {
            display: block;
        }

        .s-bot {
            padding: 10px 12px 16px;
            border-top: 1px solid var(--border);
            flex-shrink: 0;
            display: flex;
            align-items: center
        }

        .lout {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 9px;
            width: 100%;
            padding: 10px 13px;
            background: transparent;
            color: var(--text-secondary);
            border: none;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            white-space: nowrap;
            transition: all .18s
        }

        .lout:hover {
            background: #fcfcfb;
            box-shadow: inset 0 0 0 1px var(--sage-pale);
            color: var(--sage-dark);
            font-weight: 600
        }

        .lout svg {
            width: 15px;
            height: 15px;
            flex-shrink: 0
        }

        .logo-txt,
        .ni-txt,
        .abadge-txt,
        .lout-txt {
            opacity: 1;
            transform: translateX(0);
            max-width: 300px;
            overflow: hidden;
            transition: opacity .22s ease, transform .22s ease, max-width .22s ease;
        }

        .nl {
            opacity: 1;
            transform: translateX(0);
            max-height: 20px;
            transition: opacity .22s ease, transform .22s ease, max-height .22s ease, margin .22s ease;
        }

        body.sc .logo-txt,
        body.sc .ni-txt,
        body.sc .abadge-txt,
        body.sc .lout-txt {
            opacity: 0;
            transform: translateX(10px);
            max-width: 0;
            pointer-events: none;
            transition: opacity .18s ease, transform .18s ease, max-width .18s ease;
        }

        body.sc .nl {
            opacity: 0;
            transform: translateX(10px);
            max-height: 0;
            margin-top: 0;
            margin-bottom: 0;
            pointer-events: none;
            transition: opacity .18s ease, transform .18s ease, max-height .18s ease, margin .18s ease;
        }

        /* Tooltips on collapsed nav items */
        .ni { position: relative; }
        body.sc .ni::after {
            content: attr(data-tooltip);
            position: absolute;
            left: calc(100% + 12px);
            top: 50%;
            transform: translateY(-50%);
            background: var(--text-primary);
            color: #fff;
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
        body.sc .ni:hover::after {
            opacity: 1;
        }
        body.sc .abadge {
            justify-content: center;
            padding: 8px 0;
            margin: 8px auto;
            width: 48px
        }

        body.sc .nav-sec {
            padding: 8px 0;
            align-items: center;
            display: flex;
            flex-direction: column;
            gap: 2px
        }

        body.sc .ni {
            width: 44px;
            height: 44px;
            justify-content: center;
            padding: 0;
            gap: 0
        }

        body.sc .s-bot {
            justify-content: center;
            padding: 10px 0 16px
        }

        body.sc .lout {
            width: 44px;
            height: 44px;
            justify-content: center;
            gap: 0
        }

        body.sc .tog {
            display: none
        }

        .main {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            height: 100vh
        }

        .hdr {
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

        .hdr-l {
            display: flex;
            align-items: center;
            gap: 12px;
            font-family: 'Lora', serif;
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .hdr-r {
            display: flex;
            align-items: center;
            gap: 6px
        }

        .hdr-sep {
            width: 1px;
            height: 24px;
            background: var(--border);
            margin: 0 4px
        }

        .hbtn {
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
            position: relative
        }

        .hbtn:hover {
            background: var(--sage-pale)
        }

        .hbtn svg {
            stroke: var(--text-secondary);
            fill: none
        }

        .ndot {
            position: absolute;
            top: 7px;
            right: 7px;
            width: 8px;
            height: 8px;
            background: var(--red);
            border-radius: 50%;
            border: 2px solid var(--cream);
            display: none
        }

        .ndot.on {
            display: block
        }

        .ppill {
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

        .ppill:hover {
            border-color: var(--sage-light)
        }

        .ppill-name {
            font-size: 14px;
            color: var(--text-secondary);
            font-weight: 500
        }

        .pav {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--sage-pale);
            border: 1.5px solid var(--sage-light);
            display: flex;
            align-items: center;
            justify-content: center
        }

        .pav svg {
            width: 15px;
            height: 15px;
            stroke: var(--sage)
        }

        .darr {
            width: 12px;
            height: 12px;
            stroke: var(--text-muted);
            transition: transform .3s
        }

        .ppill.op .darr {
            transform: rotate(180deg)
        }

        .ndrop {
            position: fixed;
            top: calc(var(--header-h) + 6px);
            right: 180px;
            width: 320px;
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            z-index: 50000;
            display: none;
            overflow: hidden
        }

        .ndrop.show {
            display: block
        }

        .ndh {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-light)
        }

        .ndh-t {
            font-family: 'Lora', serif;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .ndh-btn {
            background: none;
            border: none;
            color: var(--sage);
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit
        }

        .ndl {
            max-height: 280px;
            overflow-y: auto
        }

        .ndi {
            display: flex;
            gap: 10px;
            padding: 10px 14px;
            border-bottom: 1px solid var(--border-light);
            cursor: pointer;
            transition: background .15s
        }

        .ndi.ur {
            background: var(--sage-pale)
        }

        .ndi:hover {
            background: var(--cream-2)
        }

        .ndi-ic {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: var(--cream-2);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0
        }

        .ndi-ic svg {
            width: 14px;
            height: 14px
        }

        .ndi-t {
            font-weight: 600;
            font-size: 13px;
            color: var(--text-primary)
        }

        .ndi-d {
            font-size: 12px;
            color: var(--text-secondary)
        }

        .ndi-dt {
            font-size: 11px;
            color: var(--text-muted);
            font-family: 'DM Mono', monospace;
            margin-top: 1px
        }

        .nd-foot {
            text-align: center;
            padding: 10px;
            font-size: 13px;
            border-top: 1px solid var(--border-light)
        }

        .nd-foot a {
            color: var(--sage);
            text-decoration: none;
            font-weight: 600
        }

        .pdrop {
            position: fixed;
            top: calc(var(--header-h) + 6px);
            right: 20px;
            width: 210px;
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            z-index: 50000;
            padding: 14px;
            display: none
        }

        .pdrop.show {
            display: block
        }

        .pdrop h3 {
            font-family: 'Lora', serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 2px
        }

        .pdrop p {
            font-size: 12px;
            color: var(--text-muted)
        }

        .pdrop hr {
            border: none;
            border-top: 1px solid var(--border-light);
            margin: 8px 0
        }

        .pdbtn {
            display: block;
            width: 100%;
            text-align: left;
            background: none;
            border: none;
            padding: 7px 10px;
            font-size: 14px;
            color: var(--text-secondary);
            cursor: pointer;
            border-radius: 8px;
            transition: all .18s;
            font-family: inherit
        }

        .pdbtn:hover {
            background: var(--sage-pale);
            color: var(--sage-dark)
        }

        .pdbtn.danger {
            color: var(--red)
        }

        .pdbtn.danger:hover {
            background: var(--red-pale)
        }

        .s-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .38);
            z-index: 299;
            backdrop-filter: blur(3px)
        }

        .s-overlay.show {
            display: block
        }

        .hmbtn {
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

        .hmbtn:hover {
            background: var(--sage-pale)
        }

        .hmbtn svg {
            width: 18px;
            height: 18px;
            stroke: var(--text-secondary)
        }

        #toast {
            position: fixed;
            top: 18px;
            right: 18px;
            z-index: 99999;
            padding: 12px 18px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: var(--shadow-lg);
            border: 1px solid;
            transition: opacity .3s, transform .3s
        }

        #toast.hidden {
            opacity: 0;
            transform: translateY(-10px);
            pointer-events: none
        }

        #toast.ok {
            background: var(--sage-pale);
            color: var(--sage-dark);
            border-color: #a8c8a0
        }

        #toast svg {
            width: 15px;
            height: 15px;
            flex-shrink: 0
        }

        .pscroll {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 20px 24px 28px
        }

        .space-y {
            display: flex;
            flex-direction: column;
            gap: 16px
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px
        }

        .grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px
        }

        .stat-card {
            border-radius: var(--radius);
            padding: 20px;
            transition: transform .2s, box-shadow .2s
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md)
        }

        .stat-val {
            font-family: 'Lora', serif;
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 4px
        }

        .stat-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: var(--text-muted)
        }

        .stat-sub {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 4px
        }

        .chart-card {
            background: var(--white);
            border: 1px solid var(--border-light);
            border-radius: var(--radius);
            padding: 18px;
            box-shadow: var(--shadow-sm)
        }

        .chart-meta {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 12px
        }

        .chart-subtitle {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--text-muted);
            margin-bottom: 2px
        }

        .chart-title {
            font-family: 'Lora', serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .badge-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 99px;
            font-size: 12px;
            font-weight: 700
        }

        .badge-completed {
            background: var(--sage-pale);
            color: var(--sage-dark)
        }

        .badge-ongoing {
            background: var(--gold-pale);
            color: var(--gold)
        }

        .badge-pending {
            background: var(--cream-2);
            color: var(--text-muted)
        }

        .badge-delayed {
            background: var(--red-pale);
            color: var(--red)
        }

        .pie-legend {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px;
            margin-top: 8px
        }

        .pie-legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--text-secondary)
        }

        .pie-dot {
            width: 10px;
            height: 10px;
            border-radius: 2px;
            flex-shrink: 0
        }

        .record-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: 10px;
            background: var(--cream);
            margin-bottom: 6px;
            font-size: 14px
        }

        .record-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #8aab82;
            flex-shrink: 0
        }

        .record-activity {
            font-weight: 600;
            color: var(--text-primary)
        }

        .record-field {
            font-size: 13px;
            color: var(--text-muted);
            margin-left: 4px
        }

        .record-farmer {
            font-size: 13px;
            color: var(--text-muted)
        }

        @media(max-width:1200px) {
            .grid-4 {
                grid-template-columns: 1fr 1fr
            }
        }

        @media(max-width:1024px) {
            .hdr-l {
                font-size: 20px
            }
        }

        @media(max-width:768px) {

            html,
            body {
                height: auto;
                min-height: 100%;
                overflow: auto
            }

            body {
                display: block;
                overflow-x: hidden
            }

            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                height: 100%;
                width: var(--sidebar-w) !important;
                transform: translateX(-100%);
                box-shadow: var(--shadow-lg);
                transition: transform .3s cubic-bezier(.4, 0, .2, 1)
            }

            .sidebar.open {
                transform: translateX(0)
            }

            body.sc .logo-txt,
            body.sc .abadge-txt,
            body.sc .nl,
            body.sc .ni-txt,
            body.sc .lout-txt {
                display: inline !important
            }

            body.sc .s-top {
                padding: 18px 16px 14px !important;
                justify-content: flex-start !important
            }

            body.sc .logo {
                justify-content: flex-start !important;
                flex: 1 !important
            }

            body.sc .tog {
                display: flex !important
            }

            body.sc .nav-sec {
                padding: 8px 12px !important;
                align-items: stretch !important;
                flex-direction: column !important
            }

            body.sc .ni {
                width: auto !important;
                height: auto !important;
                justify-content: flex-start !important;
                padding: 10px !important
            }

            body.sc .abadge {
                margin: 8px 12px 0 !important;
                width: auto !important;
                justify-content: flex-start !important;
                padding: 7px 12px !important
            }

            body.sc .s-bot {
                padding: 10px 12px 16px !important
            }

            body.sc .lout {
                width: 100% !important;
                height: auto !important;
                padding: 10px 13px !important;
                gap: 9px !important
            }
                
            body.sc .logo-icon {
                    display: flex !important
                }

                body.sc .logo-tog {
                    display: none !important
                }

            .main {
                display: block;
                width: 100%;
                height: auto;
                overflow: visible
            }

            .hdr {
                padding: 0 16px
            }

            .hmbtn {
                display: flex
            }

            .ppill-name {
                display: none
            }

            .pscroll {
                overflow: visible;
                height: auto
            }

            .ndrop {
                right: 10px;
                left: 10px;
                width: auto
            }

            .pdrop {
                right: 10px
            }

            .grid-2,
            .grid-4 {
                grid-template-columns: 1fr
            }

            #bottom-grid {
                grid-template-columns: 1fr !important
            }
        }

        @media(max-width:480px) {
            .pscroll {
                padding: 12px
            }
        }

        /* ── ADDED styles ── */

        /* Chart bar/line toggle buttons */
        .chart-tog-wrap {
            display: flex;
            gap: 4px;
        }

        .chart-tog-btn {
            padding: 3px 10px;
            border-radius: 99px;
            border: 1px solid var(--border);
            background: none;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            transition: all .18s;
        }

        .chart-tog-btn.active {
            background: var(--sage);
            color: white;
            border-color: var(--sage);
        }

        /* "View Analysis" button on stat card */
        .stat-analysis-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 8px;
            padding: 4px 10px;
            background: var(--sage);
            color: white;
            border: none;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            transition: background .18s;
        }

        .stat-analysis-btn:hover {
            background: var(--sage-dark);
        }

        /* Net Profit Modal */
        .np-overlay {
            position: fixed;
            inset: 0;
            background: rgba(20, 30, 15, .52);
            backdrop-filter: blur(5px);
            z-index: 60000;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            transition: opacity .25s;
        }

        .np-overlay.show {
            opacity: 1;
            pointer-events: all;
        }

        .np-box {
            background: var(--white);
            border-radius: 20px;
            width: min(860px, 95vw);
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 24px 80px rgba(20, 40, 15, .32);
            transform: translateY(18px) scale(.97);
            transition: transform .32s cubic-bezier(.34, 1.46, .64, 1);
        }

        .np-overlay.show .np-box {
            transform: translateY(0) scale(1);
        }

        .np-head {
            background: linear-gradient(135deg, #3d5c38 0%, #5a7a52 55%, #6d9461 100%);
            padding: 20px 24px 18px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-shrink: 0;
            position: relative;
            overflow: hidden;
        }

        .np-head::after {
            content: '💰';
            position: absolute;
            right: 52px;
            top: 8px;
            font-size: 72px;
            opacity: .15;
            pointer-events: none;
        }

        .np-head-tag {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, .65);
            margin-bottom: 5px;
        }

        .np-head-title {
            font-family: 'Lora', serif;
            font-size: 24px;
            font-weight: 700;
            color: #fff;
        }

        .np-close {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .18);
            border: 1px solid rgba(255, 255, 255, .28);
            cursor: pointer;
            color: white;
            font-size: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .18s;
            flex-shrink: 0;
        }

        .np-close:hover {
            background: rgba(255, 255, 255, .32);
        }

        .np-body {
            overflow-y: auto;
        }

        .np-kpis {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            border-bottom: 1px solid var(--border-light);
        }

        .np-kpi {
            padding: 16px 18px;
            text-align: center;
            border-right: 1px solid var(--border-light);
        }

        .np-kpi:last-child {
            border-right: none;
        }

        .np-kpi-val {
            font-family: 'Lora', serif;
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 3px;
        }

        .np-kpi-val.c-green {
            color: var(--sage-dark);
        }

        .np-kpi-val.c-gold {
            color: var(--gold);
        }

        .np-kpi-val.c-red {
            color: var(--red);
        }

        .np-kpi-val.c-blue {
            color: var(--blue);
        }

        .np-kpi-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--text-muted);
        }

        .np-notice {
            margin: 12px 18px 0;
            padding: 9px 13px;
            border-radius: 9px;
            background: #fffbf0;
            border: 1px solid var(--gold-border);
            font-size: 12px;
            color: #7a5a1e;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .np-notice a {
            color: var(--gold);
            font-weight: 700;
            text-decoration: none;
        }

        .np-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            padding: 14px 18px 4px;
        }

        .np-col {
            padding-bottom: 14px;
        }

        .np-col+.np-col {
            padding-left: 18px;
            border-left: 1px solid var(--border-light);
        }

        .np-sec-title {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.3px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 9px;
        }

        .np-minis {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .np-mini {
            flex: 1;
            min-width: 80px;
            background: var(--cream);
            border: 1px solid var(--border-light);
            border-radius: 9px;
            padding: 9px 11px;
        }

        .np-mini-lbl {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--text-muted);
        }

        .np-mini-val {
            font-family: 'DM Mono', monospace;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            margin-top: 1px;
        }

        .np-cost-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 10px;
            border-radius: 8px;
            margin-bottom: 5px;
            background: var(--cream);
            border-left: 3px solid transparent;
            transition: background .14s;
        }

        .np-cost-row:hover {
            background: var(--cream-2);
        }

        .np-cost-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .np-cost-sub {
            font-size: 11px;
            color: var(--text-muted);
        }

        .np-cost-val {
            font-family: 'DM Mono', monospace;
            font-size: 13px;
            font-weight: 700;
            color: var(--red);
        }

        .np-rev-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 7px 0;
            border-bottom: 1px solid var(--border-light);
            font-size: 13px;
        }

        .np-rev-row:last-child {
            border-bottom: none;
        }

        .np-rev-lbl {
            color: var(--text-secondary);
        }

        .np-rev-val {
            font-family: 'DM Mono', monospace;
            font-weight: 700;
        }

        .np-rev-val.g {
            color: var(--sage-dark);
        }

        .np-rev-val.r {
            color: var(--red);
        }

        .np-rev-val.b {
            color: var(--blue);
        }

        .np-insight {
            border-radius: 9px;
            padding: 12px 14px;
            margin-top: 10px;
        }

        .np-insight.good {
            background: var(--sage-pale);
            border: 1px solid #a8c8a0;
        }

        .np-insight.warn {
            background: var(--gold-pale);
            border: 1px solid var(--gold-border);
        }

        .np-insight-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--sage-dark);
            margin-bottom: 3px;
        }

        .np-insight-title.w {
            color: #7a5a1e;
        }

        .np-insight-body {
            font-size: 12px;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .np-foot {
            padding: 12px 18px;
            border-top: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            background: var(--cream);
        }

        .np-foot-note {
            font-size: 12px;
            color: var(--text-muted);
        }

        .np-foot-close {
            padding: 9px 26px;
            background: var(--sage-dark);
            color: white;
            border: none;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'DM Sans', sans-serif;
            transition: background .18s;
        }

        .np-foot-close:hover {
            background: #2a4228;
        }

        @media(max-width:640px) {
            .np-kpis {
                grid-template-columns: 1fr 1fr;
            }

            .np-grid {
                grid-template-columns: 1fr;
            }

            .np-col+.np-col {
                padding-left: 0;
                border-left: none;
                border-top: 1px solid var(--border-light);
                padding-top: 14px;
                margin-top: 4px;
            }

            .np-head-title {
                font-size: 19px;
            }
        }
    </style>
</head>

<body>
    <div id="toast" class="hidden"><svg id="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="2"></svg><span id="toast-msg"></span></div>
    <div class="s-overlay" id="sOverlay" onclick="closeSidebar()"></div>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
            <div class="s-top">
                <div class="logo" onclick="toggleSidebar()">
                    <div class="logo-icon"><img src="pictures/RiceWise_Logo.png" alt="RiceWise" onerror="this.style.display='none';this.parentElement.textContent='🌾'"></div>
                    <span class="logo-tog" title="Expand sidebar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                            <rect x="3" y="3" width="18" height="18" rx="2" />
                            <path d="M9 3v18" />
                        </svg>
                    </span>
                    <span class="logo-txt">RiceWise</span>
                </div>
                <button class="tog" onclick="toggleSidebar()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <rect x="3" y="3" width="18" height="18" rx="2" />
                        <path d="M9 3v18" />
                    </svg>
                </button>
            </div>
            <div class="abadge">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                </svg>
                <span class="abadge-txt">Admin Access</span>
            </div>
            <nav class="nav-sec">
                    <div class="nl">Navigation</div>
                    <button class="ni active" data-tooltip="Dashboard" onclick="location.href='admin_dashboard.php'"><svg viewBox="0 0 24 24">
                            <g class="icon-outline" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="7" height="7" /><rect x="14" y="3" width="7" height="7" /><rect x="14" y="14" width="7" height="7" /><rect x="3" y="14" width="7" height="7" />
                            </g>
                            <g class="icon-filled" fill="currentColor">
                                <rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" />
                            </g>
                        </svg><span class="ni-txt">Dashboard</span></button>

                    <button class="ni" data-tooltip="Records" onclick="location.href='admin_records.php'"><svg viewBox="0 0 24 24">
                            <g class="icon-outline" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="16" y1="13" x2="8" y2="13" /><line x1="16" y1="17" x2="8" y2="17" />
                            </g>
                            <g class="icon-filled">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="currentColor" /><path d="M14 2v6h6" fill="var(--sage-pale)" /><rect x="8" y="12.2" width="8" height="1.6" rx="0.8" fill="var(--white)" /><rect x="8" y="16.2" width="8" height="1.6" rx="0.8" fill="var(--white)" />
                            </g>
                        </svg><span class="ni-txt">Records</span></button>

                    <button class="ni" data-tooltip="Process Tracking" onclick="location.href='admin_process_tracking.php'"><svg viewBox="0 0 24 24">
                            <g class="icon-outline" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="6" y1="3" x2="6" y2="15" /><circle cx="18" cy="6" r="3" /><circle cx="6" cy="18" r="3" /><path d="M18 9a9 9 0 0 1-9 9" />
                            </g>
                            <g class="icon-filled">
                                <line x1="6" y1="3" x2="6" y2="15" stroke="currentColor" stroke-width="2.5" /><path d="M18 9a9 9 0 0 1-9 9" fill="none" stroke="currentColor" stroke-width="2.5" /><circle cx="18" cy="6" r="3.5" fill="currentColor" /><circle cx="6" cy="18" r="3.5" fill="currentColor" />
                            </g>
                        </svg><span class="ni-txt">Process Tracking</span></button>

                    <div class="nl">Insights</div>
                    <button class="ni" data-tooltip="Reports" onclick="location.href='admin_reports.php'"><svg viewBox="0 0 24 24">
                            <g class="icon-outline" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="20" x2="18" y2="10" /><line x1="12" y1="20" x2="12" y2="4" /><line x1="6" y1="20" x2="6" y2="14" />
                            </g>
                            <g class="icon-filled" fill="currentColor">
                                <rect x="16" y="10" width="4" height="10" rx="1" /><rect x="10" y="4" width="4" height="16" rx="1" /><rect x="4" y="14" width="4" height="6" rx="1" />
                            </g>
                        </svg><span class="ni-txt">Reports</span></button>

                    <div class="nl">Account</div>
                    <button class="ni" data-tooltip="User Management" onclick="location.href='admin_user_management.php'"><svg viewBox="0 0 24 24">
                            <g class="icon-outline" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M23 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" />
                            </g>
                            <g class="icon-filled">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2z" fill="currentColor" /><circle cx="9" cy="7" r="4" fill="currentColor" /><path d="M23 21v-2a4 4 0 0 0-3-3.87" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" /><path d="M16 3.13a4 4 0 0 1 0 7.75" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                            </g>
                        </svg><span class="ni-txt">User Management</span></button>

                    <button class="ni" data-tooltip="Notifications" onclick="location.href='admin_notifications.php'"><svg viewBox="0 0 24 24">
                            <g class="icon-outline" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M13.73 21a2 2 0 0 1-3.46 0" />
                            </g>
                            <g class="icon-filled">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9z" fill="currentColor" /><path d="M13.73 21a2 2 0 0 1-3.46 0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                            </g>
                        </svg><span class="ni-txt">Notifications</span></button>
                </nav>
            <div class="s-bot">
                <a class="lout" href="logout.php" style="text-decoration:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                        <polyline points="16 17 21 12 16 7" />
                        <line x1="21" y1="12" x2="9" y2="12" />
                    </svg><span class="lout-txt">Sign Out</span></a>
            </div>
        </aside>

    <div class="main">
        <header class="hdr">
            <div class="hdr-l">
                <button class="hmbtn" onclick="openSidebar()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2.2" stroke-linecap="round">
                        <line x1="3" y1="6" x2="21" y2="6" />
                        <line x1="3" y1="12" x2="21" y2="12" />
                        <line x1="3" y1="18" x2="21" y2="18" />
                    </svg></button>
                Dashboard
            </div>
            <div class="hdr-r">
                <button class="hbtn" id="notifBtn"><svg width="21" height="21" viewBox="0 0 24 24" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
                        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                    </svg>
                    <div class="ndot" id="ndot"></div>
                </button>
                <div class="hdr-sep"></div>
                <div class="ppill" id="ppillBtn">
                    <span class="ppill-name" id="hdrName"><?= htmlspecialchars($admin['name']) ?></span>
                    <div class="pav"><svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg></div>
                    <svg class="darr" viewBox="0 0 24 24" fill="none" stroke-width="2">
                        <polyline points="6 9 12 15 18 9" />
                    </svg>
                </div>
            </div>
        </header>

        <div class="ndrop" id="ndrop">
            <div class="ndh"><span class="ndh-t">Notifications</span><button class="ndh-btn"
                    onclick="markAllRead()">Mark all read</button></div>
            <div class="ndl" id="ndl"></div>
            <div class="nd-foot"><a href="admin_notifications.php">View all notifications</a></div>
        </div>
        <div class="pdrop" id="pdrop">
            <h3 id="pdropName"><?= htmlspecialchars($admin['name']) ?></h3>
            <p><?= htmlspecialchars($admin['email']) ?></p>
            <hr>
            <button class="pdbtn" onclick="location.href='admin_profile.php'">My Profile</button>
            <hr>
            <button class="pdbtn danger" onclick="location.href='logout.php'">Sign Out</button>
        </div>

        <div class="pscroll">
            <div class="space-y">
                <div class="grid-4" id="dash-stats"></div>
                <div class="grid-2">
                    <div class="chart-card">
                        <div class="chart-meta">
                            <div>
                                <div class="chart-subtitle">Real Farmer Data · Sales − Expenses</div>
                                <div class="chart-title">Top Farmers by Net Profit</div>
                            </div>
                            <div class="chart-tog-wrap">
                                <button class="chart-tog-btn active" onclick="switchTrendChart('bar',this)">Bar</button>
                                <button class="chart-tog-btn" onclick="switchTrendChart('line',this)">Line</button>
                            </div>
                        </div><canvas id="chart-yield-trend" height="160"></canvas>
                    </div>
                    <div class="chart-card">
                        <div class="chart-meta">
                            <div>
                                <div class="chart-subtitle">Top Harvesters (kg)</div>
                                <div class="chart-title">Users with Highest Harvest</div>
                            </div>
                        </div><canvas id="chart-yield-variety" height="160"></canvas>
                    </div>
                </div>
                <div class="grid-2" id="bottom-grid" style="grid-template-columns:1fr 2fr">
                    <div class="chart-card">
                        <div class="chart-meta">
                            <div>
                                <div class="chart-subtitle">Process distribution</div>
                                <div class="chart-title">Activity Share</div>
                            </div>
                        </div><canvas id="chart-pie" height="130" style="max-height:130px"></canvas>
                        <div class="pie-legend" id="pie-legend"></div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-title" style="margin-bottom:12px">Latest Records</div>
                        <div id="dash-latest-records"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!--
      NOTE: This Net Profit modal still uses the same disconnected static
      PSA/PhilRice per-hectare formula (assumes exactly 1 hectare, fixed
      cost list) as dashboard.php's profit card — this is the third copy
      of that same tabled issue, left untouched intentionally so it can be
      fixed consistently across all three places in one pass later.
    -->
    <div class="np-overlay" id="npOverlay" onclick="npCloseOnBg(event)">
        <div class="np-box">
            <div class="np-head">
                <div>
                    <div class="np-head-tag">Farm Profit Analysis · Estimated · Based on PSA Rice Prices</div>
                    <div class="np-head-title">💰 Net Profit Overview</div>
                </div>
                <button class="np-close" onclick="closeNpModal()">✕</button>
            </div>
            <div class="np-body">
                <div class="np-kpis">
                    <div class="np-kpi">
                        <div class="np-kpi-val c-green">₱71,000</div>
                        <div class="np-kpi-label">Est. Net Profit (Season)</div>
                    </div>
                    <div class="np-kpi">
                        <div class="np-kpi-val c-gold">₱105,300</div>
                        <div class="np-kpi-label">Est. Gross Revenue</div>
                    </div>
                    <div class="np-kpi">
                        <div class="np-kpi-val c-red">₱34,300</div>
                        <div class="np-kpi-label">Est. Total Cost</div>
                    </div>
                    <div class="np-kpi">
                        <div class="np-kpi-val c-blue">67.4%</div>
                        <div class="np-kpi-label">Profit Margin</div>
                    </div>
                </div>
                <div class="np-notice">
                    ⚠️ Assuming 1 hectare · PSA retail ₱45/kg · Farmgate ≈ ₱27/kg · PhilRice avg. yield 3.9MT/ha · <a
                        href="#">Set field size in Profile for accuracy</a>
                </div>
                <div class="np-grid">
                    <div class="np-col">
                        <div class="np-minis">
                            <div class="np-mini">
                                <div class="np-mini-lbl">Yield / HA</div>
                                <div class="np-mini-val">3.9 MT/ha</div>
                            </div>
                            <div class="np-mini">
                                <div class="np-mini-lbl">Hectares (Assumed)</div>
                                <div class="np-mini-val">1 ha (default)</div>
                            </div>
                            <div class="np-mini">
                                <div class="np-mini-lbl">Farmgate Price</div>
                                <div class="np-mini-val">₱27/kg</div>
                            </div>
                        </div>
                        <div class="np-sec-title">📊 Quarterly Profit Trend (₱)</div>
                        <div class="chart-tog-wrap" style="margin-bottom:8px">
                            <button class="chart-tog-btn active" id="np-tog-bar"
                                onclick="switchNpChart('bar')">Bar</button>
                            <button class="chart-tog-btn" id="np-tog-line" onclick="switchNpChart('line')">Line</button>
                        </div>
                        <canvas id="np-chart" height="150"></canvas>
                        <div style="display:flex;gap:12px;margin-top:6px;font-size:11px;color:var(--text-muted)">
                            <span style="display:flex;align-items:center;gap:4px"><span
                                    style="width:10px;height:10px;background:var(--sage);border-radius:2px;display:inline-block"></span>Est.
                                Net Profit</span>
                            <span style="display:flex;align-items:center;gap:4px"><span
                                    style="width:10px;height:10px;background:var(--cream-3);border-radius:2px;display:inline-block"></span>Break-even
                                line</span>
                        </div>
                        <div style="margin-top:14px">
                            <div class="np-sec-title">🧾 Revenue vs. Cost Breakdown</div>
                            <div class="np-rev-row"><span class="np-rev-lbl">Gross Revenue</span><span
                                    class="np-rev-val g">₱105,300</span></div>
                            <div class="np-rev-row"><span class="np-rev-lbl">Total Input Costs</span><span
                                    class="np-rev-val r">−₱34,300</span></div>
                            <div class="np-rev-row"><span class="np-rev-lbl">Net Profit</span><span
                                    class="np-rev-val g">₱71,000</span></div>
                            <div class="np-rev-row"><span class="np-rev-lbl">ROI</span><span
                                    class="np-rev-val b">207%</span></div>
                        </div>
                    </div>
                    <div class="np-col">
                        <div class="np-sec-title">💵 Input Cost Breakdown (Per Hectare · PSA/PhilRice)</div>
                        <div class="np-cost-row" style="border-left-color:#5a7a52">
                            <div>
                                <div class="np-cost-name">Seeds &amp; Seedbed</div>
                                <div class="np-cost-sub">₱2,800/ha</div>
                            </div>
                            <div class="np-cost-val">₱2,800</div>
                        </div>
                        <div class="np-cost-row" style="border-left-color:#8aab82">
                            <div>
                                <div class="np-cost-name">Land Preparation</div>
                                <div class="np-cost-sub">₱5,500/ha</div>
                            </div>
                            <div class="np-cost-val">₱5,500</div>
                        </div>
                        <div class="np-cost-row" style="border-left-color:#c8963e">
                            <div>
                                <div class="np-cost-name">Fertilizer</div>
                                <div class="np-cost-sub">₱7,200/ha</div>
                            </div>
                            <div class="np-cost-val">₱7,200</div>
                        </div>
                        <div class="np-cost-row" style="border-left-color:#c0392b">
                            <div>
                                <div class="np-cost-name">Pesticides</div>
                                <div class="np-cost-sub">₱3,100/ha</div>
                            </div>
                            <div class="np-cost-val">₱3,100</div>
                        </div>
                        <div class="np-cost-row" style="border-left-color:#4a90d9">
                            <div>
                                <div class="np-cost-name">Irrigation</div>
                                <div class="np-cost-sub">₱2,400/ha</div>
                            </div>
                            <div class="np-cost-val">₱2,400</div>
                        </div>
                        <div class="np-cost-row" style="border-left-color:#3d5c38">
                            <div>
                                <div class="np-cost-name">Labor (Planting)</div>
                                <div class="np-cost-sub">₱4,800/ha</div>
                            </div>
                            <div class="np-cost-val">₱4,800</div>
                        </div>
                        <div class="np-cost-row" style="border-left-color:#6d9461">
                            <div>
                                <div class="np-cost-name">Labor (Harvest)</div>
                                <div class="np-cost-sub">₱5,200/ha</div>
                            </div>
                            <div class="np-cost-val">₱5,200</div>
                        </div>
                        <div class="np-cost-row" style="border-left-color:#94a18e">
                            <div>
                                <div class="np-cost-name">Misc. / Post-Harvest</div>
                                <div class="np-cost-sub">₱3,300/ha</div>
                            </div>
                            <div class="np-cost-val">₱3,300</div>
                        </div>
                        <div class="np-insight good">
                            <div class="np-insight-title">✅ Profitable Season — 67.4% margin</div>
                            <div class="np-insight-body">Estimated net profit of ₱71,000 at current farmgate price
                                (₱27/kg). ROI is 207% — healthy return on ₱34,300 investment.</div>
                        </div>
                        <div class="np-insight warn" style="margin-top:8px">
                            <div class="np-insight-title w">⚠️ Tip: Improve Margin</div>
                            <div class="np-insight-body">Fertilizer (₱7,200) and combined labor (₱10,000) represent 50%+
                                of costs. Consider bulk procurement or cooperative pricing.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="np-foot">
                <span class="np-foot-note">* Estimates based on PhilRice, PSA, and DA data. Set field size in Profile
                    for accurate figures.</span>
                <button class="np-foot-close" onclick="closeNpModal()">Close</button>
            </div>
        </div>
    </div>
    <script>
        /* ===== SERVER DATA — real, cross-user, no more localStorage mock arrays ===== */
        const PHP_STATS = {
            totalRecords: <?= $totalRecords ?>,
            completedRecords: <?= $completedRecords ?>,
            ongoingRecords: <?= $ongoingRecords ?>,
            totalHectares: <?= $totalHectares ?>,
            farmerCount: <?= $farmerCount ?>
        };
        const TOP_HARVESTERS = <?= $jsTopHarvesters ?>;
        const TOP_PROFIT_FARMERS = <?= $jsTopProfitFarmers ?>;
        const PIE_DATA = <?= $jsPieData ?>;
        const LATEST_RECORDS = <?= $jsLatestRecords ?>;

        const isMob = () => window.innerWidth <= 768;
        (function() {
            if (localStorage.getItem('rw_sidebar_collapsed') === 'true') document.body.classList.add('sc');
            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    document.documentElement.classList.remove('sidebar-pre-collapse');
                });
            });
        })();

        function toggleSidebar() {
            if (isMob()) {
                openSidebar()
            } else {
                const on = document.body.classList.toggle('sc');
                localStorage.setItem('rw_sidebar_collapsed', on ? 'true' : 'false');
                setTimeout(() => {
                    [trendChartInst, harvestChartInst, pieChartInst].forEach(c => c && c.resize());
                }, 340);
            }
        }

        function openSidebar() {
            document.getElementById('sidebar').classList.add('open');
            document.getElementById('sOverlay').classList.add('show')
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sOverlay').classList.remove('show')
        }
        window.addEventListener('resize', () => {
            if (!isMob()) closeSidebar()
        });

        const notifBtn = document.getElementById('notifBtn'),
            ndrop = document.getElementById('ndrop');
        const ppillBtn = document.getElementById('ppillBtn'),
            pdrop = document.getElementById('pdrop');
        notifBtn.addEventListener('click', e => {
            e.stopPropagation();
            const o = ndrop.classList.toggle('show');
            pdrop.classList.remove('show');
            ppillBtn.classList.remove('op');
            if (o) fetchBellNotifs()
        });
        ppillBtn.addEventListener('click', e => {
            e.stopPropagation();
            const o = pdrop.classList.toggle('show');
            ppillBtn.classList.toggle('op', o);
            ndrop.classList.remove('show')
        });
        document.addEventListener('click', () => {
            ndrop.classList.remove('show');
            pdrop.classList.remove('show');
            ppillBtn.classList.remove('op')
        });

        /* ===== BELL DROPDOWN — same real notif_list.php pattern as every other page.
           Uses $_SESSION['rw_user_id'] regardless of role, so this shows whatever is
           in the `notifications` table for the admin's own user_id. Admin accounts
           don't have any seeded notifications yet — a proper platform-wide "system
           alerts" feed (pest/weather/activity alerts across all farmers, like the
           original mock content) would need its own table/design as a follow-up. ===== */
        let _bellNotifs = [];

        function bellNotifIcon(type) {
            const icons = {
                plant: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22V12"/><path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8"/><path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8"/></svg>',
                weather: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 17.58A5 5 0 0 0 18 7h-1.26A8 8 0 1 0 4 16.25"/></svg>',
                reminder: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="8"/><polyline points="12 6 12 12 16 14"/></svg>',
                harvest: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 22 16 8"/><path d="M3.47 12.53 5 11l1.53 1.53a3.5 3.5 0 0 1 0 4.94L5 19l-1.53-1.53a3.5 3.5 0 0 1 0-4.94z"/></svg>',
                pest: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="M8.5 8.5 16 16"/></svg>',
                water: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>',
                system: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="8"/><polyline points="12 6 12 12 16 14"/></svg>',
            };
            return icons[type] || icons.system;
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

        function renderHeaderNotifs() {
            const el = document.getElementById('ndl');
            if (!_bellNotifs.length) {
                el.innerHTML = '<div style="padding:20px;text-align:center;font-size:14px;color:var(--text-muted)">No notifications</div>';
                return;
            }
            el.innerHTML = _bellNotifs.slice(0, 5).map(n => `<div class="ndi ${n.status === 'unread' ? 'ur' : ''}"><div class="ndi-ic" style="background:var(--cream-2)">${bellNotifIcon(n.type)}</div><div><div class="ndi-t">${bellEscapeHtml(n.title)}</div><div class="ndi-d">${bellEscapeHtml(n.description || '')}</div><div class="ndi-dt">${n.notif_date || ''}</div></div></div>`).join('');
        }

        function fetchBellNotifs() {
            fetch('notif_list.php').then(r => r.json()).then(json => {
                if (json.success) {
                    _bellNotifs = json.notifications;
                    updateDot();
                    renderHeaderNotifs();
                }
            }).catch(() => {});
        }

        function updateDot() {
            document.getElementById('ndot').classList.toggle('on', _bellNotifs.some(n => n.status === 'unread'))
        }

        function markAllRead() {
            fetch('notif_mark_all_read.php', {
                method: 'POST'
            }).then(r => r.json()).then(json => {
                if (json.success) {
                    _bellNotifs.forEach(n => n.status = 'read');
                    updateDot();
                    renderHeaderNotifs();
                }
            }).catch(() => {});
        }
           
        let harvestChartInst = null, pieChartInst = null;

        function renderDashboard() {
            const total = PHP_STATS.totalRecords;
            const completionRate = total > 0 ? Math.round(PHP_STATS.completedRecords / total * 100) : 0;
            document.getElementById('dash-stats').innerHTML = [{
                    label: 'Total Records',
                    val: total.toLocaleString(),
                    color: '#5a7a52',
                    bg: '#e8f0e4',
                    sub: 'Farm activities logged, all users'
                },
                {
                    label: 'Completed',
                    val: PHP_STATS.completedRecords.toLocaleString(),
                    color: '#5a7a52',
                    bg: '#e8f0e4',
                    sub: `${completionRate}% completion rate`
                },
                {
                    label: 'Ongoing',
                    val: PHP_STATS.ongoingRecords.toLocaleString(),
                    color: '#c8963e',
                    bg: '#fdf6ec',
                    sub: 'In progress'
                },
                {
                    label: 'Total Farm Area',
                    val: PHP_STATS.totalHectares.toLocaleString() + ' ha',
                    color: '#4a90d9',
                    bg: '#eaf3fc',
                    sub: `Across ${PHP_STATS.farmerCount} registered farmer${PHP_STATS.farmerCount === 1 ? '' : 's'}`
                },
            ].map(s => `<div class="stat-card" style="background:${s.bg};border:1px solid ${s.color}22"><div class="stat-val" style="color:${s.color}">${s.val}</div><div class="stat-label">${s.label}</div><div class="stat-sub">${s.sub}</div></div>`).join('');

            const statsEl = document.getElementById('dash-stats');
            const lastCard = statsEl.lastElementChild;
            const btn = document.createElement('button');
            btn.className = 'stat-analysis-btn';
            btn.innerHTML = '📊 Net Profit Analysis';
            btn.onclick = openNpModal;
            lastCard.appendChild(btn);

            document.getElementById('dash-latest-records').innerHTML = LATEST_RECORDS.length ? LATEST_RECORDS.map(r => {
                const statusSlug = r.status === 'In Progress' ? 'ongoing' : r.status.toLowerCase();
                return `<div class="record-row">
      <div class="record-dot"></div>
      <div style="flex:1;min-width:0"><span class="record-activity">${r.activity}</span><span class="record-field">— ${r.location}</span></div>
      <span class="record-farmer">${r.farmer}</span>
      <span class="badge-status badge-${statusSlug}">${r.status}</span>
    </div>`;
            }).join('') : '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13.5px">No records logged yet across any farmer accounts.</div>';

            buildTrendChart('bar');

            if (TOP_HARVESTERS.length) {
                harvestChartInst = new Chart(document.getElementById('chart-yield-variety').getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: TOP_HARVESTERS.map(d => d.farmer),
                        datasets: [{
                            label: 'Harvest (kg)',
                            data: TOP_HARVESTERS.map(d => d.kg),
                            backgroundColor: TOP_HARVESTERS.map(d => d.fill),
                            borderRadius: 6,
                            borderSkipped: false
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: ctx => ' ' + ctx.raw.toLocaleString() + ' kg'
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
                                        size: 11
                                    }
                                }
                            },
                            y: {
                                grid: {
                                    color: '#f0ebe2'
                                },
                                ticks: {
                                    color: '#94a18e',
                                    font: {
                                        size: 12
                                    },
                                    callback: v => v.toLocaleString() + ' kg'
                                }
                            }
                        }
                    }
                });
            } else {
                document.getElementById('chart-yield-variety').parentElement.insertAdjacentHTML('beforeend', '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px">No completed harvest records yet.</div>');
            }

            if (PIE_DATA.length) {
                pieChartInst = new Chart(document.getElementById('chart-pie').getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: PIE_DATA.map(d => d.name),
                        datasets: [{
                            data: PIE_DATA.map(d => d.value),
                            backgroundColor: PIE_DATA.map(d => d.fill),
                            borderWidth: 2,
                            borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        cutout: '58%',
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });
                document.getElementById('pie-legend').innerHTML = PIE_DATA.map(d => `<div class="pie-legend-item"><div class="pie-dot" style="background:${d.fill}"></div>${d.name} (${d.value})</div>`).join('');
            } else {
                document.getElementById('pie-legend').innerHTML = '<div style="grid-column:1/-1;text-align:center;color:var(--text-muted);font-size:13px;padding:8px 0">No records yet</div>';
            }
        }

        /* ── Top Farmers by Net Profit — real Sales-Expenses methodology,
           same definition already used in reports.php. With only one real
           registered farmer right now, this will show a single bar; that's
           expected given actual signups, not a bug. ── */
        let trendChartInst = null;

        function buildTrendChart(type) {
            if (trendChartInst) trendChartInst.destroy();
            if (!TOP_PROFIT_FARMERS.length) {
                document.getElementById('chart-yield-trend').parentElement.insertAdjacentHTML('beforeend', '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px">No Sales or expense records yet.</div>');
                return;
            }
            trendChartInst = new Chart(document.getElementById('chart-yield-trend').getContext('2d'), {
                type: type,
                data: {
                    labels: TOP_PROFIT_FARMERS.map(d => d.farmer),
                    datasets: [{
                        label: 'Net Profit (₱)',
                        data: TOP_PROFIT_FARMERS.map(d => d.profit),
                        backgroundColor: type === 'bar' ? ['#b5cca8', '#8aab82', '#5a7a52', '#3d5c38', '#2a4228'] : 'rgba(90,122,82,.12)',
                        borderColor: '#5a7a52',
                        borderWidth: type === 'line' ? 2 : 0,
                        borderRadius: type === 'bar' ? 6 : 0,
                        borderSkipped: false,
                        fill: type === 'line',
                        tension: .4,
                        pointBackgroundColor: '#5a7a52',
                        pointRadius: type === 'line' ? 4 : 0
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: ctx => ' ₱' + ctx.raw.toLocaleString()
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
                                    size: 11
                                }
                            }
                        },
                        y: {
                            grid: {
                                color: '#f0ebe2'
                            },
                            ticks: {
                                color: '#94a18e',
                                font: {
                                    size: 12
                                },
                                callback: v => '₱' + (v / 1000).toFixed(0) + 'k'
                            }
                        }
                    }
                }
            });
        }

        function switchTrendChart(type, btn) {
            btn.closest('.chart-tog-wrap').querySelectorAll('.chart-tog-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            buildTrendChart(type);
        }

        /* ── Net Profit modal chart — still the tabled static-formula demo data
           (QUARTERLY_PROFIT equivalent), matching the modal's own placeholder
           KPIs above. Not wired to real data yet — see the HTML comment above
           the modal markup. ── */
        const NP_DEMO_TREND = [{
            label: "Q1",
            profit: 38500
        }, {
            label: "Q2",
            profit: 42300
        }, {
            label: "Q3",
            profit: 47800
        }, {
            label: "Q4",
            profit: 52050
        }];
        let npChartInst = null;

        function buildNpChart(type) {
            if (npChartInst) npChartInst.destroy();
            npChartInst = new Chart(document.getElementById('np-chart').getContext('2d'), {
                type: type,
                data: {
                    labels: NP_DEMO_TREND.map(d => d.label),
                    datasets: [{
                            label: 'Est. Net Profit',
                            data: NP_DEMO_TREND.map(d => d.profit),
                            backgroundColor: type === 'bar' ? ['#b5cca8', '#8aab82', '#5a7a52', '#3d5c38'] : 'rgba(90,122,82,.12)',
                            borderColor: '#5a7a52',
                            borderWidth: type === 'line' ? 2 : 0,
                            borderRadius: type === 'bar' ? 6 : 0,
                            borderSkipped: false,
                            fill: type === 'line',
                            tension: .4,
                            pointBackgroundColor: '#5a7a52',
                            pointRadius: type === 'line' ? 4 : 0,
                            type: type
                        },
                        {
                            label: 'Break-even line',
                            data: NP_DEMO_TREND.map(() => 34300),
                            type: 'line',
                            borderColor: 'rgba(200,150,62,.65)',
                            borderWidth: 2,
                            borderDash: [6, 4],
                            pointRadius: 0,
                            fill: false,
                            tension: 0,
                            backgroundColor: 'transparent'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: ctx => ' ₱' + ctx.raw.toLocaleString()
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
                                    size: 11
                                }
                            }
                        },
                        y: {
                            grid: {
                                color: '#f0ebe2'
                            },
                            ticks: {
                                color: '#94a18e',
                                font: {
                                    size: 12
                                },
                                callback: v => '₱' + (v / 1000).toFixed(0) + 'k'
                            }
                        }
                    }
                }
            });
        }

        function switchNpChart(type) {
            document.getElementById('np-tog-bar').classList.toggle('active', type === 'bar');
            document.getElementById('np-tog-line').classList.toggle('active', type === 'line');
            buildNpChart(type);
        }

        function openNpModal() {
            document.getElementById('npOverlay').classList.add('show');
            document.body.style.overflow = 'hidden';
            setTimeout(() => buildNpChart('bar'), 60);
        }

        function closeNpModal() {
            document.getElementById('npOverlay').classList.remove('show');
            document.body.style.overflow = '';
        }

        function npCloseOnBg(e) {
            if (e.target === document.getElementById('npOverlay')) closeNpModal();
        }
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeNpModal();
        });

        renderDashboard();
        fetchBellNotifs();
    </script>
</body>

</html>