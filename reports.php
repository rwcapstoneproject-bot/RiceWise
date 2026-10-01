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

// ============================================================
//  Reports aggregation — built from real farm_records rows.
//
//  Mapping used (since farm_records has one quantity/unit column,
//  not separate revenue/expense fields):
//    Harvest (kg)  = SUM(quantity) WHERE activity = 'Harvesting'
//    Revenue (₱)   = SUM(quantity) WHERE activity = 'Sales'
//    Expenses (₱)  = SUM(quantity) WHERE activity IN
//                     ('Planting','Fertilizing','Irrigation','Pest Control')
//    Profit        = Revenue - Expenses
//    Field donut   = harvest kg grouped by `location`, dynamically
//                     (top 4 + "Other"), not fixed field names
// ============================================================

$rs = $conn->prepare("SELECT activity, location, quantity, unit, status, record_date FROM farm_records WHERE user_id=?");
$rs->bind_param('i', $uid);
$rs->execute();
$allRecords = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
$rs->close();

const RW_REVENUE_ACTIVITIES = ['Sales'];
const RW_EXPENSE_ACTIVITIES = ['Planting', 'Fertilizing', 'Irrigation', 'Pest Control'];
const RW_HARVEST_ACTIVITY   = 'Harvesting';
const RW_ALL_ACTIVITIES     = ['Harvesting', 'Planting', 'Fertilizing', 'Irrigation', 'Pest Control', 'Sales'];
const RW_DONUT_COLORS       = ['#5a7a52', '#8aab82', '#b8d4b4', '#c8963e', '#a8a29a'];

function rw_in_range(array $r, DateTime $start, DateTime $end): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $r['record_date']);
    return $d !== false && $d >= $start && $d <= $end;
}

function rw_filter_range(array $records, DateTime $start, DateTime $end): array
{
    return array_values(array_filter($records, fn($r) => rw_in_range($r, $start, $end)));
}

function rw_sum_qty(array $records, array $activities, array $includeStatuses = ['Completed', 'In Progress']): float
{
    $sum = 0.0;
    foreach ($records as $r) {
        if (in_array($r['activity'], $activities, true) && in_array($r['status'], $includeStatuses, true)) {
            $sum += (float)$r['quantity'];
        }
    }
    return $sum;
}

function rw_pct_change(float $cur, float $prev, bool $invert = false): array
{
    if ($prev == 0.0) {
        $pct = $cur == 0.0 ? 0 : 100;
    } else {
        $pct = (int)round(abs(($cur - $prev) / $prev) * 100);
    }
    $increased = $cur >= $prev;
    $dir = $invert ? ($increased ? 'down' : 'up') : ($increased ? 'up' : 'down');
    return ['change' => $pct . '%', 'dir' => $dir];
}

function rw_status_slug(string $status): string
{
    return match ($status) {
        'Completed' => 'completed',
        'In Progress' => 'in-progress',
        default => 'pending',
    };
}

function rw_fmt_qty(float $qty, string $unit): string
{
    $num = round($qty, 2) == floor($qty) ? number_format($qty, 0) : number_format($qty, 2);
    return $unit === 'kg' ? ($num . ' kg') : ('₱' . $num);
}

/**
 * Builds one period block (quarterly or yearly) matching the shape the
 * front-end DATA[period] object expects.
 *
 * @param array   $records     All of the user's farm_records rows
 * @param DateTime $start      Start of the current period
 * @param DateTime $end        End of the current period
 * @param DateTime $prevStart  Start of the comparison (previous) period
 * @param DateTime $prevEnd    End of the comparison (previous) period
 * @param array   $buckets     List of [start, end, label] sub-ranges for the trend charts
 * @param string  $label       Display label, e.g. "Q3 2026 (Jul – Sep)"
 */
function rw_build_period(array $records, DateTime $start, DateTime $end, DateTime $prevStart, DateTime $prevEnd, array $buckets, string $label): array
{
    $cur  = rw_filter_range($records, $start, $end);
    $prev = rw_filter_range($records, $prevStart, $prevEnd);

    $harvestKg  = rw_sum_qty($cur, [RW_HARVEST_ACTIVITY], ['Completed']);
    $revenue    = rw_sum_qty($cur, RW_REVENUE_ACTIVITIES, ['Completed']);
    $expenses   = rw_sum_qty($cur, RW_EXPENSE_ACTIVITIES);
    $profit     = $revenue - $expenses;

    $harvestKgPrev = rw_sum_qty($prev, [RW_HARVEST_ACTIVITY], ['Completed']);
    $revenuePrev   = rw_sum_qty($prev, RW_REVENUE_ACTIVITIES, ['Completed']);
    $expensesPrev  = rw_sum_qty($prev, RW_EXPENSE_ACTIVITIES);
    $profitPrev    = $revenuePrev - $expensesPrev;

    $harvestChange  = rw_pct_change($harvestKg, $harvestKgPrev);
    $revenueChange  = rw_pct_change($revenue, $revenuePrev);
    $expensesChange = rw_pct_change($expenses, $expensesPrev, true); // spending more = bad
    $profitChange   = rw_pct_change($profit, $profitPrev);

    // ── Trend charts (revenue/expenses + harvest, per bucket) ──
    $bucketLabels  = [];
    $bucketRevenue = [];
    $bucketExpense = [];
    $bucketHarvest = [];
    foreach ($buckets as [$bStart, $bEnd, $bLabel]) {
        $bRecords = rw_filter_range($records, $bStart, $bEnd);
        $bucketLabels[]  = $bLabel;
        $bucketRevenue[] = rw_sum_qty($bRecords, RW_REVENUE_ACTIVITIES);
        $bucketExpense[] = rw_sum_qty($bRecords, RW_EXPENSE_ACTIVITIES);
        $bucketHarvest[] = rw_sum_qty($bRecords, [RW_HARVEST_ACTIVITY]);
    }

    // ── Donut: harvest kg grouped by location, top 4 + Other ──
    $byLocation = [];
    foreach ($cur as $r) {
        if ($r['activity'] !== RW_HARVEST_ACTIVITY || $r['status'] !== 'Completed') continue;
        $locRaw = (string)($r['location'] ?? '');
        $loc = trim($locRaw) !== '' ? $locRaw : 'Unspecified';
        $byLocation[$loc] = ($byLocation[$loc] ?? 0) + (float)$r['quantity'];
    }
    arsort($byLocation);
    $donutLabels = [];
    $donutData   = [];
    $i = 0;
    $otherSum = 0.0;
    foreach ($byLocation as $loc => $kg) {
        if ($i < 4) {
            $donutLabels[] = $loc;
            $donutData[]   = round($kg, 2);
        } else {
            $otherSum += $kg;
        }
        $i++;
    }
    if ($otherSum > 0) {
        $donutLabels[] = 'Other';
        $donutData[]   = round($otherSum, 2);
    }
    $donutColors = [];
    foreach ($donutLabels as $idx => $_) $donutColors[] = RW_DONUT_COLORS[$idx % count(RW_DONUT_COLORS)];

    // ── Activity breakdown ──
        $activityColors = ['#5a7a52', '#8aab82', '#b8d4b4', '#c8963e', '#f0d9b5', '#e0c9a6'];
    $counts = [];
    $sums   = [];
    foreach (RW_ALL_ACTIVITIES as $act) {
        $matching = array_values(array_filter($cur, fn($r) => $r['activity'] === $act));
        $counts[$act] = count($matching);
        $unit = $act === RW_HARVEST_ACTIVITY ? 'kg' : 'peso';
        $sums[$act] = rw_sum_qty($matching, [$act]);
    }
    $maxCount = max($counts) ?: 1;
    $activities = [];
    foreach (RW_ALL_ACTIVITIES as $idx => $act) {
        if ($counts[$act] === 0) continue; // skip activity types with zero records this period
        $unit = $act === RW_HARVEST_ACTIVITY ? 'kg' : 'peso';
        $activities[] = [
            'type'    => $act,
            'label'   => $act,
            'records' => $counts[$act],
            'qty'     => rw_fmt_qty($sums[$act], $unit),
            'pct'     => (int)round($counts[$act] / $maxCount * 100),
            'color'   => $activityColors[$idx % count($activityColors)],
        ];
    }

    // ── Recent records table (latest 5 in this period) ──
    $sorted = $cur;
    usort($sorted, fn($a, $b) => strcmp($b['record_date'], $a['record_date']));
    $recordRows = [];
    foreach (array_slice($sorted, 0, 5) as $r) {
        $d = DateTime::createFromFormat('Y-m-d', $r['record_date']);
        $unit = $r['activity'] === RW_HARVEST_ACTIVITY ? 'kg' : 'peso';
        $recordRows[] = [
            'date'     => $d ? $d->format('M j, Y') : $r['record_date'],
            'activity' => $r['activity'],
            'field'    => $r['location'],
            'qty'      => rw_fmt_qty((float)$r['quantity'], $unit),
            'status'   => rw_status_slug($r['status']),
        ];
    }

    return [
        'label'    => $label,
        'harvest'  => ['val' => number_format($harvestKg) . ' kg', 'raw' => $harvestKg] + $harvestChange,
        'revenue'  => ['val' => '₱' . number_format($revenue), 'raw' => $revenue] + $revenueChange,
        'expenses' => ['val' => '₱' . number_format($expenses), 'raw' => $expenses] + $expensesChange,
        'profit'   => ['val' => '₱' . number_format($profit), 'raw' => $profit] + $profitChange,
        'revenueChart' => ['labels' => $bucketLabels, 'revenue' => $bucketRevenue, 'expenses' => $bucketExpense],
        'donut' => ['labels' => $donutLabels, 'data' => $donutData, 'colors' => $donutColors, 'total' => array_sum($donutData)],
        'harvestBar' => ['labels' => $bucketLabels, 'data' => $bucketHarvest],
        'activities' => $activities,
        'records' => $recordRows,
    ];
}

$today = new DateTime();
$curYear = (int)$today->format('Y');
$curQ = (int)ceil(((int)$today->format('n')) / 3);
$prevQ = $curQ - 1;
$prevQYear = $curYear;
if ($prevQ < 1) {
    $prevQ = 4;
    $prevQYear--;
}

$qStartMonth = ($curQ - 1) * 3 + 1;
$curQStart = new DateTime("$curYear-$qStartMonth-01");
$curQEnd = (clone $curQStart)->modify('+3 months')->modify('-1 day');

$pqStartMonth = ($prevQ - 1) * 3 + 1;
$prevQStart = new DateTime("$prevQYear-$pqStartMonth-01");
$prevQEnd = (clone $prevQStart)->modify('+3 months')->modify('-1 day');

$quarterMonthNames = [];
for ($m = 0; $m < 3; $m++) {
    $mStart = (clone $curQStart)->modify("+$m months");
    $mEnd = (clone $mStart)->modify('+1 month')->modify('-1 day');
    $quarterMonthNames[] = [$mStart, $mEnd, $mStart->format('M')];
}
$qLabel = 'Q' . $curQ . ' ' . $curYear . ' (' . $curQStart->format('M') . ' – ' . $curQEnd->format('M') . ')';

$curYStart = new DateTime("$curYear-01-01");
$curYEnd = new DateTime("$curYear-12-31");
$prevYStart = new DateTime(($curYear - 1) . '-01-01');
$prevYEnd = new DateTime(($curYear - 1) . '-12-31');

$yearQuarters = [];
for ($q = 1; $q <= 4; $q++) {
    $qsMonth = ($q - 1) * 3 + 1;
    $qs = new DateTime("$curYear-$qsMonth-01");
    $qe = (clone $qs)->modify('+3 months')->modify('-1 day');
    $yearQuarters[] = [$qs, $qe, 'Q' . $q];
}
$yLabel = 'Full Year ' . $curYear;

$reportData = [
    'quarterly' => rw_build_period($allRecords, $curQStart, $curQEnd, $prevQStart, $prevQEnd, $quarterMonthNames, $qLabel),
    'yearly'    => rw_build_period($allRecords, $curYStart, $curYEnd, $prevYStart, $prevYEnd, $yearQuarters, $yLabel),
];
$jsReportData = json_encode($reportData);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RiceWise - Reports</title>
    <link rel="icon" type="image/png" href="pictures/RiceWise_Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Lora:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap"
        rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            transition: none !important;
        }

        html.sidebar-pre-collapse .logo-text,
        html.sidebar-pre-collapse .logo-wrap,
        html.sidebar-pre-collapse .nav-text,
        html.sidebar-pre-collapse .nav-label,
        html.sidebar-pre-collapse .signout-text,
        html.sidebar-pre-collapse .toggle-btn {
            display: none !important;
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
            transition: width .3s cubic-bezier(.4, 0, .2, 1)
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
            border-radius: var(--radius-sm);
            gap: 0
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
            overflow: hidden
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
            display: flex;
            align-items: center
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
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--cream-3) transparent
        }
            
        .nd-list::-webkit-scrollbar { width: 4px }
        .nd-list::-webkit-scrollbar-track { background: transparent }
        .nd-list::-webkit-scrollbar-thumb { background: var(--cream-3); border-radius: 2px }
        .nd-list::-webkit-scrollbar-thumb:hover { background: var(--sage-light) }

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

        /* ===== REPORTS ===== */
        .reports-content {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 20px 24px 28px;
            display: flex;
            flex-direction: column;
            gap: 18px
        }

        .reports-content::-webkit-scrollbar {
            width: 4px
        }

        .reports-content::-webkit-scrollbar-thumb {
            background: var(--cream-3);
            border-radius: 2px
        }

        /* Period bar */
        .period-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            flex-shrink: 0
        }

        .period-bar-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap
        }

        .period-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            letter-spacing: .4px
        }

        .period-tabs {
            display: flex;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 4px;
            gap: 3px
        }

        .ptab {
            padding: 8px 24px;
            border: none;
            background: transparent;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 500;
            color: var(--text-muted);
            cursor: pointer;
            transition: all .2s;
            font-family: inherit
        }

        .ptab.active {
            background: var(--sage);
            color: white;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(61, 92, 56, .25)
        }

        .ptab:hover:not(.active) {
            background: var(--sage-pale);
            color: var(--sage-dark)
        }

        .period-indicator {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            background: var(--cream-2);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 5px 12px;
            font-family: 'DM Mono', monospace
        }

        .export-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            background: var(--gold-pale);
            border: 1px solid var(--gold);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 500;
            color: var(--gold);
            cursor: pointer;
            transition: all .18s;
            font-family: inherit
        }

        .export-btn:hover {
            background: #f5e6c4
        }

        .export-btn svg {
            width: 15px;
            height: 15px
        }

        /* Stat cards */
        .stat-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px 22px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            transition: all .22s;
            cursor: pointer;
            position: relative;
            overflow: hidden
        }

        .stat-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--sage), var(--gold));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform .3s
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px)
        }

        .stat-card:hover::after {
            transform: scaleX(1)
        }

        .stat-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px
        }

        .stat-card-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .7px
        }

                .stat-card-icon {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0
        }

        .stat-card-icon svg {
            width: 20px;
            height: 20px;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round
        }

        .stat-card-icon.green {
            background: var(--sage-pale)
        }

        .stat-card-icon.green svg {
            stroke: var(--sage-dark)
        }

        .stat-card-icon.gold {
            background: var(--gold-pale)
        }

        .stat-card-icon.gold svg {
            stroke: var(--gold)
        }

        .stat-card-icon.blue {
            background: #e8f2fd
        }

        .stat-card-icon.blue svg {
            stroke: var(--blue)
        }

        .stat-card-icon.red {
            background: #fde8e8
        }

        .stat-card-icon.red svg {
            stroke: var(--red)
        }

        .stat-card-value {
            font-family: 'Lora', serif;
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1;
            margin-bottom: 6px
        }

        .stat-card-sub {
            font-size: 12.5px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap
        }

        .change-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 11.5px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 20px
        }

        .change-badge.up {
            background: #e3f5e3;
            color: #1e5c1e
        }

        .change-badge.down {
            background: #fde8e8;
            color: #c82333
        }

        .stat-info-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--cream-2);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 700;
            opacity: 0;
            transition: opacity .2s;
            font-family: 'DM Mono', monospace
        }

        .stat-card:hover .stat-info-btn {
            opacity: 1
        }

        /* Charts */
        .charts-row {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 16px
        }

        .chart-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 22px 24px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            gap: 14px;
            overflow: hidden;
            min-width: 0
        }

        .chart-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-shrink: 0;
            flex-wrap: wrap;
            gap: 10px
        }

        .chart-card-title {
            font-family: 'Lora', serif;
            font-size: 17px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .chart-card-sub {
            font-size: 12.5px;
            color: var(--text-muted);
            margin-top: 2px
        }

        .chart-head-actions {
            display: flex;
            align-items: center;
            gap: 8px
        }

        .chart-info-btn {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: var(--cream-2);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 700;
            transition: all .18s;
            font-family: 'DM Mono', monospace
        }

        .chart-info-btn:hover {
            background: var(--sage-pale);
            color: var(--sage-dark)
        }

        .chart-legend {
            display: flex;
            gap: 14px;
            flex-wrap: wrap
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12.5px;
            color: var(--text-secondary)
        }

        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0
        }

        .chart-area {
            position: relative;
            height: 220px;
            width: 100%
        }

        .donut-wrap {
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center
        }

        .donut-inner {
            position: relative;
            width: min(200px, 100%);
            height: 200px
        }

        .donut-inner canvas {
            position: absolute;
            inset: 0;
            width: 100% !important;
            height: 100% !important
        }

        /* Breakdown */
        .breakdown-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px
        }

        .breakdown-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 22px 24px;
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm)
        }

        .breakdown-title {
            font-family: 'Lora', serif;
            font-size: 17px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 3px
        }

        .breakdown-sub {
            font-size: 12.5px;
            color: var(--text-muted);
            margin-bottom: 16px
        }

                .bar-item {
            margin-bottom: 12px
        }

        .bar-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px
        }

        .bar-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13.5px;
            font-weight: 500;
            color: var(--text-secondary)
        }

        .bar-icon {
            width: 22px;
            height: 22px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0
        }

        .bar-icon svg {
            width: 12px;
            height: 12px;
            fill: none;
            stroke: var(--sage-dark);
            stroke-width: 2.2;
            stroke-linecap: round;
            stroke-linejoin: round
        }

        .bar-val {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary);
            font-family: 'DM Mono', monospace
        }

        .bar-track {
            height: 8px;
            background: var(--cream-2);
            border-radius: 4px;
            overflow: hidden;
            border: 1px solid var(--border-light)
        }

        .bar-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 1s cubic-bezier(.4, 0, .2, 1)
        }

        /* Records table */
        .records-table-card {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            overflow: hidden
        }

        .records-table-head {
            padding: 20px 24px 14px;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between
        }

        .records-table-title {
            font-family: 'Lora', serif;
            font-size: 17px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .records-table-sub {
            font-size: 12.5px;
            color: var(--text-muted);
            margin-top: 2px
        }

        .view-all-link {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--sage);
            text-decoration: none;
            transition: color .18s
        }

        .view-all-link:hover {
            color: var(--sage-dark)
        }

        .rt-scroll-wrap {
            overflow-x: auto
        }

        table.rt {
            width: 100%;
            border-collapse: collapse;
            min-width: 500px
        }

        table.rt thead tr {
            background: var(--cream-2);
            border-bottom: 2px solid var(--border)
        }

        table.rt th {
            padding: 12px 20px;
            text-align: left;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .7px;
            white-space: nowrap
        }

        table.rt td {
            padding: 13px 20px;
            border-bottom: 1px solid var(--border-light);
            font-size: 14px;
            color: var(--text-secondary)
        }

        table.rt tbody tr:last-child td {
            border-bottom: none
        }

        table.rt tbody tr:hover {
            background: var(--cream)
        }

        table.rt td:nth-child(2) {
            font-weight: 600;
            color: var(--text-primary)
        }

        table.rt td:nth-child(4) {
            font-family: 'DM Mono', monospace;
            font-size: 13px
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700
        }

        .badge::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            flex-shrink: 0
        }

        .badge.completed {
            background: #e3f5e3;
            color: #1e5c1e
        }

        .badge.completed::before {
            background: #2a8a2a
        }

        .badge.in-progress {
            background: #fef3e0;
            color: #a36820
        }

        .badge.in-progress::before {
            background: #e08020
        }

        .badge.pending {
            background: #f0f0f0;
            color: #555
        }

        .badge.pending::before {
            background: #999
        }

        /* ===== INSIGHT MODAL ===== */
        .insight-modal {
            display: none;
            position: fixed;
            z-index: 9000;
            inset: 0;
            background: rgba(15, 25, 13, .55);
            backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
            padding: 16px
        }

        .insight-modal.show {
            display: flex;
            animation: modalBgIn .2s ease
        }

        @keyframes modalBgIn {
            from {
                opacity: 0
            }

            to {
                opacity: 1
            }
        }

        .insight-modal-content {
            background: var(--white);
            border-radius: 20px;
            width: 100%;
            max-width: 460px;
            max-height: 88vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 2px 4px rgba(0, 0, 0, .04), 0 20px 60px rgba(30, 42, 26, .22);
            animation: modalUp .26s cubic-bezier(.34, 1.2, .64, 1)
        }

        @keyframes modalUp {
            from {
                transform: translateY(28px) scale(.97);
                opacity: 0
            }

            to {
                transform: translateY(0) scale(1);
                opacity: 1
            }
        }

        .insight-modal-content::before {
            content: '';
            display: block;
            height: 3px;
            background: linear-gradient(90deg, var(--sage), var(--sage-light), var(--gold));
            border-radius: 20px 20px 0 0
        }

        .insight-modal-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding: 20px 22px 14px;
            gap: 12px;
            border-bottom: 1px solid var(--border-light)
        }

        .insight-modal-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: inset 0 0 0 1px rgba(0,0,0,.04);
        }

        .insight-modal-icon svg {
            width: 24px;
            height: 24px;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .insight-modal-header {
            padding: 22px 24px 16px;
            background: linear-gradient(180deg, var(--cream) 0%, var(--white) 100%);
        }

        .insight-modal-titles {
            flex: 1
        }

        .insight-modal-title {
            font-family: 'Lora', serif;
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2
        }

        .insight-modal-period {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 3px;
            font-family: 'DM Mono', monospace
        }

        .insight-close {
            width: 30px;
            height: 30px;
            background: var(--cream-2);
            border: 1px solid var(--border-light);
            border-radius: 8px;
            font-size: 17px;
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all .18s
        }

        .insight-close:hover {
            background: var(--cream-3)
        }

        .insight-modal-body {
            padding: 18px 22px 22px;
            overflow-y: auto;
            flex: 1
        }

        .insight-modal-body::-webkit-scrollbar {
            width: 3px
        }

        .insight-modal-body::-webkit-scrollbar-thumb {
            background: var(--cream-3);
            border-radius: 2px
        }

        .insight-value-big {
            font-family: 'Lora', serif;
            font-size: 36px;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1;
            margin-bottom: 4px
        }

        .insight-value-unit {
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
            font-weight: 500;
            color: var(--text-muted)
        }

        .insight-change-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 18px
        }

        .insight-section-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 10px;
            margin-top: 18px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-light)
        }

        .insight-section-label:first-of-type {
            margin-top: 0
        }

        .insight-kv-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 4px
        }

        .insight-kv {
            background: var(--cream);
            border: 1px solid var(--border-light);
            border-radius: 10px;
            padding: 11px 13px
        }

        .insight-kv-label {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .6px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 4px
        }

        .insight-kv-value {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary)
        }

        .insight-note {
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.6;
            background: var(--sage-pale);
            border-radius: 10px;
            padding: 12px 14px;
            border-left: 3px solid var(--sage-light)
        }

        .insight-mini-bar-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 9px
        }

        .insight-mini-bar-label {
            font-size: 12.5px;
            color: var(--text-secondary);
            width: 80px;
            flex-shrink: 0
        }

        .insight-mini-bar-track {
            flex: 1;
            height: 7px;
            background: var(--cream-2);
            border-radius: 4px;
            overflow: hidden
        }

        .insight-mini-bar-fill {
            height: 100%;
            border-radius: 4px;
            transition: width .8s cubic-bezier(.4, 0, .2, 1)
        }

        .insight-mini-bar-val {
            font-size: 12px;
            font-family: 'DM Mono', monospace;
            color: var(--text-primary);
            font-weight: 600;
            width: 60px;
            text-align: right;
            flex-shrink: 0
        }

        /* Responsive */
        @media(max-width:1200px) and (min-width:1025px) {
            .stat-row {
                grid-template-columns: repeat(2, 1fr)
            }

            .charts-row {
                grid-template-columns: 1fr 320px
            }
        }

        @media(max-width:1024px) and (min-width:769px) {
            .reports-content {
                padding: 16px 18px
            }

            .stat-row {
                grid-template-columns: repeat(2, 1fr)
            }

            .charts-row {
                grid-template-columns: 1fr
            }

            .breakdown-row {
                grid-template-columns: 1fr
            }

            .chart-area {
                height: 180px
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

            .reports-content {
                overflow: visible !important;
                height: auto !important;
                padding: 12px 12px 24px
            }

            .period-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 10px
            }

            .period-bar-left {
                justify-content: space-between
            }

            .period-tabs {
                display: grid;
                grid-template-columns: 1fr 1fr
            }

            .ptab {
                padding: 8px 10px;
                font-size: 13px;
                text-align: center
            }

            .export-btn {
                width: 100%;
                justify-content: center
            }

            .stat-row {
                grid-template-columns: 1fr 1fr;
                gap: 10px
            }

            .stat-card {
                padding: 14px
            }

            .stat-card-value {
                font-size: 22px
            }

            .charts-row {
                grid-template-columns: 1fr
            }

            .breakdown-row {
                grid-template-columns: 1fr
            }

            .chart-area {
                height: 180px
            }

            .chart-card {
                padding: 14px
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

        @media(max-width:480px) {
            .stat-row {
                grid-template-columns: 1fr 1fr
            }

            .stat-card-value {
                font-size: 18px
            }

            .stat-card-label {
                font-size: 10px
            }
        }
    </style>
</head>

<body>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-top">
            <div class="logo-wrap" onclick="toggleSidebar()" title="Toggle sidebar">
                <div class="logo-icon"><img src="pictures/RiceWise_Logo.png" alt="RiceWise"
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

            <a href="reports.php" class="nav-item active" data-tooltip="Reports"><svg viewBox="0 0 24 24">
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
                Reports
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
            <div class="nd-foot"><a href="notifications.php">View all</a></div>
        </div>
        <div class="profile-drop" id="profileDrop">
            <h3 id="profileName"><?= htmlspecialchars($user['name']) ?></h3>
            <p id="profileEmail"><?= htmlspecialchars($user['email']) ?></p>
            <hr>
            <a href="profile.php" class="pd-btn">My Profile</a>
            <a href="logout.php" class="pd-btn">Sign out</a>
        </div>

        <div class="reports-content">
            <!-- Period bar -->
            <div class="period-bar">
                <div class="period-bar-left">
                    <div class="period-tabs">
                        <button class="ptab active" data-period="quarterly">Quarterly</button>
                        <button class="ptab" data-period="yearly">Yearly</button>
                    </div>
                    <div class="period-indicator" id="periodIndicator">Q1 2026 (Jan – Mar)</div>
                </div>
                <button class="export-btn" onclick="exportCSV()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                        <polyline points="7 10 12 15 17 10" />
                        <line x1="12" y1="15" x2="12" y2="3" />
                    </svg>
                    Export
                </button>
            </div>

            <!-- Stat cards -->
            <div class="stat-row">
                <div class="stat-card" onclick="openInsight('harvest')">
                    <div class="stat-info-btn">i</div>
                    <div class="stat-card-top"><span class="stat-card-label">Total Harvest</span>
                        <div class="stat-card-icon green"><svg viewBox="0 0 24 24"><path d="M3 17l6-6 4 4 8-8v10H3z"/></svg></div>                    </div>
                    <div class="stat-card-value" id="statHarvest">4,950 <span
                            style="font-size:14px;font-family:'DM Sans';font-weight:500;color:var(--text-muted)">kg</span>
                    </div>
                    <div class="stat-card-sub">vs prev period <span class="change-badge up" id="changeHarvest">↑
                            12%</span></div>
                </div>
                <div class="stat-card" onclick="openInsight('revenue')">
                    <div class="stat-info-btn">i</div>
                    <div class="stat-card-top"><span class="stat-card-label">Total Revenue</span>
                        <div class="stat-card-icon gold"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 9.5c0-1.5 1.3-2.5 3-2.5s3 1 3 2.5S13.5 12 12 12s-3 1-3 2.5 1.3 2.5 3 2.5 3-1 3-2.5"/></svg></div>
                    </div>
                    <div class="stat-card-value" id="statRevenue">₱94,050</div>
                    <div class="stat-card-sub">vs prev period <span class="change-badge up" id="changeRevenue">↑
                            8%</span></div>
                </div>
                <div class="stat-card" onclick="openInsight('expenses')">
                    <div class="stat-info-btn">i</div>
                    <div class="stat-card-top"><span class="stat-card-label">Total Expenses</span>
                        <div class="stat-card-icon red"><svg viewBox="0 0 24 24"><polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/></svg></div>
                    </div>
                    <div class="stat-card-value" id="statExpenses">₱38,300</div>
                    <div class="stat-card-sub">vs prev period <span class="change-badge down" id="changeExpenses">↑
                            3%</span></div>
                </div>
                <div class="stat-card" onclick="openInsight('profit')">
                    <div class="stat-info-btn">i</div>
                    <div class="stat-card-top"><span class="stat-card-label">Net Profit</span>
                        <div class="stat-card-icon blue"><svg viewBox="0 0 24 24"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg></div>
                    </div>
                    <div class="stat-card-value" id="statProfit">₱55,750</div>
                    <div class="stat-card-sub">vs prev period <span class="change-badge up" id="changeProfit">↑
                            11%</span></div>
                </div>
            </div>

            <!-- Charts row -->
            <div class="charts-row">
                <div class="chart-card">
                    <div class="chart-card-head">
                        <div>
                            <div class="chart-card-title">Revenue vs Expenses</div>
                            <div class="chart-card-sub" id="revenueChartSub">Monthly — Q1 2026</div>
                        </div>
                        <div class="chart-head-actions">
                            <div class="chart-legend">
                                <div class="legend-item">
                                    <div class="legend-dot" style="background:#5a7a52"></div> Revenue
                                </div>
                                <div class="legend-item">
                                    <div class="legend-dot" style="background:#c8963e"></div> Expenses
                                </div>
                            </div>
                            <button class="chart-info-btn" onclick="openInsight('revenueChart')">i</button>
                        </div>
                    </div>
                    <div class="chart-area"><canvas id="revenueChart"></canvas></div>
                </div>
                <div class="chart-card">
                    <div class="chart-card-head">
                        <div>
                            <div class="chart-card-title">Harvest by Field</div>
                            <div class="chart-card-sub" id="donutChartSub">Distribution this period</div>
                        </div>
                        <button class="chart-info-btn" onclick="openInsight('donut')">i</button>
                    </div>
                    <div class="donut-wrap">
                        <div class="donut-inner"><canvas id="harvestDonut"></canvas></div>
                    </div>
                    <div class="chart-legend" id="donutLegend" style="justify-content:center;gap:12px;flex-wrap:wrap;margin-top:4px"></div>
                </div>
            </div>

            <!-- Breakdown row -->
            <div class="breakdown-row">
                <div class="breakdown-card">
                    <div class="breakdown-title">Activity Breakdown</div>
                    <div class="breakdown-sub" id="activitySub">Records logged this quarter</div>
                    <div id="activityBars"></div>
                </div>
                <div class="breakdown-card">
                    <div class="breakdown-title">Harvest Volume</div>
                    <div class="breakdown-sub" id="harvestBarSub">Kilograms harvested per month</div>
                    <div style="position:relative;height:200px"><canvas id="harvestBar"></canvas></div>
                </div>
            </div>

            <!-- Records table -->
            <div class="records-table-card">
                <div class="records-table-head">
                    <div>
                        <div class="records-table-title">Recent Records</div>
                        <div class="records-table-sub" id="tableSub">Latest farm activities this quarter</div>
                    </div>
                    <a href="records.php" class="view-all-link">View all &rarr;</a>
                </div>
                <div class="rt-scroll-wrap">
                    <table class="rt">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Activity</th>
                                <th>Field</th>
                                <th>Quantity</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="reportTbody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- INSIGHT MODAL -->
    <div class="insight-modal" id="insightModal">
        <div class="insight-modal-content">
            <div class="insight-modal-header">
                <div class="insight-modal-icon" id="insightIcon"></div>
                <div class="insight-modal-titles">
                    <div class="insight-modal-title" id="insightTitle"></div>
                    <div class="insight-modal-period" id="insightPeriod"></div>
                </div>
                <button class="insight-close" onclick="closeInsight()">×</button>
            </div>
            <div class="insight-modal-body" id="insightBody"></div>
        </div>
    </div>

    <script>
        /* ===== SIDEBAR ===== */
        const isMobile = () => window.innerWidth <= 768;
        (function() {
            if (!isMobile() && localStorage.getItem('sidebarCollapsed') === '1') document.body.classList.add('sidebar-collapsed');
            requestAnimationFrame(() => requestAnimationFrame(() => document.documentElement.classList.remove('sidebar-pre-collapse')));
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

        function rebuildCharts() {
            [revenueChart, donutChart, harvestBarChart].forEach(c => {
                if (c && typeof c.resize === 'function') c.resize();
            });
        }

        /* ===== BELL DROPDOWN — live data, same pattern as dashboard.php / records.php ===== */
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
        const ND_STAR_SVG = '<svg viewBox="0 0 24 24" width="11" height="11" fill="var(--star,#e6a817)" stroke="var(--star,#e6a817)" stroke-width="1.5" style="vertical-align:-1px;margin-right:3px;flex-shrink:0"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';

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

        /* ===== DATA =====
           Real numbers, computed server-side in PHP from this user's
           farm_records (see the aggregation block at the top of this file). */
        const DATA = <?= $jsReportData ?>;

        let currentPeriod = 'quarterly';
        let revenueChart, donutChart, harvestBarChart;

        function initCharts(period) {
            const d = DATA[period];
            const chartDefaults = {
                responsive: true,
                maintainAspectRatio: false
            };

            if (revenueChart) revenueChart.destroy();
            revenueChart = new Chart(document.getElementById('revenueChart'), {
                type: 'line',
                data: {
                    labels: d.revenueChart.labels,
                    datasets: [{
                            label: 'Revenue',
                            data: d.revenueChart.revenue,
                            borderColor: '#5a7a52',
                            backgroundColor: 'rgba(90,122,82,.1)',
                            fill: true,
                            borderWidth: 2.5,
                            tension: .4,
                            pointRadius: 5,
                            pointBackgroundColor: '#5a7a52',
                            pointBorderColor: 'white',
                            pointBorderWidth: 2
                        },
                        {
                            label: 'Expenses',
                            data: d.revenueChart.expenses,
                            borderColor: '#c8963e',
                            backgroundColor: 'rgba(200,150,62,.08)',
                            fill: true,
                            borderWidth: 2.5,
                            tension: .4,
                            pointRadius: 5,
                            pointBackgroundColor: '#c8963e',
                            pointBorderColor: 'white',
                            pointBorderWidth: 2
                        }
                    ]
                },
                options: {
                    ...chartDefaults,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(30,42,26,.92)',
                            bodyColor: '#fff',
                            titleColor: 'rgba(255,255,255,.7)',
                            padding: 12,
                            cornerRadius: 10,
                            callbacks: {
                                label: c => ' ₱' + c.raw.toLocaleString()
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,.04)'
                            },
                            ticks: {
                                callback: v => '₱' + (v / 1000).toFixed(0) + 'k',
                                font: {
                                    size: 11
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 12
                                }
                            }
                        }
                    }
                }
            });

            if (donutChart) donutChart.destroy();
            donutChart = new Chart(document.getElementById('harvestDonut'), {
                type: 'doughnut',
                data: {
                    labels: d.donut.labels,
                    datasets: [{
                        data: d.donut.data,
                        backgroundColor: d.donut.colors,
                        borderColor: '#fff',
                        borderWidth: 3,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '62%',
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(30,42,26,.92)',
                            bodyColor: '#fff',
                            padding: 11,
                            cornerRadius: 10,
                            callbacks: {
                                label: c => ' ' + c.label + ': ' + c.raw.toLocaleString() + ' kg'
                            }
                        }
                    }
                }
            });

            if (harvestBarChart) harvestBarChart.destroy();
            harvestBarChart = new Chart(document.getElementById('harvestBar'), {
                type: 'bar',
                data: {
                    labels: d.harvestBar.labels,
                    datasets: [{
                        label: 'Harvest (kg)',
                        data: d.harvestBar.data,
                        backgroundColor: 'rgba(90,122,82,.18)',
                        borderColor: '#5a7a52',
                        borderWidth: 2,
                        borderRadius: 7
                    }]
                },
                options: {
                    ...chartDefaults,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: c => ' ' + c.raw.toLocaleString() + ' kg'
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,.04)'
                            },
                            ticks: {
                                font: {
                                    size: 11
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 12
                                }
                            }
                        }
                    }
                }
            });
        }

        const ACTIVITY_ICON_MAP = {
            'Harvesting':   '<svg viewBox="0 0 24 24"><path d="M3 17l6-6 4 4 8-8v10H3z"/></svg>',
            'Planting':     '<svg viewBox="0 0 24 24"><path d="M12 22V12"/><path d="M12 12c-3-5-8-5-8-5 0 6 5 8 8 8"/><path d="M12 12c3-5 8-5 8-5 0 6-5 8-8 8"/></svg>',
            'Fertilizing':  '<svg viewBox="0 0 24 24"><path d="M9 3h6l1 4H8z"/><path d="M8 7h8l1 13a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1z"/></svg>',
            'Irrigation':   '<svg viewBox="0 0 24 24"><path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/></svg>',
            'Pest Control': '<svg viewBox="0 0 24 24"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="M8.5 8.5 16 16"/></svg>',
            'Sales':        '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 9.5c0-1.5 1.3-2.5 3-2.5s3 1 3 2.5S13.5 12 12 12s-3 1-3 2.5 1.3 2.5 3 2.5 3-1 3-2.5"/></svg>',
        };  
        
        function updateUI(period) {
            const d = DATA[period];
            const isQ = period === 'quarterly';

            document.getElementById('periodIndicator').textContent = d.label;
            document.getElementById('revenueChartSub').textContent = (isQ ? 'Monthly — ' : 'Quarterly — ') + d.label;
            document.getElementById('donutChartSub').textContent = 'Distribution · ' + d.label;
            document.getElementById('activitySub').textContent = isQ ? 'Records logged this quarter' : 'Records logged this year';
            document.getElementById('harvestBarSub').textContent = isQ ? 'Kilograms per month' : 'Kilograms per quarter';
            document.getElementById('tableSub').textContent = isQ ? 'Latest farm activities this quarter' : 'Latest farm activities this year';

            // Donut legend — built from whatever locations actually appear in this period's harvests
            const legendEl = document.getElementById('donutLegend');
            legendEl.innerHTML = d.donut.labels.length ?
                d.donut.labels.map((l, i) => `<div class="legend-item"><div class="legend-dot" style="background:${d.donut.colors[i]}"></div> ${l}</div>`).join('') :
                '<div class="legend-item" style="color:var(--text-muted)">No harvest records logged this period yet</div>';

            // Stat cards
            const harvestEl = document.getElementById('statHarvest');
            const [harvestNum, harvestUnit] = d.harvest.val.includes('kg') ? [d.harvest.val.replace(' kg', ''), 'kg'] : [d.harvest.val, ''];
            harvestEl.innerHTML = harvestNum + (harvestUnit ? ` <span style="font-size:14px;font-family:'DM Sans';font-weight:500;color:var(--text-muted)">${harvestUnit}</span>` : '');
            document.getElementById('statRevenue').textContent = d.revenue.val;
            document.getElementById('statExpenses').textContent = d.expenses.val;
            document.getElementById('statProfit').textContent = d.profit.val;

            ['harvest', 'revenue', 'expenses', 'profit'].forEach(k => {
                const el = document.getElementById('change' + k.charAt(0).toUpperCase() + k.slice(1));
                el.textContent = (DATA[period][k].dir === 'up' ? '↑ ' : '↓ ') + DATA[period][k].change.replace('+', '').replace('-', '');
                el.className = 'change-badge ' + DATA[period][k].dir;
            });

            // Activity bars
                        const barsEl = document.getElementById('activityBars');
            barsEl.innerHTML = d.activities.length ? d.activities.map(a => `
        <div class="bar-item">
            <div class="bar-row"><span class="bar-label"><span class="bar-icon" style="background:${a.color}22">${ACTIVITY_ICON_MAP[a.type] || ''}</span>${a.label}</span><span class="bar-val">${a.records} rec · ${a.qty}</span></div>
            <div class="bar-track"><div class="bar-fill" style="width:0%;background:${a.color}" data-w="${a.pct}"></div></div>
        </div>`).join('') : '<div style="font-size:13px;color:var(--text-muted);padding:8px 0">No records logged this period yet.</div>';
            setTimeout(() => {
                barsEl.querySelectorAll('.bar-fill').forEach(el => {
                    el.style.width = el.dataset.w + '%';
                });
            }, 80);

            // Records table
            const tbody = document.getElementById('reportTbody');
            tbody.innerHTML = d.records.length ? d.records.map(r => `
        <tr>
            <td>${r.date}</td>
            <td>${r.activity}</td>
            <td>${r.field}</td>
            <td>${r.qty}</td>
            <td><span class="badge ${r.status}">${r.status === 'completed' ? 'Completed' : r.status === 'in-progress' ? 'In Progress' : 'Pending'}</span></td>
        </tr>`).join('') : '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:24px 20px">No records logged this period yet.</td></tr>';

            initCharts(period);
        }

        /* ===== PERIOD TABS ===== */
        document.querySelectorAll('.ptab').forEach(t => {
            t.addEventListener('click', function() {
                document.querySelectorAll('.ptab').forEach(x => x.classList.remove('active'));
                this.classList.add('active');
                currentPeriod = this.dataset.period;
                updateUI(currentPeriod);
            });
        });

        /* ===== INSIGHT MODAL DATA ===== */
        const INSIGHTS = {
            harvest: (period) => {
                const d = DATA[period];
                const q = d.harvest;
                const hasFields = d.donut.labels.length > 0;
                const fieldCount = d.donut.labels.length || 1;
                let bestIdx = 0;
                d.donut.data.forEach((v, i) => {
                    if (v > d.donut.data[bestIdx]) bestIdx = i;
                });
                const harvestEntry = d.activities.find(a => a.label.includes('Harvesting'));
                return {
                    icon: '<svg viewBox="0 0 24 24" style="stroke:var(--sage-dark)"><path d="M3 17l6-6 4 4 8-8v10H3z"/></svg>',
					iconBg: 'var(--sage-pale)',
                    title: 'Total Harvest',
                    valueBig: q.raw.toLocaleString(),
                    valueUnit: 'kg',
                    change: q.change,
                    dir: q.dir,
                    sections: [{
                            label: 'Breakdown',
                            kvs: hasFields ?
                                d.donut.labels.map((l, i) => ({
                                    k: l,
                                    v: Math.round(d.donut.data[i]).toLocaleString() + ' kg'
                                })) :
                                [{
                                    k: 'No data yet',
                                    v: '—'
                                }]
                        },
                        {
                            label: 'Key Metrics',
                            kvs: [{
                                    k: 'Avg per Field',
                                    v: hasFields ? Math.round(q.raw / fieldCount).toLocaleString() + ' kg' : '—'
                                },
                                {
                                    k: 'Best Field',
                                    v: hasFields ? d.donut.labels[bestIdx] : '—'
                                },
                                {
                                    k: 'Harvest Records',
                                    v: (harvestEntry ? harvestEntry.records : 0) + ' entries'
                                },
                                {
                                    k: 'Price/kg',
                                    v: q.raw > 0 ? '₱' + Math.round(d.revenue.raw / q.raw) : '—'
                                },
                            ]
                        },
                    ],
                    note: 'Harvest volume is directly tied to irrigation scheduling and fertilizer timing. Consistent water supply during the grain-filling stage significantly boosts yield.'
                };
            },
            revenue: (period) => {
                const d = DATA[period];
                const q = d.revenue;
                const months = d.revenueChart.labels;
                const revs = d.revenueChart.revenue;
                const maxRev = Math.max(...revs, 0);
                return {
                    icon: '<svg viewBox="0 0 24 24" style="stroke:var(--gold)"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 9.5c0-1.5 1.3-2.5 3-2.5s3 1 3 2.5S13.5 12 12 12s-3 1-3 2.5 1.3 2.5 3 2.5 3-1 3-2.5"/></svg>',
					iconBg: 'var(--gold-pale)',
                    title: 'Total Revenue',
                    valueBig: '₱' + q.raw.toLocaleString(),
                    valueUnit: '',
                    change: q.change,
                    dir: q.dir,
                    sections: [{
                            label: 'Revenue per Period',
                            miniBars: months.map((m, i) => ({
                                label: m,
                                val: '₱' + (revs[i] / 1000).toFixed(0) + 'k',
                                pct: maxRev > 0 ? Math.round(revs[i] / maxRev * 100) : 0,
                                color: '#5a7a52'
                            }))
                        },
                        {
                            label: 'Revenue Details',
                            kvs: [{
                                    k: 'Avg Price/kg',
                                    v: d.harvest.raw > 0 ? '₱' + Math.round(q.raw / d.harvest.raw) : '—'
                                },
                                {
                                    k: 'Best Month',
                                    v: maxRev > 0 ? months[revs.indexOf(maxRev)] : '—'
                                },
                                {
                                    k: 'Profit Margin',
                                    v: q.raw > 0 ? Math.round((d.profit.raw / q.raw) * 100) + '%' : '—'
                                },
                                {
                                    k: 'vs Last Period',
                                    v: q.change
                                },
                            ]
                        },
                    ],
                    note: 'Revenue here reflects what you\'ve logged under the Sales activity type. Recording every sale promptly keeps this figure — and your profit margin — accurate.'
                };
            },
            expenses: (period) => {
                const d = DATA[period];
                const q = d.expenses;
                // Real breakdown: whichever of Planting/Fertilizing/Irrigation/Pest Control
                // actually have records this period — not a fabricated fixed split.
                const expenseCats = d.activities.filter(a => /Planting|Fertilizing|Irrigation|Pest Control/.test(a.label));
                const expenseCatsMax = Math.max(...expenseCats.map(a => {
                    const n = parseFloat(a.qty.replace(/[₱,]/g, ''));
                    return isNaN(n) ? 0 : n;
                }), 0);
                let largest = expenseCats.length ? expenseCats.reduce((best, a) => {
                    const n = parseFloat(a.qty.replace(/[₱,]/g, '')) || 0;
                    const bestN = parseFloat(best.qty.replace(/[₱,]/g, '')) || 0;
                    return n > bestN ? a : best;
                }) : null;
                return {
                    icon: '<svg viewBox="0 0 24 24" style="stroke:var(--red)"><polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/></svg>',
					iconBg: '#fde8e8',
                    title: 'Total Expenses',
                    valueBig: '₱' + q.raw.toLocaleString(),
                    valueUnit: '',
                    change: q.change,
                    dir: q.dir,
                    sections: [{
                            label: 'Expense Breakdown',
                            miniBars: expenseCats.length ? expenseCats.map(a => {
                                const n = parseFloat(a.qty.replace(/[₱,]/g, '')) || 0;
                                return {
                                    label: a.label.replace(/^\S+\s/, ''),
                                    val: a.qty,
                                    pct: expenseCatsMax > 0 ? Math.round(n / expenseCatsMax * 100) : 0,
                                    color: a.color
                                };
                            }) : []
                        },
                        {
                            label: 'Cost Metrics',
                            kvs: [{
                                    k: 'Cost per kg',
                                    v: d.harvest.raw > 0 ? '₱' + Math.round(q.raw / d.harvest.raw) : '—'
                                },
                                {
                                    k: 'vs Revenue',
                                    v: d.revenue.raw > 0 ? Math.round(q.raw / d.revenue.raw * 100) + '% of rev' : '—'
                                },
                                {
                                    k: 'vs Last Period',
                                    v: q.change
                                },
                                {
                                    k: 'Largest Cost',
                                    v: largest ? largest.label.replace(/^\S+\s/, '') : '—'
                                },
                            ]
                        },
                    ],
                    note: 'Expenses are totaled from Planting, Fertilizing, Irrigation, and Pest Control records logged this period. Bulk purchasing of inputs at the start of the season can reduce costs by 10–18%.'
                };
            },
            profit: (period) => {
                const d = DATA[period];
                const q = d.profit;
                const hasFields = d.donut.labels.length > 0 && d.donut.total > 0;
                return {
                    icon: '<svg viewBox="0 0 24 24" style="stroke:var(--blue)"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>',
					iconBg: '#e8f2fd',
                    title: 'Net Profit',
                    valueBig: '₱' + q.raw.toLocaleString(),
                    valueUnit: '',
                    change: q.change,
                    dir: q.dir,
                    sections: [{
                            label: 'Profitability',
                            kvs: [{
                                    k: 'Revenue',
                                    v: d.revenue.val
                                },
                                {
                                    k: 'Expenses',
                                    v: d.expenses.val
                                },
                                {
                                    k: 'Net Profit',
                                    v: q.val
                                },
                                {
                                    k: 'Margin',
                                    v: d.revenue.raw > 0 ? Math.round(q.raw / d.revenue.raw * 100) + '%' : '—'
                                },
                            ]
                        },
                        {
                            // Estimated by splitting total profit proportionally to each field's
                            // share of harvest volume this period — an estimate, not a direct measurement.
                            label: 'Per Field Estimate (by harvest share)',
                            kvs: hasFields ?
                                d.donut.labels.map((l, i) => ({
                                    k: l,
                                    v: '₱' + Math.round(q.raw * (d.donut.data[i] / d.donut.total)).toLocaleString()
                                })) :
                                [{
                                    k: 'No harvest data yet',
                                    v: '—'
                                }]
                        },
                    ],
                    note: 'Your profit margin reflects revenue recorded under Sales minus costs from Planting, Fertilizing, Irrigation, and Pest Control this period. Reducing post-harvest losses through proper drying facilities can further improve margins.'
                };
            },
            revenueChart: (period) => {
                const d = DATA[period];
                return {
                    icon: '<svg viewBox="0 0 24 24" style="stroke:var(--sage-dark)"><polyline points="3 17 9 11 13 15 21 6"/><polyline points="14 6 21 6 21 13"/></svg>',
					iconBg: 'var(--sage-pale)',
                    title: 'Revenue vs Expenses Chart',
                    valueBig: '',
                    valueUnit: '',
                    change: null,
                    dir: null,
                    sections: [{
                            label: 'How to Read This Chart',
                            kvs: [{
                                    k: 'Green Line',
                                    v: 'Total Revenue (₱)'
                                },
                                {
                                    k: 'Gold Line',
                                    v: 'Total Expenses (₱)'
                                },
                                {
                                    k: 'Gap = Profit',
                                    v: 'Wider gap = higher profit'
                                },
                                {
                                    k: 'Period',
                                    v: d.label
                                },
                            ]
                        },
                        {
                            label: period === 'quarterly' ? 'Monthly Figures' : 'Quarterly Figures',
                            miniBars: (() => {
                                const maxRev = Math.max(...d.revenueChart.revenue, 0);
                                return d.revenueChart.labels.map((l, i) => ({
                                    label: l,
                                    val: '₱' + (d.revenueChart.revenue[i] / 1000).toFixed(0) + 'k',
                                    pct: maxRev > 0 ? Math.round(d.revenueChart.revenue[i] / maxRev * 100) : 0,
                                    color: '#5a7a52'
                                }));
                            })()
                        },
                    ],
                    note: 'A consistent upward trend in revenue relative to expenses is a positive sign. Watch for periods where the gap narrows — these may signal rising input costs or lower yield.'
                };
            },
            donut: (period) => {
                const d = DATA[period];
                const hasFields = d.donut.labels.length > 0;
                const maxV = hasFields ? Math.max(...d.donut.data) : 1;
                let bestIdx = 0;
                d.donut.data.forEach((v, i) => {
                    if (v > d.donut.data[bestIdx]) bestIdx = i;
                });
                return {
                    icon: '<svg viewBox="0 0 24 24" style="stroke:var(--sage-dark)"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>',
					iconBg: 'var(--sage-pale)',
                    title: 'Harvest by Field',
                    valueBig: '',
                    valueUnit: '',
                    change: null,
                    dir: null,
                    sections: [{
                            label: 'Field Contributions',
                            miniBars: hasFields ? d.donut.labels.map((f, i) => ({
                                label: f,
                                val: d.donut.data[i].toLocaleString() + ' kg',
                                pct: Math.round(d.donut.data[i] / maxV * 100),
                                color: d.donut.colors[i]
                            })) : []
                        },
                        {
                            label: 'Share of Total',
                            kvs: hasFields ? d.donut.labels.map((f, i) => ({
                                k: f,
                                v: Math.round(d.donut.data[i] / d.donut.total * 100) + '%'
                            })) : [{
                                k: 'No harvest records yet',
                                v: '—'
                            }]
                        },
                    ],
                    note: hasFields ?
                        `${d.donut.labels[bestIdx]} currently leads in yield this period. Fields that lag behind may benefit from reviewing irrigation access and soil amendments used on your top-performing field.` :
                        'Log a Harvesting record to start seeing which fields are producing the most.'
                };
            }
        };

       function openInsight(key) {
            const fn = INSIGHTS[key];
            if (!fn) return;
            const ins = fn(currentPeriod);
            document.getElementById('insightIcon').innerHTML = ins.icon;
            document.getElementById('insightIcon').style.background = ins.iconBg;
            document.getElementById('insightTitle').textContent = ins.title;
            document.getElementById('insightPeriod').textContent = DATA[currentPeriod].label;
            let html = '';
            if (ins.valueBig) {
                html += `<div class="insight-value-big">${ins.valueBig} <span class="insight-value-unit">${ins.valueUnit}</span></div>`;
                if (ins.change) {
                    html += `<div class="insight-change-row"><span class="change-badge ${ins.dir}">${ins.dir === 'up' ? '↑' : '↓'} ${ins.change.replace('+', '').replace('-', '')}</span><span style="font-size:12.5px;color:var(--text-muted)">vs previous period</span></div>`;
                }
            }

            ins.sections.forEach(sec => {
                html += `<div class="insight-section-label">${sec.label}</div>`;
                if (sec.kvs) {
                    html += `<div class="insight-kv-grid">`;
                    sec.kvs.forEach(kv => {
                        html += `<div class="insight-kv"><div class="insight-kv-label">${kv.k}</div><div class="insight-kv-value">${kv.v}</div></div>`;
                    });
                    html += `</div>`;
                }
                if (sec.miniBars) {
                    sec.miniBars.forEach(b => {
                        html += `<div class="insight-mini-bar-item"><span class="insight-mini-bar-label">${b.label}</span><div class="insight-mini-bar-track"><div class="insight-mini-bar-fill" style="width:0%;background:${b.color}" data-w="${b.pct}"></div></div><span class="insight-mini-bar-val">${b.val}</span></div>`;
                    });
                }
            });

            if (ins.note) {
                html += `<div class="insight-section-label">Insight</div><div class="insight-note">${ins.note}</div>`;
            }

            document.getElementById('insightBody').innerHTML = html;
            document.getElementById('insightModal').classList.add('show');
            setTimeout(() => {
                document.querySelectorAll('.insight-mini-bar-fill').forEach(el => {
                    el.style.width = el.dataset.w + '%';
                });
            }, 100);
        }

        function closeInsight() {
            document.getElementById('insightModal').classList.remove('show');
        }
        document.getElementById('insightModal').addEventListener('click', function(e) {
            if (e.target === this) closeInsight();
        });

        /* ===== EXPORT TO EXCEL =====
           Same technique as records.php: build a styled HTML table and hand
           it to Excel as an .xls file (with the RiceWise sage/gold theme),
           so opening it shows real colored headers instead of a flat CSV grid. */
        function exportCSV() {
            const d = DATA[currentPeriod];
            const isQ = currentPeriod === 'quarterly';
            const fmtNum = n => Number(n).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
            const todayStr = new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });

            const escapeHtml = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

            // ── Section: Summary stats ──
            const statRows = [
                ['Total Harvest', d.harvest.val, d.harvest.change, d.harvest.dir],
                ['Total Revenue', d.revenue.val, d.revenue.change, d.revenue.dir],
                ['Total Expenses', d.expenses.val, d.expenses.change, d.expenses.dir],
                ['Net Profit', d.profit.val, d.profit.change, d.profit.dir],
            ].map(([label, val, change, dir]) => `
                <tr>
                    <td style="padding:6px 10px;border:1px solid #ddd8cf;">${label}</td>
                    <td style="padding:6px 10px;border:1px solid #ddd8cf;font-weight:bold;" align="right">${val}</td>
                    <td style="padding:6px 10px;border:1px solid #ddd8cf;color:${dir === 'up' ? '#1e5c1e' : '#c82333'};font-weight:bold;" align="right">${dir === 'up' ? '&#8593;' : '&#8595;'} ${change}</td>
                </tr>`).join('');

            // ── Section: Activity breakdown ──
            const activityRows = d.activities.length ? d.activities.map(a => `
                <tr>
                    <td style="padding:6px 10px;border:1px solid #ddd8cf;">${escapeHtml(a.label)}</td>
                    <td style="padding:6px 10px;border:1px solid #ddd8cf;" align="right">${a.records}</td>
                    <td style="padding:6px 10px;border:1px solid #ddd8cf;" align="right">${escapeHtml(a.qty)}</td>
                </tr>`).join('') :
                `<tr><td colspan="3" style="padding:6px 10px;border:1px solid #ddd8cf;color:#94a18e;">No records logged this period yet.</td></tr>`;

            // ── Section: Recent records ──
            const recordRows = d.records.length ? d.records.map(r => `
                <tr>
                    <td style="padding:6px 10px;border:1px solid #ddd8cf;">${r.date}</td>
                    <td style="padding:6px 10px;border:1px solid #ddd8cf;">${escapeHtml(r.activity)}</td>
                    <td style="padding:6px 10px;border:1px solid #ddd8cf;">${escapeHtml(r.field)}</td>
                    <td style="padding:6px 10px;border:1px solid #ddd8cf;" align="right">${escapeHtml(r.qty)}</td>
                    <td style="padding:6px 10px;border:1px solid #ddd8cf;">${r.status === 'completed' ? 'Completed' : r.status === 'in-progress' ? 'In Progress' : 'Pending'}</td>
                </tr>`).join('') :
                `<tr><td colspan="5" style="padding:6px 10px;border:1px solid #ddd8cf;color:#94a18e;">No records logged this period yet.</td></tr>`;

            const sectionHeader = (label, cols) => `
                <tr><td colspan="${cols}" style="padding:8px;"></td></tr>
                <tr><td colspan="${cols}" style="background:#c8963e;color:#ffffff;font-weight:bold;font-size:13px;padding:7px 10px;border:1px solid #b8842e;">${label}</td></tr>`;

            const html = `
                <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
                <head><meta charset="UTF-8">
                <!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
                <x:Name>RiceWise Report</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
                </x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
                </head>
                <body>
                <table border="0" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:13px;">
                    <tr><td colspan="5" style="background:#3d5c38;color:#ffffff;font-size:20px;font-weight:bold;padding:14px 10px;">RiceWise Report — ${d.label}</td></tr>
                    <tr><td colspan="5" style="padding:2px 10px;color:#5a6655;font-size:11px;">Exported ${todayStr} &middot; ${isQ ? 'Quarterly' : 'Yearly'} view</td></tr>

                    ${sectionHeader('Summary', 5).replace('colspan="5"', 'colspan="3"')}
                    <tr>
                        <td style="background:#5a7a52;color:#ffffff;font-weight:bold;padding:7px 10px;border:1px solid #ddd8cf;">Metric</td>
                        <td style="background:#5a7a52;color:#ffffff;font-weight:bold;padding:7px 10px;border:1px solid #ddd8cf;" align="right">Value</td>
                        <td style="background:#5a7a52;color:#ffffff;font-weight:bold;padding:7px 10px;border:1px solid #ddd8cf;" align="right">vs Prev Period</td>
                    </tr>
                    ${statRows}

                    ${sectionHeader('Activity Breakdown', 3)}
                    <tr>
                        <td style="background:#5a7a52;color:#ffffff;font-weight:bold;padding:7px 10px;border:1px solid #ddd8cf;">Activity</td>
                        <td style="background:#5a7a52;color:#ffffff;font-weight:bold;padding:7px 10px;border:1px solid #ddd8cf;" align="right">Records</td>
                        <td style="background:#5a7a52;color:#ffffff;font-weight:bold;padding:7px 10px;border:1px solid #ddd8cf;" align="right">Total Qty</td>
                    </tr>
                    ${activityRows}

                    ${sectionHeader('Recent Records', 5)}
                    <tr>
                        <td style="background:#5a7a52;color:#ffffff;font-weight:bold;padding:7px 10px;border:1px solid #ddd8cf;">Date</td>
                        <td style="background:#5a7a52;color:#ffffff;font-weight:bold;padding:7px 10px;border:1px solid #ddd8cf;">Activity</td>
                        <td style="background:#5a7a52;color:#ffffff;font-weight:bold;padding:7px 10px;border:1px solid #ddd8cf;">Field</td>
                        <td style="background:#5a7a52;color:#ffffff;font-weight:bold;padding:7px 10px;border:1px solid #ddd8cf;" align="right">Quantity</td>
                        <td style="background:#5a7a52;color:#ffffff;font-weight:bold;padding:7px 10px;border:1px solid #ddd8cf;">Status</td>
                    </tr>
                    ${recordRows}
                </table>
                </body></html>`;

            const blob = new Blob(['\ufeff', html], { type: 'application/vnd.ms-excel' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `ricewise-report-${currentPeriod}-${new Date().toISOString().slice(0, 10)}.xls`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        }

        /* ===== INIT ===== */
        updateUI('quarterly');
    </script>
</body>

</html>