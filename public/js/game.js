// Server clock: all fleet times follow the server, so the browser clock is corrected by
// the offset measured when the page arrived (server render time vs. first response byte).
const serverClock = (() => {
    const el = document.querySelector('[data-server-clock]');
    let offset = 0;
    if (el) {
        const nav = performance.getEntriesByType?.('navigation')?.[0];
        const received = nav && nav.responseStart > 0 ? performance.timeOrigin + nav.responseStart : Date.now();
        offset = parseInt(el.dataset.serverMs, 10) - received;
    }
    const now = () => new Date(Date.now() + offset);
    const zone = { timeZone: 'Europe/Berlin' };
    const format = (date, options) => date.toLocaleString('de-DE', { ...zone, ...options });
    const day = (date) => format(date, { year: 'numeric', month: '2-digit', day: '2-digit' });

    if (el) {
        const show = () => {
            const t = now();
            el.textContent = format(t, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            el.dateTime = t.toISOString();
            // Re-align to the next full second so the display flips exactly on the second.
            setTimeout(show, 1000 - (t.getTime() % 1000) + 5);
        };
        show();
    }

    return { now, format, day };
})();

// Live resource counters and construction countdowns.
(() => {
    const started = Date.now();
    const fmt = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 0 });

    const duration = (s) => {
        s = Math.max(0, Math.floor(s));
        const d = Math.floor(s / 86400);
        const h = Math.floor((s % 86400) / 3600);
        const m = Math.floor((s % 3600) / 60);
        const pad = (n) => String(n).padStart(2, '0');
        const time = (h || d) ? `${pad(h)}:${pad(m)}:${pad(s % 60)}` : `${pad(m)}:${pad(s % 60)}`;
        return d ? `${d}d ${time}` : time;
    };

    const resources = [...document.querySelectorAll('[data-rate]')].map((el) => ({
        el,
        amount: parseFloat(el.dataset.amount),
        rate: parseFloat(el.dataset.rate),
        protectedAmount: parseFloat(el.dataset.protected),
    }));
    const countdowns = [...document.querySelectorAll('[data-finishes]')].map((el) => ({
        el,
        remaining: parseInt(el.dataset.finishes, 10) - parseInt(el.dataset.now, 10),
    }));

    let reloading = false;
    const tick = () => {
        const elapsed = (Date.now() - started) / 1000;

        for (const r of resources) {
            const value = Math.max(0, r.amount + r.rate * elapsed / 3600);
            r.el.textContent = fmt.format(Math.floor(value));
            r.el.classList.toggle('res-exposed', value > r.protectedAmount);
        }

        for (const c of countdowns) {
            const left = c.remaining - elapsed;
            c.el.textContent = left > 0 ? duration(left) : 'fertig';
            if (left <= 0 && !reloading) {
                reloading = true;
                setTimeout(() => location.reload(), 1000);
            }
        }
    };

    tick();
    setInterval(tick, 1000);

    // Shipyard build rate (100–200 %): highlight the cost row of the chosen rate and use its maximum.
    document.querySelectorAll('[data-build-rates]').forEach((box) => {
        const select = box.querySelector('select[name="percent"]');
        if (!select) {
            return;
        }
        const quantity = box.querySelector('input[name="quantity"]');
        const maxLabel = box.querySelector('[data-rate-max-label]');
        const button = box.querySelector('button[type="submit"]');
        const update = () => {
            let max = 0;
            box.querySelectorAll('[data-rate-percent]').forEach((cost) => {
                const active = cost.dataset.ratePercent === select.value;
                cost.classList.toggle('rate-active', active);
                if (active) {
                    max = parseInt(cost.dataset.rateMax, 10);
                }
            });
            // A higher rate chosen in the dropdown opens the collapsed cost lines.
            const details = box.querySelector('[data-rate-details]');
            if (details && select.value !== '100') {
                details.open = true;
            }
            quantity.max = Math.max(max, 1);
            quantity.disabled = button.disabled = max < 1;
            if (max > 0 && parseInt(quantity.value, 10) > max) {
                quantity.value = max;
            }
            maxLabel.textContent = `max. ${fmt.format(max)}`;
        };
        select.addEventListener('change', update);
        // Clicking a cost row picks that rate.
        box.querySelectorAll('[data-rate-percent]').forEach((row) => row.addEventListener('click', () => {
            select.value = row.dataset.ratePercent;
            update();
        }));
        update();
    });

    // Confirmation modal for dangerous buttons (data-confirm="question", data-confirm-label="Do it").
    const dialog = document.getElementById('confirm-dialog');
    if (dialog && typeof dialog.showModal === 'function') {
        let pending = null;
        document.addEventListener('click', (event) => {
            const button = event.target.closest('[data-confirm]');
            if (!button || !button.form || dialog.contains(button)) return;
            event.preventDefault();
            pending = button;
            dialog.querySelector('[data-confirm-text]').textContent = button.dataset.confirm;
            dialog.querySelector('[data-confirm-ok]').textContent = button.dataset.confirmLabel || 'Bestätigen';
            dialog.showModal();
        });
        dialog.addEventListener('close', () => {
            if (dialog.returnValue === 'confirm' && pending) {
                // Submits with the clicked button, so formaction and name/value are kept.
                pending.form.requestSubmit(pending);
            }
            pending = null;
            dialog.returnValue = '';
        });
        // Clicking the backdrop cancels.
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close('cancel');
        });
    }

    // Queues: reorder by drag & drop, then save the new order.
    document.querySelectorAll('[data-sortable]').forEach((list) => {
        let dragged = null;
        const initialOrder = () => [...list.querySelectorAll('input[name="order[]"]')].map((input) => input.value).join(',');
        const before = initialOrder();

        list.addEventListener('dragstart', (event) => {
            dragged = event.target.closest('.queue-item');
            if (!dragged) return;
            dragged.classList.add('dragging');
            event.dataTransfer.effectAllowed = 'move';
        });
        list.addEventListener('dragover', (event) => {
            if (!dragged) return;
            event.preventDefault();
            const target = event.target.closest('.queue-item');
            if (!target || target === dragged) return;
            const rect = target.getBoundingClientRect();
            const after = event.clientY > rect.top + rect.height / 2;
            list.insertBefore(dragged, after ? target.nextSibling : target);
        });
        list.addEventListener('dragend', () => {
            if (!dragged) return;
            dragged.classList.remove('dragging');
            dragged = null;
            if (initialOrder() !== before) {
                list.closest('form').submit();
            }
        });
    });

    // Flight preview: duration and arrival for the chosen target and speed (same formula as the server).
    const distance = (a, b) => {
        if (a[0] !== b[0]) return 100000 * Math.abs(a[0] - b[0]);
        if (a[1] !== b[1]) return 20000 + 500 * Math.abs(a[1] - b[1]);
        if (a[2] !== b[2]) return 1000 + 100 * Math.abs(a[2] - b[2]);
        return 5;
    };
    document.querySelectorAll('form[data-flight]').forEach((form) => {
        const preview = form.querySelector('[data-flight-preview]');
        const from = form.dataset.from.split(':').map(Number);
        const shipSpeed = parseFloat(form.dataset.speed);
        const gameSpeed = parseFloat(form.dataset.gameSpeed) || 1;
        const field = (name) => form.querySelector(`[name="${name}"]`);
        const update = () => {
            const to = ['galaxy', 'system', 'position'].map((n) => parseInt(field(n).value, 10));
            const percent = parseFloat(String(field('speed').value).replace(',', '.'));
            if (to.some(Number.isNaN) || Number.isNaN(percent) || percent < 0.1 || percent > 100 || !shipSpeed) {
                preview.textContent = 'Geschwindigkeit: 0,1 bis 100 %';
                return;
            }
            const seconds = Math.max(5, Math.round(3600 * distance(from, to) / (shipSpeed * percent / 100) / gameSpeed));
            const arrival = new Date(serverClock.now().getTime() + seconds * 1000);
            const sameDay = serverClock.day(arrival) === serverClock.day(serverClock.now());
            const when = serverClock.format(arrival, sameDay
                ? { hour: '2-digit', minute: '2-digit', second: '2-digit' }
                : { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
            preview.textContent = `Flugdauer ${duration(seconds)} · Ankunft ${sameDay ? 'heute' : ''} ${when} Uhr · Rückkehr ${duration(2 * seconds)} nach Start`;
        };
        form.querySelectorAll('[data-flight-input]').forEach((input) => input.addEventListener('input', update));
        update();
    });

    // Fleet form: "max" buttons and live cargo capacity.
    // "max" buttons in every ship form.
    document.querySelectorAll('[data-fill-max]').forEach((button) => {
        button.addEventListener('click', () => {
            button.previousElementSibling.value = button.dataset.fillMax;
            button.previousElementSibling.dispatchEvent(new Event('input'));
        });
    });

    const fleetForm = document.getElementById('fleet-form');
    if (fleetForm) {
        const shipInputs = [...fleetForm.querySelectorAll('input[data-cargo]')];
        const capacity = document.getElementById('cargo-capacity');
        const updateCapacity = () => {
            const total = shipInputs.reduce((sum, input) => sum + (parseInt(input.value, 10) || 0) * parseInt(input.dataset.cargo, 10), 0);
            capacity.textContent = fmt.format(total);
        };
        shipInputs.forEach((input) => input.addEventListener('input', updateCapacity));
        updateCapacity();
    }
})();
