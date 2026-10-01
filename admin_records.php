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

// ── All farm_records across every farmer, joined to the owner's name ──
$rs = $conn->query(
    "SELECT fr.id, fr.user_id, u.name AS farmer, fr.activity, fr.crop_type, fr.location,
            fr.quantity, fr.unit, fr.status, fr.record_date
     FROM farm_records fr
     JOIN users u ON u.id = fr.user_id
     ORDER BY fr.record_date DESC, fr.id DESC"
);
$allRecords = $rs->fetch_all(MYSQLI_ASSOC);

// Farmer list for the Add/Edit modal's dropdown
$fu = $conn->query("SELECT id, name FROM users WHERE role='user' ORDER BY name ASC");
$farmers = $fu->fetch_all(MYSQLI_ASSOC);

$jsRecords = json_encode($allRecords);
$jsFarmers = json_encode($farmers);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RiceWise Admin — Records</title>
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
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0 }

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
            --radius-xs: 8px;
            --sidebar-w: 252px;
            --sidebar-col: 68px;
            --header-h: 64px;
        }

        html { font-size: 15px }
        html, body { height: 100%; font-family: 'DM Sans', sans-serif; background: var(--cream); color: var(--text-primary) }
        body { display: flex; height: 100vh; overflow: hidden }

        ::-webkit-scrollbar { width: 4px; height: 4px }
        ::-webkit-scrollbar-thumb { background: var(--cream-3); border-radius: 2px }

        /* ===== Sidebar ===== */
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

        body.sc .sidebar { width: var(--sidebar-col) }

        .s-top {
            height: var(--header-h);
            padding: 0 16px 0 8px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            flex-shrink: 0;
            transition: padding .3s cubic-bezier(.4,0,.2,1)
        }

        body.sc .s-top { padding: 16px 0; justify-content: center }

        .logo { display: flex; align-items: center; gap: 6px; flex: 1; min-width: 0; cursor: pointer }
        body.sc .logo { justify-content: center; flex: none }

        .logo-icon {
            width: 48px; height: 48px; flex-shrink: 0; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 17px; overflow: hidden; transition: transform .2s
        }
        .logo-icon img { width: 34px; height: 34px; object-fit: contain }

        .logo-tog {
            display: none; width: 44px; height: 44px; flex-shrink: 0;
            align-items: center; justify-content: center; border-radius: var(--radius-sm);
            transition: background .2s
        }
        .logo-tog svg { width: 20px; height: 20px; stroke: var(--text-secondary); transition: stroke .2s }
        .logo-tog:hover { background: var(--sage-pale) }
        .logo-tog:hover svg { stroke: var(--sage-dark) }
        body.sc .logo-icon { display: none }
        body.sc .logo-tog { display: flex }

        .logo-txt { font-family: 'Lora', serif; font-size: 19px; font-weight: 700; color: var(--text-primary); letter-spacing: -.4px; white-space: nowrap }

        .tog {
            width: 30px; height: 30px; flex-shrink: 0; background: none; border: none;
            border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center;
            cursor: pointer; margin-left: auto; transition: background .2s
        }
        .tog:hover { background: var(--sage-pale) }
        .tog svg { width: 18px; height: 18px; stroke: var(--text-muted) }

        .abadge {
            margin: 8px 12px 0; padding: 7px 12px; border-radius: 10px; background: var(--gold-pale);
            border: 1px solid var(--gold-border); display: flex; align-items: center; gap: 6px;
            font-size: 11px; font-weight: 700; color: var(--gold); white-space: nowrap; overflow: hidden; flex-shrink: 0
        }
        .abadge svg { width: 12px; height: 12px; flex-shrink: 0 }

        .nav-sec { padding: 8px 12px; flex: 1; overflow-y: auto; overflow-x: hidden }

        .nl {
            font-size: 10px; font-weight: 700; letter-spacing: 1.4px; text-transform: uppercase;
            color: var(--text-muted); padding: 0 8px; margin: 14px 0 3px; white-space: nowrap
        }
        .nl:first-child { margin-top: 6px }

        .ni {
            display: flex; align-items: center; gap: 11px; padding: 10px; color: var(--text-secondary);
            border: none; background: none; border-radius: var(--radius-sm); cursor: pointer;
            font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 500; position: relative;
            white-space: nowrap; overflow: hidden; width: 100%; text-align: left; transition: all .18s; margin-bottom: 2px
        }
        .ni:hover { background: #fcfcfb; box-shadow: inset 0 0 0 1px var(--sage-pale); color: var(--sage-dark); font-weight: 600 }
        .ni.active { background: var(--sage-pale); color: var(--sage-dark); font-weight: 600 }
        
        .ni svg { width: 18px; height: 18px; flex-shrink: 0 }
        .ni svg .icon-filled { display: none }
        .ni.active svg .icon-outline { display: none }
        .ni.active svg .icon-filled { display: block }
        .ni.active:hover { background: var(--sage-pale); box-shadow: inset 0 0 0 1px var(--sage-light); color: var(--sage-dark) }

        .s-bot { padding: 10px 12px 16px; border-top: 1px solid var(--border); flex-shrink: 0; display: flex; align-items: center }

        .lout {
            display: flex; align-items: center; justify-content: flex-start; gap: 9px; width: 100%;
            padding: 10px 13px; background: transparent; color: var(--text-secondary); border: none;
            border-radius: var(--radius-sm); font-size: 14px; font-weight: 500; cursor: pointer;
            font-family: 'DM Sans', sans-serif; white-space: nowrap; transition: all .18s
        }
        .lout:hover { background: #fcfcfb; box-shadow: inset 0 0 0 1px var(--sage-pale); color: var(--sage-dark); font-weight: 600 }
        .lout svg { width: 15px; height: 15px; flex-shrink: 0 }

        .logo-txt, .ni-txt, .abadge-txt, .lout-txt {
            opacity: 1; transform: translateX(0); max-width: 300px; overflow: hidden;
            transition: opacity .22s ease, transform .22s ease, max-width .22s ease;
        }
        .nl {
            opacity: 1; transform: translateX(0); max-height: 20px;
            transition: opacity .22s ease, transform .22s ease, max-height .22s ease, margin .22s ease;
        }
        body.sc .logo-txt, body.sc .ni-txt, body.sc .abadge-txt, body.sc .lout-txt {
            opacity: 0; transform: translateX(10px); max-width: 0; pointer-events: none;
            transition: opacity .18s ease, transform .18s ease, max-width .18s ease;
        }
        body.sc .nl {
            opacity: 0; transform: translateX(10px); max-height: 0; margin-top: 0; margin-bottom: 0;
            pointer-events: none; transition: opacity .18s ease, transform .18s ease, max-height .18s ease, margin .18s ease;
        }

        /* Tooltips on collapsed nav items */
        .ni { position: relative; }
        body.sc .ni::after {
            content: attr(data-tooltip); position: absolute; left: calc(100% + 12px); top: 50%;
            transform: translateY(-50%); background: var(--text-primary); color: #fff; font-size: 12px;
            font-weight: 500; padding: 5px 10px; border-radius: 6px; white-space: nowrap; opacity: 0;
            pointer-events: none; transition: opacity .15s; z-index: 9999;
        }
        body.sc .ni:hover::after { opacity: 1 }

        body.sc .abadge { justify-content: center; padding: 8px 0; margin: 8px auto; width: 48px }
        body.sc .nav-sec { padding: 8px 0; align-items: center; display: flex; flex-direction: column; gap: 2px }
        body.sc .ni { width: 44px; height: 44px; justify-content: center; padding: 0; gap: 0 }
        body.sc .s-bot { justify-content: center; padding: 10px 0 16px }
        body.sc .lout { width: 44px; height: 44px; justify-content: center; gap: 0 }
        body.sc .tog { display: none }

        .main { flex: 1; min-width: 0; display: flex; flex-direction: column; overflow: hidden; height: 100vh }

        .hdr {
            height: var(--header-h); flex-shrink: 0; background: rgba(250, 247, 242, .96);
            backdrop-filter: blur(16px); border-bottom: 1px solid var(--border-light); padding: 0 24px;
            display: flex; align-items: center; justify-content: space-between; z-index: 100; position: sticky; top: 0
        }
        .hdr-l { display: flex; align-items: center; gap: 12px; font-family: 'Lora', serif; font-size: 22px; font-weight: 700; color: var(--text-primary) }
        .hdr-r { display: flex; align-items: center; gap: 6px }
        .hdr-sep { width: 1px; height: 24px; background: var(--border); margin: 0 4px }

        .hbtn {
            display: flex; align-items: center; justify-content: center; width: 38px; height: 38px;
            border-radius: var(--radius-sm); cursor: pointer; border: none; background: transparent;
            transition: background .2s; position: relative
        }
        .hbtn:hover { background: var(--sage-pale) }
        .hbtn svg { stroke: var(--text-secondary); fill: none }

        .ndot {
            position: absolute; top: 7px; right: 7px; width: 8px; height: 8px; background: var(--red);
            border-radius: 50%; border: 2px solid var(--cream); display: none
        }
        .ndot.on { display: block }

        .ppill {
            display: flex; align-items: center; gap: 8px; padding: 5px 8px 5px 12px; background: var(--white);
            border: 1px solid var(--border); border-radius: 50px; cursor: pointer; transition: border-color .2s
        }
        .ppill:hover { border-color: var(--sage-light) }
        .ppill-name { font-size: 14px; color: var(--text-secondary); font-weight: 500 }
        .pav {
            width: 30px; height: 30px; border-radius: 50%; background: var(--sage-pale);
            border: 1.5px solid var(--sage-light); display: flex; align-items: center; justify-content: center
        }
        .pav svg { width: 15px; height: 15px; stroke: var(--sage) }
        .darr { width: 12px; height: 12px; stroke: var(--text-muted); transition: transform .3s }
        .ppill.op .darr { transform: rotate(180deg) }

        .ndrop {
            position: fixed; top: calc(var(--header-h) + 6px); right: 180px; width: 320px; background: var(--white);
            border: 1px solid var(--border-light); border-radius: var(--radius); box-shadow: var(--shadow-lg);
            z-index: 50000; display: none; overflow: hidden
        }
        .ndrop.show { display: block }
        .ndh { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--border-light) }
        .ndh-t { font-family: 'Lora', serif; font-size: 14px; font-weight: 700; color: var(--text-primary) }
        .ndh-btn { background: none; border: none; color: var(--sage); cursor: pointer; font-size: 13px; font-weight: 600; font-family: inherit }
        .ndl { max-height: 280px; overflow-y: auto }
        .ndi { display: flex; gap: 10px; padding: 10px 14px; border-bottom: 1px solid var(--border-light); cursor: pointer; transition: background .15s }
        .ndi.ur { background: var(--sage-pale) }
        .ndi:hover { background: var(--cream-2) }
        .ndi-ic { width: 30px; height: 30px; border-radius: 8px; background: var(--cream-2); display: flex; align-items: center; justify-content: center; flex-shrink: 0 }
        .ndi-ic svg { width: 14px; height: 14px }
        .ndi-t { font-weight: 600; font-size: 13px; color: var(--text-primary) }
        .ndi-d { font-size: 12px; color: var(--text-secondary) }
        .ndi-dt { font-size: 11px; color: var(--text-muted); font-family: 'DM Mono', monospace; margin-top: 1px }
        .nd-foot { text-align: center; padding: 10px; font-size: 13px; border-top: 1px solid var(--border-light) }
        .nd-foot a { color: var(--sage); text-decoration: none; font-weight: 600 }

        .pdrop {
            position: fixed; top: calc(var(--header-h) + 6px); right: 20px; width: 210px; background: var(--white);
            border: 1px solid var(--border-light); border-radius: var(--radius); box-shadow: var(--shadow-lg);
            z-index: 50000; padding: 14px; display: none
        }
        .pdrop.show { display: block }
        .pdrop h3 { font-family: 'Lora', serif; font-size: 15px; font-weight: 700; color: var(--text-primary); margin-bottom: 2px }
        .pdrop p { font-size: 12px; color: var(--text-muted) }
        .pdrop hr { border: none; border-top: 1px solid var(--border-light); margin: 8px 0 }
        .pdbtn {
            display: block; width: 100%; text-align: left; background: none; border: none; padding: 7px 10px;
            font-size: 14px; color: var(--text-secondary); text-decoration: none; cursor: pointer; border-radius: 8px;
            transition: all .18s; font-family: inherit
        }
        .pdbtn:hover { background: var(--sage-pale); color: var(--sage-dark) }
        .pdbtn.danger { color: var(--red) }
        .pdbtn.danger:hover { background: var(--red-pale) }

        .pscroll { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 20px 24px 32px }

        .s-overlay { display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, .38); z-index: 299; backdrop-filter: blur(3px) }
        .s-overlay.show { display: block }

        .hmbtn {
            display: none; width: 38px; height: 38px; background: var(--cream-2); border: 1px solid var(--border);
            border-radius: var(--radius-sm); align-items: center; justify-content: center; cursor: pointer;
            flex-shrink: 0; transition: background .18s
        }
        .hmbtn:hover { background: var(--sage-pale) }
        .hmbtn svg { width: 18px; height: 18px; stroke: var(--text-secondary) }

        #toast {
            position: fixed; top: 18px; right: 18px; z-index: 99999; padding: 12px 18px; border-radius: var(--radius-sm);
            font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px; box-shadow: var(--shadow-lg);
            border: 1px solid; transition: opacity .3s, transform .3s
        }
        #toast.hidden { opacity: 0; transform: translateY(-10px); pointer-events: none }
        #toast.ok { background: var(--sage-pale); color: var(--sage-dark); border-color: #a8c8a0 }
        #toast.err { background: var(--red-pale); color: var(--red); border-color: var(--red-border) }
        #toast svg { width: 15px; height: 15px; flex-shrink: 0 }

        /* ===== Records-specific ===== */
        .toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 14px }

        .search-wrap { position: relative }
        .search-wrap svg { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 13px; height: 13px; color: var(--text-muted); pointer-events: none }
        .search-wrap input {
            padding: 9px 12px 9px 30px; border-radius: var(--radius-sm); border: 1px solid var(--border-light);
            background: var(--cream-2); font-family: 'DM Sans', sans-serif; font-size: 13px; color: var(--text-primary);
            outline: none; width: 220px; transition: border-color .15s
        }
        .search-wrap input:focus { border-color: var(--sage-light) }

        .filter-tabs {
            display: flex; gap: 2px; padding: 4px; border-radius: var(--radius-sm); background: var(--white);
            border: 1px solid var(--border-light); overflow-x: auto; flex-shrink: 0
        }
        .filter-tab {
            padding: 6px 12px; border-radius: 7px; border: none; background: transparent; font-family: 'DM Sans', sans-serif;
            font-size: 13px; font-weight: 600; cursor: pointer; color: var(--text-muted); white-space: nowrap; transition: all .18s
        }
        .filter-tab.active { background: var(--sage-pale); color: var(--sage-dark) }

        .btn {
            padding: 9px 16px; border-radius: var(--radius-sm); border: none; font-family: 'DM Sans', sans-serif;
            font-size: 13px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: transform .15s
        }
        .btn svg { width: 13px; height: 13px }
        .btn:hover { transform: translateY(-1px) }
        .btn:disabled { opacity: .6; cursor: not-allowed; transform: none }
        .btn-green { background: var(--sage); color: #fff }
        .btn-outline { background: var(--cream-2); color: var(--sage-dark) }

        .table-wrap { overflow-x: auto; border-radius: var(--radius); border: 1px solid var(--border-light); background: var(--white); box-shadow: var(--shadow-sm) }
        table { width: 100%; border-collapse: collapse; font-size: 14px }
        thead tr { background: var(--cream); border-bottom: 1px solid var(--border-light) }
        thead th { padding: 11px 16px; text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted) }
        tbody tr { border-bottom: 1px solid var(--border-light); transition: background .1s }
        tbody tr:hover { background: var(--cream) }
        tbody td { padding: 11px 16px; color: var(--text-secondary) }
        tbody td.bold { font-weight: 600; color: var(--text-primary) }
        tbody td.mono { font-family: 'DM Mono', monospace; font-size: 13px }

        .action-btns { display: flex; gap: 4px }
        .action-btn { width: 28px; height: 28px; border-radius: 7px; border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all .18s }
        .action-btn:hover { opacity: .75 }
        .action-btn svg { width: 11px; height: 11px }
        .action-btn.edit { background: var(--sage-pale); color: var(--sage) }
        .action-btn.del { background: var(--red-pale); color: var(--red) }

        .table-footer { padding: 10px 16px; font-size: 13px; color: var(--text-muted); border-top: 1px solid var(--border-light) }

        .badge-status { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 700 }
        .badge-completed { background: var(--sage-pale); color: var(--sage-dark) }
        .badge-ongoing { background: var(--gold-pale); color: var(--gold) }
        .badge-pending { background: var(--cream-2); color: var(--text-muted) }

        .modal-overlay { position: fixed; inset: 0; z-index: 1000; background: rgba(30, 42, 26, .5); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; padding: 16px }
        .modal-overlay.hidden { display: none }
        .modal { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); width: 100%; max-width: 440px; box-shadow: 0 20px 60px rgba(0, 0, 0, .2) }
        .modal-header { padding: 16px 20px; border-bottom: 1px solid var(--border-light); display: flex; align-items: center; justify-content: space-between }
        .modal-title { font-family: 'Lora', serif; font-size: 15px; font-weight: 700; color: var(--text-primary) }
        .modal-close { width: 32px; height: 32px; border-radius: 8px; background: var(--cream-2); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--text-muted) }
        .modal-body { padding: 20px; display: flex; flex-direction: column; gap: 12px; max-height: 70vh; overflow-y: auto }
        .modal-footer { padding: 12px 20px; border-top: 1px solid var(--border-light); display: flex; justify-content: flex-end; gap: 8px }
        .settings-label { display: block; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px }
        .modal-input, .modal-select {
            width: 100%; padding: 10px 12px; border-radius: var(--radius-sm); border: 1.5px solid var(--border);
            background: var(--cream-2); font-family: 'DM Sans', sans-serif; font-size: 14px; color: var(--text-primary);
            outline: none; transition: border-color .15s
        }
        .modal-input:focus, .modal-select:focus { border-color: var(--sage-light) }
        .modal-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px }
        .modal-error { font-size: 12px; color: var(--red); font-weight: 600; display: none }
        .modal-error.show { display: block }
        .btn-cancel { background: var(--cream-2); color: var(--text-secondary) }

        @media(max-width:768px) {
            html, body { height: auto; min-height: 100%; overflow: auto }
            body { display: block; overflow-x: hidden }
            .sidebar {
                position: fixed; top: 0; left: 0; bottom: 0; height: 100%; width: var(--sidebar-w) !important;
                transform: translateX(-100%); box-shadow: var(--shadow-lg); transition: transform .3s cubic-bezier(.4, 0, .2, 1)
            }
            .sidebar.open { transform: translateX(0) }
            body.sc .logo-txt, body.sc .abadge-txt, body.sc .nl, body.sc .ni-txt, body.sc .lout-txt { display: inline !important }
            body.sc .s-top { padding: 18px 16px 14px !important; justify-content: flex-start !important }
            body.sc .logo { justify-content: flex-start !important; flex: 1 !important }
            body.sc .tog { display: flex !important }
            body.sc .nav-sec { padding: 8px 12px !important; align-items: stretch !important; flex-direction: column !important }
            body.sc .ni { width: auto !important; height: auto !important; justify-content: flex-start !important; padding: 10px !important }
            body.sc .abadge { margin: 8px 12px 0 !important; width: auto !important; justify-content: flex-start !important; padding: 7px 12px !important }
            body.sc .s-bot { padding: 10px 12px 16px !important }
            body.sc .lout { width: 100% !important; height: auto !important; padding: 10px 13px !important; gap: 9px !important }
            body.sc .logo-icon { display: flex !important }
            body.sc .logo-tog { display: none !important }
            .main { display: block; width: 100%; height: auto; overflow: visible }
            .hdr { padding: 0 16px }
            .hmbtn { display: flex }
            .ppill-name { display: none }
            .pscroll { overflow: visible; height: auto }
            .ndrop { right: 10px; left: 10px; width: auto }
            .pdrop { right: 10px }
        }
    </style>
</head>

<body>
    <div id="toast" class="hidden"><svg id="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="2"></svg><span id="toast-msg"></span></div>
    <div class="s-overlay" id="sOverlay" onclick="closeSidebar()"></div>

    <!-- RECORD MODAL -->
    <div class="modal-overlay hidden" id="record-modal">
        <div class="modal">
            <div class="modal-header">
                <span class="modal-title" id="modal-title">Add Record</span>
                <button class="modal-close" onclick="closeModal()"><svg viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" width="14" height="14">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg></button>
            </div>
            <div class="modal-body">
                <div><label class="settings-label">Farmer</label>
                    <select class="modal-select" id="m-farmer"></select>
                </div>
                <div class="modal-row">
                    <div><label class="settings-label">Date</label><input type="date" class="modal-input" id="m-date">
                    </div>
                    <div><label class="settings-label">Activity</label>
                        <select class="modal-select" id="m-activity" onchange="onActivityChange()">
                            <option value="Planting">Planting</option>
                            <option value="Fertilizing">Fertilizing</option>
                            <option value="Irrigation">Irrigation</option>
                            <option value="Pest Control">Pest Control</option>
                            <option value="Harvesting">Harvesting</option>
                            <option value="Sales">Sales</option>
                        </select>
                    </div>
                </div>
                <div id="m-crop-wrap"><label class="settings-label">Crop Type</label>
                    <select class="modal-select" id="m-crop">
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
                <div><label class="settings-label">Field Location</label><input type="text" class="modal-input"
                        id="m-field" placeholder="e.g. Field A-1"></div>
                <div class="modal-row">
                    <div><label class="settings-label" id="m-qty-label">Cost (₱)</label><input type="number"
                            class="modal-input" id="m-qty" placeholder="0" step="0.01" min="0"></div>
                    <div><label class="settings-label">Status</label>
                        <select class="modal-select" id="m-status">
                            <option value="Pending">Pending</option>
                            <option value="In Progress">Ongoing</option>
                            <option value="Completed">Completed</option>
                        </select>
                    </div>
                </div>
                <div class="modal-error" id="modal-error"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-cancel" onclick="closeModal()">Cancel</button>
                <button class="btn btn-green" id="modal-save-btn" onclick="saveRecord()">Save</button>
            </div>
        </div>
    </div>

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
            <button class="ni" data-tooltip="Dashboard" onclick="location.href='admin_dashboard.php'"><svg viewBox="0 0 24 24">
                    <g class="icon-outline" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="7" /><rect x="14" y="3" width="7" height="7" /><rect x="14" y="14" width="7" height="7" /><rect x="3" y="14" width="7" height="7" />
                    </g>
                    <g class="icon-filled" fill="currentColor">
                        <rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" />
                    </g>
                </svg><span class="ni-txt">Dashboard</span></button>

            <button class="ni active" data-tooltip="Records" onclick="location.href='admin_records.php'"><svg viewBox="0 0 24 24">
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
            <a class="lout" href="logout.php" style="text-decoration:none">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                    <polyline points="16 17 21 12 16 7" />
                    <line x1="21" y1="12" x2="9" y2="12" />
                </svg>
                <span class="lout-txt">Sign Out</span>
            </a>
        </div>
    </aside>

    <!-- MAIN -->
    <div class="main">
        <header class="hdr">
            <div class="hdr-l">
                <button class="hmbtn" onclick="openSidebar()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                        stroke-linecap="round">
                        <line x1="3" y1="6" x2="21" y2="6" />
                        <line x1="3" y1="12" x2="21" y2="12" />
                        <line x1="3" y1="18" x2="21" y2="18" />
                    </svg>
                </button>
                Records
            </div>
            <div class="hdr-r">
                <button class="hbtn" id="notifBtn">
                    <svg width="21" height="21" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
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
            <div class="toolbar">
                <div class="search-wrap" style="flex:1;min-width:160px">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8" />
                        <line x1="21" y1="21" x2="16.65" y2="16.65" />
                    </svg>
                    <input type="text" id="records-search" placeholder="Search records…" oninput="filterRecords()"
                        style="width:100%">
                </div>
                <div class="filter-tabs" id="records-filter-tabs">
                    <button class="filter-tab active" data-status="All" onclick="setRecordsFilter('All', this)">All</button>
                    <button class="filter-tab" data-status="Completed" onclick="setRecordsFilter('Completed', this)">Completed</button>
                    <button class="filter-tab" data-status="In Progress" onclick="setRecordsFilter('In Progress', this)">Ongoing</button>
                    <button class="filter-tab" data-status="Pending" onclick="setRecordsFilter('Pending', this)">Pending</button>
                </div>
                <button class="btn btn-green" onclick="openAddModal()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19" />
                        <line x1="5" y1="12" x2="19" y2="12" />
                    </svg>Add
                </button>
                <button class="btn btn-outline" onclick="exportRecordsCSV()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                        <polyline points="7 10 12 15 17 10" />
                        <line x1="12" y1="15" x2="12" y2="3" />
                    </svg>Export
                </button>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr id="records-thead"></tr>
                    </thead>
                    <tbody id="records-tbody"></tbody>
                </table>
                <div class="table-footer" id="records-footer"></div>
            </div>
        </div>
    </div>
    <script>
        /* ===== SERVER DATA — real, cross-user ===== */
        const PHP_FARMERS = <?= $jsFarmers ?>;
        let records = <?= $jsRecords ?>; // {id, user_id, farmer, activity, crop_type, location, quantity, unit, status, record_date}

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
                openSidebar();
            } else {
                const on = document.body.classList.toggle('sc');
                localStorage.setItem('rw_sidebar_collapsed', on ? 'true' : 'false');
            }
        }

        function openSidebar() {
            document.getElementById('sidebar').classList.add('open');
            document.getElementById('sOverlay').classList.add('show');
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sOverlay').classList.remove('show');
        }
        window.addEventListener('resize', () => {
            if (!isMob()) closeSidebar();
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
            if (o) fetchBellNotifs();
        });
        ppillBtn.addEventListener('click', e => {
            e.stopPropagation();
            const o = pdrop.classList.toggle('show');
            ppillBtn.classList.toggle('op', o);
            ndrop.classList.remove('show');
        });
        document.addEventListener('click', () => {
            ndrop.classList.remove('show');
            pdrop.classList.remove('show');
            ppillBtn.classList.remove('op');
        });

        /* ===== BELL DROPDOWN — real notif_list.php, same as admin_dashboard.php ===== */
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
            document.getElementById('ndot').classList.toggle('on', _bellNotifs.some(n => n.status === 'unread'));
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

        let recordsFilter = 'All';
        let editingRecordId = null;
        const KG_ACTIVITY = 'Harvesting';

        function escapeHtml(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c]));
        }

        function fmtQty(qty, unit) {
            const n = Math.round(parseFloat(qty) * 100) / 100;
            const disp = Number.isInteger(n) ? n : n.toFixed(2);
            return unit === 'kg' ? `${disp} kg` : `₱${disp}`;
        }

        function statusSlug(s) {
            return s === 'In Progress' ? 'ongoing' : s.toLowerCase();
        }

        function statusLabel(s) {
            return s === 'In Progress' ? 'Ongoing' : s;
        }

        function getFilteredRecords() {
            const q = (document.getElementById('records-search')?.value || '').toLowerCase();
            return records.filter(r => {
                const matchQ = !q || [r.farmer, r.activity, r.location, r.status, r.crop_type].some(v => v && v.toLowerCase().includes(q));
                const matchF = recordsFilter === 'All' || r.status === recordsFilter;
                return matchQ && matchF;
            });
        }

        function setRecordsFilter(status, btn) {
            recordsFilter = status;
            document.querySelectorAll('#records-filter-tabs .filter-tab').forEach(b => b.classList.toggle('active', b === btn));
            renderRecords();
        }

        function filterRecords() {
            renderRecords();
        }

        function renderRecords() {
            const filtered = getFilteredRecords();
            const cols = ['Date', 'Farmer', 'Activity', 'Field', 'Quantity', 'Status', 'Actions'];
            document.getElementById('records-thead').innerHTML = cols.map(h => `<th>${h}</th>`).join('');
            document.getElementById('records-tbody').innerHTML = filtered.length ? filtered.map(r => `
            <tr>
              <td class="mono">${r.record_date}</td><td class="bold">${escapeHtml(r.farmer)}</td>
              <td>${r.activity}${r.crop_type ? ' <span style="color:var(--text-muted);font-size:12px">(' + escapeHtml(r.crop_type) + ')</span>' : ''}</td>
              <td class="mono">${escapeHtml(r.location)}</td><td class="mono">${fmtQty(r.quantity, r.unit)}</td>
              <td><span class="badge-status badge-${statusSlug(r.status)}">${statusLabel(r.status)}</span></td>
              <td><div class="action-btns">
                <button class="action-btn edit" onclick="openEditModal(${r.id})"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>
                <button class="action-btn del" onclick="deleteRecord(${r.id})"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg></button>
              </div></td>
            </tr>`).join('') : `<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted)">No records match your search.</td></tr>`;
            document.getElementById('records-footer').textContent = `Showing ${filtered.length} of ${records.length} records`;
        }

        function onActivityChange() {
            const act = document.getElementById('m-activity').value;
            document.getElementById('m-crop-wrap').style.display = act === 'Planting' ? 'block' : 'none';
            document.getElementById('m-qty-label').textContent = act === KG_ACTIVITY ? 'Quantity (kg)' : 'Cost (₱)';
        }

        function populateFarmerSelect(selectedId) {
            const sel = document.getElementById('m-farmer');
            sel.innerHTML = '<option value="">Select farmer…</option>' + PHP_FARMERS.map(f => `<option value="${f.id}">${escapeHtml(f.name)}</option>`).join('');
            if (selectedId) sel.value = selectedId;
        }

        function openAddModal() {
            editingRecordId = null;
            document.getElementById('modal-title').textContent = 'Add Record';
            document.getElementById('modal-error').classList.remove('show');
            populateFarmerSelect(null);
            document.getElementById('m-date').value = new Date().toISOString().slice(0, 10);
            document.getElementById('m-activity').value = 'Planting';
            document.getElementById('m-crop').value = '';
            document.getElementById('m-field').value = '';
            document.getElementById('m-qty').value = '';
            document.getElementById('m-status').value = 'Pending';
            onActivityChange();
            document.getElementById('record-modal').classList.remove('hidden');
        }

        function openEditModal(id) {
            const r = records.find(r => r.id === id);
            if (!r) return;
            editingRecordId = id;
            document.getElementById('modal-title').textContent = 'Edit Record';
            document.getElementById('modal-error').classList.remove('show');
            populateFarmerSelect(r.user_id);
            document.getElementById('m-date').value = r.record_date;
            document.getElementById('m-activity').value = r.activity;
            document.getElementById('m-crop').value = r.crop_type || '';
            document.getElementById('m-field').value = r.location;
            document.getElementById('m-qty').value = r.quantity;
            document.getElementById('m-status').value = r.status;
            onActivityChange();
            document.getElementById('record-modal').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('record-modal').classList.add('hidden');
        }

        function saveRecord() {
            const errEl = document.getElementById('modal-error');
            errEl.classList.remove('show');
            const userId = document.getElementById('m-farmer').value;
            const activity = document.getElementById('m-activity').value;
            const cropType = document.getElementById('m-crop').value;
            const location = document.getElementById('m-field').value.trim();
            const qty = document.getElementById('m-qty').value;
            const status = document.getElementById('m-status').value;
            const recordDate = document.getElementById('m-date').value;

            if (!userId) {
                errEl.textContent = 'Please select a farmer.';
                errEl.classList.add('show');
                return;
            }
            if (!location) {
                errEl.textContent = 'Please enter a field location.';
                errEl.classList.add('show');
                return;
            }
            if (qty === '' || parseFloat(qty) < 0) {
                errEl.textContent = 'Please enter a valid quantity/cost.';
                errEl.classList.add('show');
                return;
            }
            if (!recordDate) {
                errEl.textContent = 'Please choose a date.';
                errEl.classList.add('show');
                return;
            }
            if (activity === 'Planting' && !cropType) {
                errEl.textContent = 'Crop type is required for Planting.';
                errEl.classList.add('show');
                return;
            }

            const payload = {
                user_id: parseInt(userId, 10),
                activity,
                crop_type: activity === 'Planting' ? cropType : null,
                location,
                quantity: parseFloat(qty),
                unit: activity === KG_ACTIVITY ? 'kg' : 'peso',
                status,
                record_date: recordDate
            };
            const btn = document.getElementById('modal-save-btn');
            btn.disabled = true;
            btn.textContent = 'Saving…';

            const url = editingRecordId ? 'admin_records_edit.php' : 'admin_records_add.php';
            if (editingRecordId) payload.id = editingRecordId;

            fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                })
                .then(res => res.json()).then(json => {
                    btn.disabled = false;
                    btn.textContent = 'Save';
                    if (!json.success) {
                        errEl.textContent = json.message || 'Failed to save record.';
                        errEl.classList.add('show');
                        return;
                    }
                    const farmerName = PHP_FARMERS.find(f => f.id == userId)?.name || '';
                    if (editingRecordId) {
                        records = records.map(r => r.id === editingRecordId ? {
                            ...r,
                            ...payload,
                            farmer: farmerName
                        } : r);
                    } else {
                        records = [{
                            id: json.id,
                            ...payload,
                            farmer: farmerName
                        }, ...records];
                    }
                    closeModal();
                    renderRecords();
                    showToast('Record saved!');
                }).catch(() => {
                    btn.disabled = false;
                    btn.textContent = 'Save';
                    errEl.textContent = 'Network error — please try again.';
                    errEl.classList.add('show');
                });
        }

        function deleteRecord(id) {
            if (!confirm('Delete this record? This cannot be undone.')) return;
            fetch('admin_records_delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id
                    })
                })
                .then(res => res.json()).then(json => {
                    if (!json.success) {
                        showToast(json.message || 'Failed to delete record.', 'err');
                        return;
                    }
                    records = records.filter(r => r.id !== id);
                    renderRecords();
                    showToast('Record deleted!');
                }).catch(() => showToast('Network error — please try again.', 'err'));
        }

        function exportRecordsCSV() {
            const data = getFilteredRecords();
            if (!data.length) return;
            const keys = ['record_date', 'farmer', 'activity', 'crop_type', 'location', 'quantity', 'unit', 'status'];
            const rows = [keys.join(','), ...data.map(r => keys.map(k => `"${String(r[k] ?? '').replace(/"/g, '""')}"`).join(','))];
            const blob = new Blob([rows.join('\n')], {
                type: 'text/csv'
            });
            const url = URL.createObjectURL(blob);
            const a = Object.assign(document.createElement('a'), {
                href: url,
                download: 'admin-records.csv'
            });
            a.click();
            URL.revokeObjectURL(url);
        }

        let toastTimer;

        function showToast(msg, type = 'ok') {
            clearTimeout(toastTimer);
            const el = document.getElementById('toast');
            el.className = type;
            document.getElementById('toast-msg').textContent = msg;
            document.getElementById('toast-icon').innerHTML = type === 'ok' ? '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>' : '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>';
            toastTimer = setTimeout(() => el.classList.add('hidden'), 3000);
        }

        renderRecords();
        fetchBellNotifs();
    </script>
</body>

</html>