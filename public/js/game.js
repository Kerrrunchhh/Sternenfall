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
