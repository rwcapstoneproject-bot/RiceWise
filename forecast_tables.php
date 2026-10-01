<?php
/*
 * Names of the EXISTING tables that forecasts are compared against.
 * Checked against SHOW COLUMNS output for profit_history and weather_history.
 * price_history is still unchecked (see SETUP.md step 1).
 */

// profit_history: id, user_id, period_label ('2026-Q3'), period_date,
//                 net_profit, gross_revenue, total_cost, hectares, farmgate_price
const RW_TBL_PROFIT_HISTORY  = 'profit_history';

// weather_history is stored per LOCATION (no user_id column):
//                 id, location_lat, location_lng, log_date, temp_max, temp_min,
//                 humidity, wind_speed, wind_direction, precipitation_probability,
//                 precipitation_sum, weather_code, created_at
const RW_TBL_WEATHER_HISTORY = 'weather_history';
const RW_COL_WEATHER_DATE    = 'log_date';
const RW_COL_WEATHER_LAT     = 'location_lat';
const RW_COL_WEATHER_LNG     = 'location_lng';

// price history behind price_history_list.php: needs price_month ('YYYY-MM')
// and regular_milled. Global (not per-user).
const RW_TBL_PRICE_HISTORY   = 'price_history';