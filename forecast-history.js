/*
 * forecast-history.js
 * Adds a "Forecast history" section (forecast vs. actual) to the Profit,
 * Weather and Rice modals. Load it AFTER the dashboard's inline <script>,
 * because it wraps the existing openModal() to load data when a modal opens.
 *
 * Each modal needs one empty container:
 *   #pmForecastHistory   (profit)
 *   #wmForecastHistory   (weather)
 *   #rmForecastHistory   (rice)
 */
(function () {
    'use strict';

    const ICON_HISTORY = '<svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><polyline points="12 7 12 12 15.5 14"/></svg>';

    const fmtMoney0 = v => '₱' + Math.round(v).toLocaleString('en-US');
    const fmtMoney2 = v => '₱' + v.toFixed(2);
    const fmtTemp = v => Math.round(v) + '°';

    const TYPES = {
        profit: {
            box: 'pmForecastHistory', modal: 'profitModal', secClass: 'pm-sec-lbl',
            heads: ['Quarter', 'Forecast', 'Actual', 'Error'],
            fmt: fmtMoney0, unit: '%', thresholds: [5, 10],
            empty: 'No saved forecasts yet. Each time the profit forecast runs, it is saved here and checked against the actual result once the quarter ends.',
            actualNote: 'Actual = estimated net profit calculated for that quarter, not audited sales.'
        },
        weather: {
            box: 'wmForecastHistory', modal: 'weatherModal', secClass: 'wm-sec-lbl',
            heads: ['Day', 'Forecast high', 'Actual high', 'Error'],
            fmt: fmtTemp, unit: '°', thresholds: [1.5, 3],
            empty: 'No past forecasts to compare yet. Forecasts are saved daily, so this fills in over the next couple of weeks.',
            actualNote: 'Actual = the reading logged for that day.'
        },
        rice: {
            box: 'rmForecastHistory', modal: 'riceModal', secClass: 'rm-sec-lbl',
            heads: ['Month', 'Forecast', 'Actual', 'Error'],
            fmt: fmtMoney2, unit: '%', thresholds: [5, 10],
            empty: 'No saved price forecasts yet. Forecasts are saved when you open this panel and compared with PSA prices once each month is published.',
            actualNote: 'Actual = regular milled retail price for that month.'
        }
    };
    const MODAL_TO_TYPE = { profitModal: 'profit', weatherModal: 'weather', riceModal: 'rice' };
    const tickets = { profit: 0, weather: 0, rice: 0 };

    function injectStyles() {
        if (document.getElementById('fhStyles')) return;
        const css = `
        .fh-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px}
        .fh-head > div{margin-bottom:0 !important}
        .fh-summary{font-size:11.5px;color:var(--text-muted)}
        .fh-summary strong{font-family:'DM Mono',monospace;color:var(--text-primary)}
        .fh-list{display:flex;flex-direction:column;gap:5px}
        .fh-row{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr) minmax(0,1fr) minmax(0,.9fr);gap:8px;align-items:center;padding:9px 12px;background:var(--cream-2);border-radius:var(--radius-sm);font-size:12.5px;color:var(--text-primary)}
        .fh-row.fh-th{background:none;padding:0 12px 2px;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-muted)}
        .fh-num{font-family:'DM Mono',monospace;text-align:right}
        .fh-th .fh-num{font-family:inherit}
        .fh-muted{color:var(--text-muted)}
        .fh-tag{display:inline-block;margin-left:6px;padding:1px 6px;border-radius:8px;background:#fff3d6;color:#a06a1e;font-size:9.5px;font-weight:700;vertical-align:1px}
        .fh-pill{display:inline-block;padding:2px 8px;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:10.5px;font-weight:700;white-space:nowrap}
        .fh-pill.good{background:var(--sage-pale);color:var(--sage-dark)}
        .fh-pill.warn{background:#fff3d6;color:#a06a1e}
        .fh-pill.bad{background:#fdecea;color:#c0392b}
        .fh-pill.pending{background:var(--cream-3);color:var(--text-secondary)}
        .fh-note{font-size:10.5px;color:var(--text-muted);margin-top:8px;line-height:1.5}
        .fh-empty{padding:14px 16px;background:var(--cream-2);border-radius:var(--radius-sm);font-size:12.5px;color:var(--text-secondary);line-height:1.55}
        `;
        const el = document.createElement('style');
        el.id = 'fhStyles';
        el.textContent = css;
        document.head.appendChild(el);
    }

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function parseISO(iso) {
        const [y, m, d] = String(iso).split('-').map(Number);
        return new Date(y, m - 1, d);
    }
    function targetLabel(type, row) {
        if (type === 'profit') return row.label;
        const d = parseISO(row.target);
        return type === 'weather'
            ? d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
            : d.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
    }
    function issuedLabel(iso) {
        return parseISO(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }
    function grade(err, thresholds) {
        const a = Math.abs(err);
        return a <= thresholds[0] ? 'good' : a <= thresholds[1] ? 'warn' : 'bad';
    }
    function errText(err, unit) {
        return (err > 0 ? '+' : '') + err.toFixed(1) + unit;
    }

    function rowTooltip(type, cfg, row) {
        const parts = ['Forecast made ' + issuedLabel(row.issued)];
        if (row.lstm !== null && row.rf !== null) parts.push('LSTM ' + cfg.fmt(row.lstm) + ' · RF ' + cfg.fmt(row.rf));
        if (type === 'weather' && row.extra) {
            const e = row.extra;
            parts.push('Forecast low ' + fmtTemp(e.lo_f) + ', rain ' + e.rain_f + '%');
            if (e.lo_a !== null) parts.push('Actual low ' + fmtTemp(e.lo_a) + (e.rain_a !== null ? ', rain chance ' + Math.round(e.rain_a) + '%' : ''));
        }
        if (row.estimated) parts.push('Trained on estimated history, not real saved data');
        return parts.join(' | ');
    }

    function renderRow(type, cfg, row) {
        const tag = row.estimated ? '<span class="fh-tag">est. data</span>' : '';
        const actual = row.actual !== null ? cfg.fmt(row.actual) : '<span class="fh-muted">—</span>';
        let last;
        if (row.status === 'upcoming') last = '<span class="fh-pill pending">Pending</span>';
        else if (row.status === 'in_progress') last = '<span class="fh-pill pending">In progress</span>';
        else if (row.error === null) last = '<span class="fh-muted">—</span>';
        else last = '<span class="fh-pill ' + grade(row.error, cfg.thresholds) + '">' + errText(row.error, cfg.unit) + '</span>';

        return '<div class="fh-row" title="' + esc(rowTooltip(type, cfg, row)) + '">'
            + '<div>' + esc(targetLabel(type, row)) + tag + '</div>'
            + '<div class="fh-num">' + cfg.fmt(row.forecast) + '</div>'
            + '<div class="fh-num">' + actual + '</div>'
            + '<div class="fh-num">' + last + '</div>'
            + '</div>';
    }

    function render(type, json) {
        const cfg = TYPES[type];
        const box = document.getElementById(cfg.box);
        if (!box) return;

        const head = (summary) => '<div class="fh-head"><div class="' + cfg.secClass + '">' + ICON_HISTORY + 'Forecast history</div>'
            + '<span class="fh-summary">' + summary + '</span></div>';

        if (!json.rows.length) {
            box.innerHTML = head('') + '<div class="fh-empty">' + esc(cfg.empty) + '</div>';
            return;
        }

        const s = json.summary;
        const summary = s.graded > 0
            ? 'Avg. error <strong>' + s.avg_abs_error + s.unit + '</strong> over ' + s.graded + ' checked forecast' + (s.graded > 1 ? 's' : '')
            : 'Accuracy shows once a forecast period ends';

        const th = '<div class="fh-row fh-th"><div>' + cfg.heads[0] + '</div><div class="fh-num">' + cfg.heads[1]
            + '</div><div class="fh-num">' + cfg.heads[2] + '</div><div class="fh-num">' + cfg.heads[3] + '</div></div>';

        box.innerHTML = head(summary)
            + '<div class="fh-list">' + th + json.rows.map(r => renderRow(type, cfg, r)).join('') + '</div>'
            + '<div class="fh-note">' + esc(cfg.actualNote) + ' Hover a row for details.</div>';
    }

    function load(type) {
        const cfg = TYPES[type];
        const box = cfg && document.getElementById(cfg.box);
        if (!box) return;
        injectStyles();

        const ticket = ++tickets[type];
        if (!box.dataset.loaded) {
            box.innerHTML = '<div class="fh-empty fh-muted">Loading forecast history…</div>';
        }
        fetch('forecast_history_list.php?type=' + encodeURIComponent(type), { credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : Promise.reject(new Error('http')))
            .then(json => {
                if (ticket !== tickets[type]) return;      // a newer request is in flight
                if (!json.success) throw new Error('api');
                render(type, json);
                box.dataset.loaded = '1';
            })
            .catch(() => {
                if (ticket !== tickets[type]) return;
                box.innerHTML = '<div class="fh-empty">Couldn\'t load forecast history. Close and reopen this panel to try again.</div>';
            });
    }

    // Refresh after a forecast is saved, but only if the modal is actually open.
    function reload(type) {
        const cfg = TYPES[type];
        if (!cfg) return;
        const modal = document.getElementById(cfg.modal);
        if (modal && modal.classList.contains('show')) load(type);
    }

    // Load history whenever one of the three modals opens.
    const originalOpen = window.openModal;
    if (typeof originalOpen === 'function') {
        window.openModal = function (id) {
            const result = originalOpen.apply(this, arguments);
            const type = MODAL_TO_TYPE[id];
            if (type) load(type);
            return result;
        };
    }

    window.FH = { load: load, reload: reload };
})();