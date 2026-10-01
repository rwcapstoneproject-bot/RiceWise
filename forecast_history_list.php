<?php
/*
 * forecast_history_list.php?type=profit|weather|rice
 *
 * For every target period we take the LAST forecast issued before that period
 * began, then attach what actually happened (from the history tables) and the
 * error. Rows whose period hasn't ended yet are marked upcoming / in_progress
 * and get no error, so we never grade a forecast before the answer is known.
 *
 * Actual values are looked up with subqueries that return a single row, so
 * duplicate rows in the history tables can't duplicate a forecast row here.
 */
require_once 'config.php';
require_once __DIR__ . '/forecast_tables.php';
date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json');

if (!isset($_SESSION['rw_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not signed in']);
    exit;
}
$uid   = (int)$_SESSION['rw_user_id'];
$type  = $_GET['type'] ?? '';
$today = date('Y-m-d');

function rw_status(string $start, string $end, string $today): string
{
    if ($start > $today) return 'upcoming';
    if ($end < $today)   return 'closed';
    return 'in_progress';
}
function rw_num($v): ?float
{
    return ($v === null || $v === '') ? null : (float)$v;
}
function rw_fetch(mysqli $conn, string $sql, string $types, array $params): array
{
    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

$rows = [];
$unit = '%';

try {
    switch ($type) {

        case 'profit':
            $sql = "SELECT f.forecast_period, f.period_date, f.issued_date, f.blended_value,
                           f.lstm_value, f.rf_value, f.is_estimated,
                           (SELECT h.net_profit FROM " . RW_TBL_PROFIT_HISTORY . " h
                             WHERE h.user_id = f.user_id AND h.period_label = f.forecast_period
                             ORDER BY h.id DESC LIMIT 1) AS actual
                    FROM profit_forecast_runs f
                    JOIN (SELECT forecast_period, MAX(issued_date) AS issued_date
                          FROM profit_forecast_runs
                          WHERE user_id = ? AND issued_date < period_date
                          GROUP BY forecast_period) m
                      ON m.forecast_period = f.forecast_period AND m.issued_date = f.issued_date
                    WHERE f.user_id = ?
                    ORDER BY f.period_date DESC
                    LIMIT 8";
            foreach (rw_fetch($conn, $sql, 'ii', [$uid, $uid]) as $r) {
                $start = $r['period_date'];
                $end   = date('Y-m-d', strtotime($start . ' +3 months -1 day'));
                $rows[] = [
                    'label'     => $r['forecast_period'],
                    'target'    => $start,
                    'issued'    => $r['issued_date'],
                    'status'    => rw_status($start, $end, $today),
                    'forecast'  => (float)$r['blended_value'],
                    'lstm'      => rw_num($r['lstm_value']),
                    'rf'        => rw_num($r['rf_value']),
                    'actual'    => rw_num($r['actual']),
                    'estimated' => (bool)$r['is_estimated'],
                    'extra'     => null,
                ];
            }
            $unit = '%';
            break;

        case 'weather':
            // weather_history is per LOCATION, so find this user's coordinates
            // (same fallback the dashboard uses).
            $loc = rw_fetch($conn, "SELECT farm_lat, farm_lng FROM users WHERE id = ? LIMIT 1", 'i', [$uid]);
            $lat = isset($loc[0]['farm_lat']) && $loc[0]['farm_lat'] !== null ? (float)$loc[0]['farm_lat'] : 14.937936;
            $lng = isset($loc[0]['farm_lng']) && $loc[0]['farm_lng'] !== null ? (float)$loc[0]['farm_lng'] : 120.927572;
            $latS = sprintf('%.6f', $lat);
            $lngS = sprintf('%.6f', $lng);

            $wh   = RW_TBL_WEATHER_HISTORY;
            $dc   = RW_COL_WEATHER_DATE;
            $la   = RW_COL_WEATHER_LAT;
            $ln   = RW_COL_WEATHER_LNG;
            $from = date('Y-m-d', strtotime('-14 days'));
            $sql  = "SELECT f.forecast_date, f.issued_date, f.temp_max, f.temp_min, f.rain_chance,
                            h.a_max, h.a_min, h.a_rain
                     FROM weather_forecast_runs f
                     JOIN (SELECT forecast_date, MAX(issued_date) AS issued_date
                           FROM weather_forecast_runs
                           WHERE user_id = ? AND forecast_date >= ? AND forecast_date < ?
                             AND issued_date < forecast_date
                           GROUP BY forecast_date) m
                       ON m.forecast_date = f.forecast_date AND m.issued_date = f.issued_date
                     LEFT JOIN (SELECT {$dc} AS d, MAX(temp_max) AS a_max, MAX(temp_min) AS a_min,
                                       MAX(precipitation_probability) AS a_rain
                                FROM {$wh}
                                WHERE {$la} = CAST(? AS DECIMAL(9,6)) AND {$ln} = CAST(? AS DECIMAL(9,6))
                                GROUP BY {$dc}) h
                       ON h.d = f.forecast_date
                     WHERE f.user_id = ?
                     ORDER BY f.forecast_date DESC";
            foreach (rw_fetch($conn, $sql, 'issssi', [$uid, $from, $today, $latS, $lngS, $uid]) as $r) {
                $rows[] = [
                    'label'     => $r['forecast_date'],
                    'target'    => $r['forecast_date'],
                    'issued'    => $r['issued_date'],
                    'status'    => 'closed',
                    'forecast'  => (float)$r['temp_max'],
                    'lstm'      => null,
                    'rf'        => null,
                    'actual'    => rw_num($r['a_max']),
                    'estimated' => false,
                    'extra'     => [
                        'lo_f'   => (float)$r['temp_min'],
                        'lo_a'   => rw_num($r['a_min']),
                        'rain_f' => (int)$r['rain_chance'],
                        'rain_a' => rw_num($r['a_rain']),
                    ],
                ];
            }
            $unit = '°';
            break;

        case 'rice':
            $sql = "SELECT f.forecast_month, f.issued_date, f.blended_price, f.lstm_price, f.rf_price,
                           (SELECT p.regular_milled FROM " . RW_TBL_PRICE_HISTORY . " p
                             WHERE p.price_month = DATE_FORMAT(f.forecast_month, '%Y-%m')
                             LIMIT 1) AS actual
                    FROM rice_forecast_runs f
                    JOIN (SELECT forecast_month, MAX(issued_date) AS issued_date
                          FROM rice_forecast_runs
                          WHERE user_id = ? AND issued_date < forecast_month
                          GROUP BY forecast_month) m
                      ON m.forecast_month = f.forecast_month AND m.issued_date = f.issued_date
                    WHERE f.user_id = ?
                    ORDER BY f.forecast_month DESC
                    LIMIT 8";
            foreach (rw_fetch($conn, $sql, 'ii', [$uid, $uid]) as $r) {
                $start = $r['forecast_month'];
                $end   = date('Y-m-t', strtotime($start));
                $rows[] = [
                    'label'     => $start,
                    'target'    => $start,
                    'issued'    => $r['issued_date'],
                    'status'    => rw_status($start, $end, $today),
                    'forecast'  => (float)$r['blended_price'],
                    'lstm'      => rw_num($r['lstm_price']),
                    'rf'        => rw_num($r['rf_price']),
                    'actual'    => rw_num($r['actual']),
                    'estimated' => false,
                    'extra'     => null,
                ];
            }
            $unit = '%';
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown type']);
            exit;
    }

    // Error = forecast - actual. Percent for money, degrees for temperature.
    // Only graded once the period has closed and an actual value exists.
    $errs = [];
    foreach ($rows as &$row) {
        $row['error'] = null;
        if ($row['status'] === 'closed' && $row['actual'] !== null) {
            if ($unit === '%') {
                if (abs($row['actual']) > 0.0001) {
                    $row['error'] = round(($row['forecast'] - $row['actual']) / abs($row['actual']) * 100, 1);
                }
            } else {
                $row['error'] = round($row['forecast'] - $row['actual'], 1);
            }
        }
        if ($row['error'] !== null) $errs[] = abs($row['error']);
    }
    unset($row);

    echo json_encode([
        'success' => true,
        'type'    => $type,
        'rows'    => $rows,
        'summary' => [
            'graded'        => count($errs),
            'avg_abs_error' => $errs ? round(array_sum($errs) / count($errs), 1) : null,
            'unit'          => $unit,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not load forecast history']);
}