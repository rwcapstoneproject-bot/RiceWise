/*!
 * forecast-algorithms.js — RiceWise Forecasting
 * ---------------------------------------------------------------
 * Two lightweight, dependency-free, client-side forecasting models
 * used to project a short series (temperature, rain %, rice price,
 * quarterly profit) a few steps beyond the data RiceWise already has:
 *
 *   1. RandomForestRegressor — a bagged ensemble of regression trees
 *      trained on sliding-window lag features. Fast, robust on tiny
 *      datasets, analytic (no iterative training needed).
 *
 *   2. SimpleLSTM — a real LSTM cell (forget/input/candidate/output
 *      gates + cell state) trained with gradient descent. Because
 *      this runs in the browser with no numeric library, gradients
 *      are computed via central-difference numerical differentiation
 *      instead of hand-rolled backprop-through-time. That trades some
 *      training speed for a much smaller chance of a wrong-by-hand
 *      BPTT bug — perfectly fine at the small hidden sizes / short
 *      sequences RiceWise deals with (14 days of weather, a dozen
 *      monthly prices, 4 quarters of profit).
 *
 * Both models forecast autoregressively: each predicted value is fed
 * back in as the newest point in the window to produce the next one.
 *
 * Public API (window.ForecastAI):
 *   ForecastAI.rfForecast(series, opts)     -> number[]
 *   ForecastAI.lstmForecast(series, opts)   -> number[]
 *   ForecastAI.ensembleForecast(series, opts)
 *       -> { lstm: number[], randomForest: number[], blended: number[] }
 *
 * opts: { window, stepsAhead, hiddenSize, epochs, nTrees }
 * ---------------------------------------------------------------
 */
(function (global) {
    'use strict';

    // ===================== shared utils =====================
    function mean(arr) { return arr.reduce((a, b) => a + b, 0) / arr.length; }
    function variance(arr) {
        if (!arr.length) return 0;
        const m = mean(arr);
        return mean(arr.map(v => (v - m) * (v - m)));
    }
    function sampleWithReplacement(n) {
        const idx = [];
        for (let i = 0; i < n; i++) idx.push(Math.floor(Math.random() * n));
        return idx;
    }
    function minMax(arr) {
        const mn = Math.min(...arr), mx = Math.max(...arr);
        const span = (mx - mn) || 1; // avoid /0 on flat series
        return {
            mn, mx,
            norm: v => (v - mn) / span,
            denorm: v => v * span + mn
        };
    }

    // ===================== Random Forest =====================
    class RegressionTree {
        constructor(maxDepth, minSamplesSplit, maxFeatures) {
            this.maxDepth = maxDepth;
            this.minSamplesSplit = minSamplesSplit;
            this.maxFeatures = maxFeatures;
            this.root = null;
        }
        fit(X, y) {
            this.nFeatures = X[0].length;
            this.root = this._build(X, y, 0);
        }
        _build(X, y, depth) {
            const n = y.length;
            if (n < this.minSamplesSplit || depth >= this.maxDepth || variance(y) < 1e-8) {
                return { leaf: true, value: mean(y) };
            }
            const best = this._bestSplit(X, y);
            if (!best || !best.leftIdx.length || !best.rightIdx.length) {
                return { leaf: true, value: mean(y) };
            }
            const { feature, threshold, leftIdx, rightIdx } = best;
            return {
                leaf: false, feature, threshold,
                left: this._build(leftIdx.map(i => X[i]), leftIdx.map(i => y[i]), depth + 1),
                right: this._build(rightIdx.map(i => X[i]), rightIdx.map(i => y[i]), depth + 1)
            };
        }
        _bestSplit(X, y) {
            const n = y.length;
            let features = [...Array(this.nFeatures).keys()];
            if (this.maxFeatures && this.maxFeatures < features.length) {
                features = features.sort(() => Math.random() - 0.5).slice(0, this.maxFeatures);
            }
            let bestScore = Infinity, bestFeature = null, bestThreshold = null, bestLeft = null, bestRight = null;
            for (const f of features) {
                const values = [...new Set(X.map(r => r[f]))].sort((a, b) => a - b);
                for (let i = 0; i < values.length - 1; i++) {
                    const threshold = (values[i] + values[i + 1]) / 2;
                    const leftIdx = [], rightIdx = [];
                    for (let j = 0; j < n; j++) (X[j][f] <= threshold ? leftIdx : rightIdx).push(j);
                    if (!leftIdx.length || !rightIdx.length) continue;
                    const leftY = leftIdx.map(i2 => y[i2]), rightY = rightIdx.map(i2 => y[i2]);
                    const score = (leftY.length * variance(leftY) + rightY.length * variance(rightY)) / n;
                    if (score < bestScore) {
                        bestScore = score; bestFeature = f; bestThreshold = threshold;
                        bestLeft = leftIdx; bestRight = rightIdx;
                    }
                }
            }
            if (bestFeature === null) return null;
            return { feature: bestFeature, threshold: bestThreshold, leftIdx: bestLeft, rightIdx: bestRight };
        }
        predictOne(x) {
            let node = this.root;
            while (!node.leaf) node = x[node.feature] <= node.threshold ? node.left : node.right;
            return node.value;
        }
    }

    class RandomForestRegressor {
        constructor({ nTrees = 25, maxDepth = 4, minSamplesSplit = 2, maxFeatures = null } = {}) {
            this.nTrees = nTrees;
            this.maxDepth = maxDepth;
            this.minSamplesSplit = minSamplesSplit;
            this.maxFeatures = maxFeatures;
            this.trees = [];
        }
        fit(X, y) {
            this.trees = [];
            const n = X.length;
            const mf = this.maxFeatures || Math.max(1, Math.round(Math.sqrt(X[0].length)));
            for (let t = 0; t < this.nTrees; t++) {
                const idx = sampleWithReplacement(n);
                const tree = new RegressionTree(this.maxDepth, this.minSamplesSplit, mf);
                tree.fit(idx.map(i => X[i]), idx.map(i => y[i]));
                this.trees.push(tree);
            }
        }
        predict(x) { return mean(this.trees.map(t => t.predictOne(x))); }
    }

    // ===================== Simple LSTM =====================
    class SimpleLSTM {
        constructor({ hiddenSize = 4, epochs = 100, lr = 0.15 } = {}) {
            this.h = hiddenSize;
            this.epochs = epochs;
            this.lr = lr;
            this._init();
        }
        _init() {
            const h = this.h, z = h + 2; // input per step = [value, position-in-window]
            const mk = (r, c) => Array.from({ length: r }, () => Array.from({ length: c }, () => (Math.random() * 2 - 1) * 0.4));
            this.params = {
                Wf: mk(h, z), bf: new Array(h).fill(0),
                Wi: mk(h, z), bi: new Array(h).fill(0),
                Wc: mk(h, z), bc: new Array(h).fill(0),
                Wo: mk(h, z), bo: new Array(h).fill(0),
                Wy: mk(1, h), by: new Array(1).fill(0)
            };
        }
        _sig(v) { return 1 / (1 + Math.exp(-v)); }
        _forward(seq, params) {
            const h = this.h;
            let hPrev = new Array(h).fill(0), cPrev = new Array(h).fill(0);
            for (const xt of seq) {
                const z = [...hPrev, ...xt];
                const f = [], i = [], cHat = [], o = [], c = [], hNew = [];
                for (let k = 0; k < h; k++) {
                    let sf = params.bf[k], si = params.bi[k], sc = params.bc[k], so = params.bo[k];
                    for (let j = 0; j < z.length; j++) {
                        sf += params.Wf[k][j] * z[j];
                        si += params.Wi[k][j] * z[j];
                        sc += params.Wc[k][j] * z[j];
                        so += params.Wo[k][j] * z[j];
                    }
                    f[k] = this._sig(sf); i[k] = this._sig(si); cHat[k] = Math.tanh(sc); o[k] = this._sig(so);
                    c[k] = f[k] * cPrev[k] + i[k] * cHat[k];
                    hNew[k] = o[k] * Math.tanh(c[k]);
                }
                hPrev = hNew; cPrev = c;
            }
            let y = params.by[0];
            for (let k = 0; k < h; k++) y += params.Wy[0][k] * hPrev[k];
            return y;
        }
        _loss(dataset, params) {
            let s = 0;
            for (const { seq, target } of dataset) {
                const p = this._forward(seq, params);
                s += (p - target) * (p - target);
            }
            return s / dataset.length;
        }
        train(dataset) {
            const eps = 1e-3;
            for (let epoch = 0; epoch < this.epochs; epoch++) {
                for (const key of Object.keys(this.params)) {
                    const p = this.params[key];
                    const isMatrix = Array.isArray(p[0]);
                    if (isMatrix) {
                        for (let r = 0; r < p.length; r++) {
                            for (let c = 0; c < p[r].length; c++) {
                                const orig = p[r][c];
                                p[r][c] = orig + eps;
                                const lPlus = this._loss(dataset, this.params);
                                p[r][c] = orig - eps;
                                const lMinus = this._loss(dataset, this.params);
                                p[r][c] = orig - this.lr * ((lPlus - lMinus) / (2 * eps));
                            }
                        }
                    } else {
                        for (let r = 0; r < p.length; r++) {
                            const orig = p[r];
                            p[r] = orig + eps;
                            const lPlus = this._loss(dataset, this.params);
                            p[r] = orig - eps;
                            const lMinus = this._loss(dataset, this.params);
                            p[r] = orig - this.lr * ((lPlus - lMinus) / (2 * eps));
                        }
                    }
                }
            }
        }
        predict(seq) { return this._forward(seq, this.params); }
    }

    // ===================== dataset helpers =====================
    function buildWindowDataset(normed, window) {
        const ds = [];
        for (let i = 0; i < normed.length - window; i++) {
            const seq = [];
            for (let w = 0; w < window; w++) seq.push([normed[i + w], w / ((window - 1) || 1)]);
            ds.push({ seq, target: normed[i + window] });
        }
        return ds;
    }
    function safeWindow(seriesLen, window) {
        let w = window;
        while (w > 1 && seriesLen - w < 2) w--;
        return Math.max(1, w);
    }

    // ===================== public forecasters =====================
    function lstmForecast(series, { window = 3, stepsAhead = 3, hiddenSize = 4, epochs = 100 } = {}) {
        const w = safeWindow(series.length, window);
        const { norm, denorm } = minMax(series);
        const normed = series.map(norm);
        const ds = buildWindowDataset(normed, w);
        const model = new SimpleLSTM({ hiddenSize, epochs, lr: 0.15 });
        model.train(ds);
        let history = normed.slice(-w);
        const out = [];
        for (let s = 0; s < stepsAhead; s++) {
            const seq = history.map((v, idx) => [v, idx / ((w - 1) || 1)]);
            const predNorm = model.predict(seq);
            out.push(denorm(predNorm));
            history = [...history.slice(1), predNorm];
        }
        return out;
    }

    function rfForecast(series, { window = 3, stepsAhead = 3, nTrees = 25 } = {}) {
        const w = safeWindow(series.length, window);
        const X = [], y = [];
        for (let i = 0; i < series.length - w; i++) {
            X.push(series.slice(i, i + w));
            y.push(series[i + w]);
        }
        const rf = new RandomForestRegressor({ nTrees, maxDepth: 4, minSamplesSplit: 2 });
        rf.fit(X, y);
        let hist = series.slice(-w);
        const out = [];
        for (let s = 0; s < stepsAhead; s++) {
            const pred = rf.predict(hist);
            out.push(pred);
            hist = [...hist.slice(1), pred];
        }
        return out;
    }

    function ensembleForecast(series, opts = {}) {
        const lstm = lstmForecast(series, opts);
        const randomForest = rfForecast(series, opts); // fixed: was "rfFsorecast" (typo -> ReferenceError)
        const blended = lstm.map((v, idx) => (v + (randomForest[idx] ?? v)) / 2);
        return { lstm, randomForest, blended };
    }

    global.ForecastAI = { RandomForestRegressor, SimpleLSTM, lstmForecast, rfForecast, ensembleForecast };
})(window);