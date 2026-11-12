// Line charts of the status page: one line per series over the daily snapshots,
// a crosshair with the values of the day under the pointer. The data comes from
// data-chart = {days: ["2026-11-12", ...], series: [{name, values: [...]}, ...]}.
(() => {
    const SVG = 'http://www.w3.org/2000/svg';
    const lang = document.documentElement.lang || 'de';
    const number = new Intl.NumberFormat(lang, { maximumFractionDigits: 0 });
    const compact = new Intl.NumberFormat(lang, { notation: 'compact', maximumFractionDigits: 1 });
    const dayFormat = new Intl.DateTimeFormat(lang, { day: '2-digit', month: '2-digit' });
    const longDay = new Intl.DateTimeFormat(lang, { day: '2-digit', month: '2-digit', year: 'numeric' });
    const asDate = (iso) => new Date(iso + 'T12:00:00Z');

    const el = (name, attrs = {}, parent = null) => {
        const node = document.createElementNS(SVG, name);
        for (const [key, value] of Object.entries(attrs)) {
            node.setAttribute(key, value);
        }
        if (parent) {
            parent.appendChild(node);
        }
        return node;
    };

    // A "nice" upper bound and step for the y axis (1, 2, 2.5 or 5 × 10^n).
    const niceScale = (max) => {
        if (max <= 0) {
            return { top: 1, step: 0.25 };
        }
        const rough = max / 4;
        const magnitude = 10 ** Math.floor(Math.log10(rough));
        const step = [1, 2, 2.5, 5, 10].map((f) => f * magnitude).find((s) => s >= rough);
        return { top: Math.ceil(max / step) * step, step };
    };

    const draw = (figure) => {
        const data = JSON.parse(figure.dataset.chart);
        const plot = figure.querySelector('.trend-plot');
        const width = Math.max(280, plot.clientWidth);
        const height = 240;
        const margin = { top: 12, right: 16, bottom: 28, left: 56 };
        const innerW = width - margin.left - margin.right;
        const innerH = height - margin.top - margin.bottom;
        const count = data.days.length;
        const max = Math.max(0, ...data.series.flatMap((s) => s.values));
        const { top, step } = niceScale(max);
        const x = (i) => margin.left + (count === 1 ? innerW / 2 : (i / (count - 1)) * innerW);
        const y = (v) => margin.top + innerH - (v / top) * innerH;

        plot.textContent = '';
        const svg = el('svg', { viewBox: `0 0 ${width} ${height}`, width, height, class: 'trend-svg' }, plot);

        // Recessive grid and y labels.
        for (let v = 0; v <= top + step / 2; v += step) {
            el('line', { x1: margin.left, x2: width - margin.right, y1: y(v), y2: y(v), class: 'trend-grid' }, svg);
            el('text', { x: margin.left - 8, y: y(v) + 4, class: 'trend-axis', 'text-anchor': 'end' }, svg).textContent = compact.format(v);
        }
        // About five date labels along the x axis.
        const every = Math.max(1, Math.ceil(count / 5));
        data.days.forEach((day, i) => {
            if (i % every === 0 || i === count - 1) {
                el('text', { x: x(i), y: height - 8, class: 'trend-axis', 'text-anchor': i === 0 ? 'start' : (i === count - 1 ? 'end' : 'middle') }, svg).textContent = dayFormat.format(asDate(day));
            }
        });

        data.series.forEach((series, s) => {
            const points = series.values.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ');
            el('polyline', { points, class: 'trend-line', style: `--series: var(--series-${s + 1})` }, svg);
        });

        // Crosshair, markers and tooltip for the day under the pointer.
        const cross = el('line', { y1: margin.top, y2: margin.top + innerH, class: 'trend-cross', visibility: 'hidden' }, svg);
        const dots = data.series.map((_, s) => el('circle', { r: 4, class: 'trend-dot', style: `--series: var(--series-${s + 1})`, visibility: 'hidden' }, svg));
        const tip = document.createElement('div');
        tip.className = 'trend-tip';
        tip.hidden = true;
        plot.appendChild(tip);
        const hit = el('rect', { x: margin.left, y: margin.top, width: innerW, height: innerH, class: 'trend-hit' }, svg);

        const show = (event) => {
            const box = svg.getBoundingClientRect();
            const px = (event.clientX - box.left) * (width / box.width);
            const i = Math.max(0, Math.min(count - 1, Math.round(((px - margin.left) / innerW) * (count - 1))));
            cross.setAttribute('x1', x(i));
            cross.setAttribute('x2', x(i));
            cross.setAttribute('visibility', 'visible');
            dots.forEach((dot, s) => {
                dot.setAttribute('cx', x(i));
                dot.setAttribute('cy', y(data.series[s].values[i]));
                dot.setAttribute('visibility', 'visible');
            });
            tip.replaceChildren();
            const head = document.createElement('strong');
            head.textContent = longDay.format(asDate(data.days[i]));
            tip.appendChild(head);
            data.series.forEach((series, s) => {
                const row = document.createElement('div');
                const swatch = document.createElement('span');
                swatch.className = 'trend-swatch';
                swatch.style.setProperty('--series', `var(--series-${s + 1})`);
                row.append(swatch, `${series.name}: ${number.format(series.values[i])}`);
                tip.appendChild(row);
            });
            tip.hidden = false;
            const left = (x(i) / width) * box.width;
            tip.style.left = `${Math.min(Math.max(0, left + 12), box.width - tip.offsetWidth)}px`;
        };
        const hide = () => {
            cross.setAttribute('visibility', 'hidden');
            dots.forEach((dot) => dot.setAttribute('visibility', 'hidden'));
            tip.hidden = true;
        };
        hit.addEventListener('pointermove', show);
        hit.addEventListener('pointerdown', show);
        hit.addEventListener('pointerleave', hide);
    };

    const all = [...document.querySelectorAll('.trend-chart[data-chart]')];
    all.forEach(draw);
    let timer = null;
    window.addEventListener('resize', () => {
        clearTimeout(timer);
        timer = setTimeout(() => all.forEach(draw), 150);
    });
})();
