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

    // System messages: opening an unread one marks it read and lowers the counter in the menu.
    document.querySelectorAll('details[data-read-url]').forEach((details) => {
        details.addEventListener('toggle', () => {
            if (!details.open || !details.dataset.readUrl) {
                return;
            }
            const url = details.dataset.readUrl;
            delete details.dataset.readUrl;
            details.classList.remove('unread');
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
                body: new URLSearchParams({ _token: details.dataset.token }),
            }).then((response) => (response.ok ? response.json() : null)).then((data) => {
                if (!data) {
                    return;
                }
                document.querySelectorAll('[data-unread-badge]').forEach((badge) => {
                    badge.textContent = data.unread;
                    badge.hidden = data.unread < 1;
                });
                if (data.unread < 1) {
                    const button = document.querySelector('[data-read-all] button');
                    if (button) {
                        button.disabled = true;
                        button.title = 'Alles gelesen';
                    }
                }
            }).catch(() => {});
        });
    });

    // "Link kopieren": copies the full address of a shared combat report.
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-copy-link]');
        if (!button) {
            return;
        }
        event.preventDefault();
        const link = new URL(button.dataset.copyLink, location.href).href;
        const done = () => {
            const label = button.textContent;
            button.textContent = 'Kopiert ✓';
            setTimeout(() => { button.textContent = label; }, 1500);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(link).then(done, () => window.prompt('Link kopieren:', link));
        } else {
            window.prompt('Link kopieren:', link);
        }
    });

    // Fold-out sections marked with data-remember-open keep their open/closed state (per browser).
    document.querySelectorAll('details[data-remember-open]').forEach((details) => {
        const key = 'sternenfall.open.' + details.dataset.rememberOpen;
        try {
            const stored = localStorage.getItem(key);
            if (stored !== null) {
                details.open = stored === '1';
            }
        } catch (e) { /* ignore */ }
        details.addEventListener('toggle', () => {
            try {
                localStorage.setItem(key, details.open ? '1' : '0');
            } catch (e) { /* ignore */ }
        });
    });

    // Simple tabs: buttons [data-tab-target] show the matching [data-tab-panel]; the choice is remembered.
    document.querySelectorAll('[data-tabs]').forEach((nav) => {
        const key = 'sternenfall.tab.' + nav.dataset.tabs;
        const buttons = [...nav.querySelectorAll('[data-tab-target]')];
        const show = (target) => {
            buttons.forEach((b) => {
                const on = b.dataset.tabTarget === target;
                b.classList.toggle('active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            document.querySelectorAll('[data-tab-panel]').forEach((panel) => {
                if (buttons.some((b) => b.dataset.tabTarget === panel.dataset.tabPanel)) {
                    panel.hidden = panel.dataset.tabPanel !== target;
                }
            });
        };
        let start = buttons[0]?.dataset.tabTarget;
        try {
            const stored = localStorage.getItem(key);
            if (stored && buttons.some((b) => b.dataset.tabTarget === stored)) {
                start = stored;
            }
        } catch (e) { /* ignore */ }
        buttons.forEach((b) => b.addEventListener('click', () => {
            show(b.dataset.tabTarget);
            try {
                localStorage.setItem(key, b.dataset.tabTarget);
            } catch (e) { /* ignore */ }
        }));
        if (start) {
            show(start);
        }
    });

    // Notepad: character count, warning about unsaved changes, Ctrl+S saves.
    document.querySelectorAll('[data-notes-form]').forEach((form) => {
        const area = form.querySelector('[data-notes]');
        const count = form.querySelector('[data-notes-count]');
        const state = form.querySelector('[data-notes-state]');
        const saved = area.value;
        let dirty = false;
        area.addEventListener('input', () => {
            count.textContent = area.value.length;
            dirty = area.value !== saved;
            state.textContent = dirty ? 'Ungespeicherte Änderungen' : state.textContent;
        });
        area.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
                event.preventDefault();
                dirty = false;
                form.requestSubmit();
            }
        });
        form.addEventListener('submit', () => { dirty = false; });
        window.addEventListener('beforeunload', (event) => {
            if (dirty) {
                event.preventDefault();
            }
        });
    });

    // Mail list: one checkbox selects all letters.
    document.querySelectorAll('[data-mail-list]').forEach((form) => {
        const all = form.querySelector('[data-select-all]');
        const boxes = [...form.querySelectorAll('input[name="letters[]"]')];
        all?.addEventListener('change', () => boxes.forEach((box) => { box.checked = all.checked; }));
        boxes.forEach((box) => box.addEventListener('change', () => {
            all.checked = boxes.every((b) => b.checked);
            all.indeterminate = !all.checked && boxes.some((b) => b.checked);
        }));
    });

    // Fleet table: select fleets, pick one action; only the fields of that action are shown and sent.
    document.querySelectorAll('[data-fleet-bulk]').forEach((form) => {
        const boxes = [...form.querySelectorAll('input[name="fleets[]"]')];
        const all = form.querySelector('[data-select-all]');
        const action = form.querySelector('[data-bulk-action]');
        const count = form.querySelector('[data-selected-count]');
        const submit = form.querySelector('#fleet-bulk-submit');
        const panels = [...form.querySelectorAll('.bulk-panel')];
        // "send:attack" opens the "send" fields plus everything marked for that mission.
        const matches = (el, value) => {
            const panels = (el.dataset.panel || '').split(' ');
            return panels.includes(value) || (value.startsWith('send:') && panels.includes('send'));
        };
        const help = form.querySelector('[data-action-help]');
        const setEnabled = (el, enabled) => el.querySelectorAll('input, select, textarea, button[data-fill-max]').forEach((input) => { input.disabled = !enabled; });

        // Cargo room of the selected fleet: capacity, already loaded, typed in, still free.
        const room = form.querySelector('[data-cargo-room]');
        const cargoInputs = [...form.querySelectorAll('[data-cargo-input]')];
        const number = (n) => Math.floor(n).toLocaleString('de-DE');
        const showCargoRoom = (picked) => {
            if (!room) {
                return;
            }
            const capacity = picked.reduce((sum, box) => sum + (parseInt(box.dataset.capacity, 10) || 0), 0);
            const loaded = picked.reduce((sum, box) => sum + (parseInt(box.dataset.load, 10) || 0), 0);
            room.classList.remove('lacking');
            if (picked.length === 0) {
                room.textContent = '';
            } else if (capacity === 0) {
                room.textContent = picked.length === 1
                    ? 'Diese Flotte hat keinen Frachtraum – nur Frachter und Großfrachter transportieren Ladung.'
                    : 'Die ausgewählten Flotten haben keinen Frachtraum.';
            } else if (picked.length > 1) {
                const typed = cargoInputs.reduce((sum, input) => sum + (parseFloat(input.value) || 0), 0);
                const free = capacity - loaded - typed;
                room.textContent = `📦 Frachtraum der Auswahl ${number(capacity)} · bereits geladen ${number(loaded)} · neue Ladung ${number(typed)} · frei ${free < 0 ? '−' + number(-free) + ' (zu viel!)' : number(free)} – beim Transport im Konvoi wird die Ladung auf die Frachter verteilt.`;
                room.classList.toggle('lacking', free < 0);
            } else {
                const typed = cargoInputs.reduce((sum, input) => sum + (parseFloat(input.value) || 0), 0);
                const free = capacity - loaded - typed;
                room.textContent = `📦 Frachtraum ${number(capacity)} · bereits geladen ${number(loaded)} · neue Ladung ${number(typed)} · frei ${free < 0 ? '−' + number(-free) + ' (zu viel!)' : number(free)}`;
                room.classList.toggle('lacking', free < 0);
            }
        };
        cargoInputs.forEach((input) => input.addEventListener('input', () => showCargoRoom(boxes.filter((box) => box.checked))));

        const update = () => {
            const picked = boxes.filter((box) => box.checked);
            const value = action.value;
            const single = picked.length === 1 ? picked[0].value : null;
            count.textContent = picked.length === 0 ? 'Keine Flotte ausgewählt' : (picked.length === 1 ? '1 Flotte ausgewählt' : `${picked.length} Flotten ausgewählt`);
            if (all) {
                const shown = boxes.filter((box) => !box.closest('tr').hidden).length;
                all.checked = picked.length > 0 && picked.length === shown;
                all.indeterminate = picked.length > 0 && picked.length < shown;
            }

            let fleetPanelShown = false;
            panels.forEach((panel) => {
                let show = value !== '' && matches(panel, value);
                if (show && panel.dataset.forFleet) {
                    show = panel.dataset.forFleet === single;
                    fleetPanelShown = fleetPanelShown || (show && matches(panel, 'load'));
                }
                panel.hidden = !show;
                setEnabled(panel, show);
                // Parts inside a panel that belong to only some of its actions.
                panel.querySelectorAll('[data-panel]').forEach((part) => {
                    const own = matches(part, value);
                    part.hidden = !own;
                    if (show) {
                        setEnabled(part, own);
                    }
                });
            });
            const noHangar = form.querySelector('[data-no-hangar]');
            if (noHangar) {
                noHangar.hidden = !(value === 'load' && single && !fleetPanelShown);
            }

            // Flight preview: from the first selected fleet at the speed of the slowest one.
            if (picked.length > 0) {
                form.dataset.from = picked[0].dataset.from;
                form.dataset.speed = Math.min(...picked.map((box) => parseFloat(box.dataset.speed) || Infinity));
            } else {
                delete form.dataset.from;
            }
            form.dispatchEvent(new Event('flight-origin'));
            showCargoRoom(picked);
            submit.disabled = picked.length === 0 || value === '';
            const hint = form.querySelector('[data-submit-hint]');
            if (hint) {
                hint.textContent = picked.length === 0
                    ? '← Hake oben in der Tabelle die Flotte(n) an, die losfliegen sollen.'
                    : (value === '' ? '← Wähle oben eine Aktion aus.' : '');
            }
            if (help) {
                help.textContent = action.selectedOptions[0]?.dataset.help ?? '';
            }
        };

        const visible = (box) => !box.closest('tr').hidden;
        // A squadron selects exactly its fleets (those not filtered out).
        form.querySelectorAll('[data-select-fleets]').forEach((button) => {
            button.addEventListener('click', () => {
                const ids = button.dataset.selectFleets.split(',').filter(Boolean);
                const kinds = new Set(boxes.filter((box) => ids.includes(box.value)).map((box) => box.closest('tr').dataset.own));
                document.dispatchEvent(new CustomEvent('fleet-show-tab', { detail: kinds.has('0') ? 'alliance' : 'own' }));
                boxes.forEach((box) => { box.checked = ids.includes(box.value) && visible(box); });
                update();
            });
        });
        // Filtered-out fleets are never part of an action.
        form.addEventListener('fleet-filter', () => {
            boxes.forEach((box) => { if (!visible(box)) { box.checked = false; } });
            update();
        });
        boxes.forEach((box) => box.addEventListener('change', update));
        if (all) {
            all.addEventListener('change', () => {
                boxes.forEach((box) => { box.checked = all.checked && visible(box); });
                update();
            });
        }
        action.addEventListener('change', update);
        update();
    });

    // Trade post: "max" buys as much as the free cargo space, the stock and the credits allow,
    // after what is already entered for other goods (selling first frees cargo space).
    document.querySelectorAll('[data-trade-form]').forEach((form) => {
        const fleet = form.querySelector('select[name="fleet"]');
        const credits = parseFloat(form.dataset.credits) || 0;
        const number = (input) => Math.max(0, Math.floor(parseFloat(input.value) || 0));
        form.querySelectorAll('[data-buy-max]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = button.previousElementSibling;
                const others = [...form.querySelectorAll('[data-buy-good]')].filter((other) => other !== input);
                let free = parseFloat(fleet.selectedOptions[0]?.dataset.freeCargo) || 0;
                form.querySelectorAll('[data-sell-good]').forEach((sell) => { free += number(sell); });
                let money = credits;
                for (const other of others) {
                    if (other.dataset.cargo === '1') {
                        free -= number(other);
                    }
                    money -= number(other) * (parseFloat(other.dataset.price) || 0);
                }
                const price = parseFloat(input.dataset.price) || 0;
                let max = parseFloat(input.dataset.stock) || 0;
                if (input.dataset.cargo === '1') {
                    max = Math.min(max, free);
                }
                if (price > 0) {
                    max = Math.min(max, money / price);
                }
                input.value = Math.max(0, Math.floor(max));
            });
        });
    });

    // Fleet filters: rules on name and ship counts joined by AND/OR (AND binds stronger), and
    // tabs (own fleets / own plus alliance fleets); filters can be switched off. Kept in the browser for the next visit.
    document.querySelectorAll('[data-fleet-filter]').forEach((filter) => {
        const form = document.querySelector('[data-fleet-bulk]');
        const rows = [...document.querySelectorAll('[data-fleet-row]')];
        const list = filter.querySelector('[data-filter-rules]');
        const template = filter.querySelector('[data-filter-rule]');
        const enabled = filter.querySelector('[data-filter-enabled]');
        const tabs = document.querySelector('[data-fleet-tabs]');
        let tab = tabs ? 'own' : 'alliance';
        const summary = filter.querySelector('[data-filter-summary]');
        const storageKey = 'sternenfall.fleetFilter';
        // The saved filter currently shown (its colour marks the fleets), null after manual changes.
        let active = null;

        const ruleOf = (el) => ({
            join: el.querySelector('[data-join]').value,
            kind: el.querySelector('[data-kind]').value,
            text: el.querySelector('[data-text]').value.trim().toLowerCase(),
            op: el.querySelector('[data-op]').value,
            count: Math.max(0, parseInt(el.querySelector('[data-count]').value, 10) || 0),
            ship: el.querySelector('[data-ship]').value,
        });
        const test = (rule, row) => {
            const name = row.dataset.name;
            switch (rule.kind) {
                case 'starts': return name.startsWith(rule.text);
                case 'ends': return name.endsWith(rule.text);
                case 'contains': return name.includes(rule.text);
                case 'ships': {
                    const have = (JSON.parse(row.dataset.ships || '{}') || {})[rule.ship] || 0;
                    return rule.op === 'gte' ? have >= rule.count : (rule.op === 'lte' ? have <= rule.count : have === rule.count);
                }
            }
            return true;
        };
        // OR separates groups; all rules of a group must hold.
        const passes = (rules, row) => {
            if (rules.length === 0) {
                return true;
            }
            const groups = [[]];
            rules.forEach((rule, i) => {
                if (i > 0 && rule.join === 'or') {
                    groups.push([]);
                }
                groups[groups.length - 1].push(rule);
            });
            return groups.some((group) => group.every((rule) => test(rule, row)));
        };

        const apply = () => {
            const rules = [...list.children].map(ruleOf).filter((r) => r.kind === 'ships' || r.text !== '');
            let shown = 0;
            rows.forEach((row) => {
                // "Allianzflotten" shows own fleets plus released ones, "Eigene Flotten" only own ones.
                const inTab = tab !== 'own' || row.dataset.own === '1';
                const ok = inTab && (!enabled.checked || passes(rules, row));
                row.hidden = !ok;
                row.style.boxShadow = active && ok && enabled.checked ? `inset 4px 0 0 ${active.color}` : '';
                shown += ok ? 1 : 0;
            });
            // A player's section heading disappears when none of his fleets is shown.
            document.querySelectorAll('[data-owner-head]').forEach((head) => {
                let next = head.nextElementSibling;
                let any = false;
                while (next && !next.hasAttribute('data-owner-head')) {
                    any = any || (next.hasAttribute('data-fleet-row') && !next.hidden);
                    next = next.nextElementSibling;
                }
                head.hidden = !any;
            });
            const inView = rows.filter((row) => tab !== 'own' || row.dataset.own === '1').length;
            summary.textContent = !enabled.checked ? '(ausgeschaltet)' : (active ? `„${active.name}“ ` : '') + (shown === inView ? '' : `– ${shown} von ${inView} Flotten`);
            tabs?.querySelectorAll('[data-tab]').forEach((button) => button.classList.toggle('active', button.dataset.tab === tab));
            summary.style.color = active ? active.color : '';
            // Saving again under the shown filter's name updates it.
            const saveForm = filter.querySelector('[data-filter-save]');
            if (saveForm && active) {
                saveForm.querySelector('[data-filter-name]').value = active.name;
                saveForm.querySelector('[data-filter-color]').value = active.color;
            }
            filter.querySelectorAll('[data-apply-filter]').forEach((chip) => {
                chip.closest('.filter-chip').classList.toggle('active', !!active && JSON.parse(chip.dataset.applyFilter).name === active.name);
            });
            if (form) {
                form.dispatchEvent(new Event('fleet-filter'));
            }
            try {
                localStorage.setItem(storageKey, JSON.stringify({ enabled: enabled.checked, tab, rules: [...list.children].map(ruleOf), active }));
            } catch (e) { /* storage unavailable */ }
        };
        const addRule = (values = {}) => {
            const el = template.content.firstElementChild.cloneNode(true);
            for (const [key, field] of [['join', '[data-join]'], ['kind', '[data-kind]'], ['text', '[data-text]'], ['op', '[data-op]'], ['count', '[data-count]'], ['ship', '[data-ship]']]) {
                if (values[key] !== undefined) {
                    el.querySelector(field).value = values[key];
                }
            }
            const kind = el.querySelector('[data-kind]');
            const sync = () => {
                const ships = kind.value === 'ships';
                el.querySelector('[data-text]').hidden = ships;
                el.querySelector('[data-ship-fields]').hidden = !ships;
            };
            const edited = () => { active = null; apply(); };
            kind.addEventListener('change', () => { sync(); edited(); });
            el.querySelectorAll('input, select').forEach((input) => input.addEventListener('input', edited));
            el.querySelector('[data-remove]').addEventListener('click', () => { el.remove(); edited(); });
            list.appendChild(el);
            sync();
        };

        filter.querySelector('[data-add-rule]').addEventListener('click', () => { addRule(); filter.open = true; active = null; apply(); });
        filter.querySelector('[data-reset]').addEventListener('click', () => { list.replaceChildren(); enabled.checked = true; active = null; apply(); });
        enabled.addEventListener('change', apply);
        tabs?.querySelectorAll('[data-tab]').forEach((button) => button.addEventListener('click', () => { tab = button.dataset.tab; apply(); }));
        // A squadron may ask for the tab that shows all its fleets.
        document.addEventListener('fleet-show-tab', (event) => { if (tabs) { tab = event.detail; apply(); } });

        // Saved filters: a click shows them, their colour marks the fleets.
        filter.querySelectorAll('[data-apply-filter]').forEach((chip) => {
            chip.addEventListener('click', () => {
                const saved = JSON.parse(chip.dataset.applyFilter);
                list.replaceChildren();
                (saved.rules || []).forEach((rule) => addRule(rule));
                enabled.checked = true;
                active = { name: saved.name, color: saved.color };
                apply();
            });
        });
        const save = filter.querySelector('[data-filter-save]');
        if (save) {
            save.addEventListener('submit', () => {
                save.querySelector('[name="definition"]').value = JSON.stringify({ alliance: true, rules: [...list.children].map(ruleOf) });
            });
        }

        try {
            const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
            if (saved) {
                enabled.checked = saved.enabled !== false;
                tab = tabs && ['own', 'alliance'].includes(saved.tab) ? saved.tab : tab;
                (saved.rules || []).forEach((rule) => addRule(rule));
                active = saved.active || null;
                filter.open = (saved.rules || []).length > 0;
            }
            // Whether the filter box was open or folded is remembered on its own.
            const open = localStorage.getItem(storageKey + '.open');
            if (open !== null) {
                filter.open = open === '1';
            }
        } catch (e) { /* ignore */ }
        filter.addEventListener('toggle', () => {
            try {
                localStorage.setItem(storageKey + '.open', filter.open ? '1' : '0');
            } catch (e) { /* ignore */ }
        });
        apply();
    });

    // Fleet forms: a remembered coordinate fills galaxy, system and position.
    document.querySelectorAll('[data-bookmark-pick]').forEach((select) => {
        select.addEventListener('change', () => {
            const option = select.selectedOptions[0];
            if (!option || !option.value) {
                return;
            }
            const form = select.closest('form');
            for (const field of ['galaxy', 'system', 'position']) {
                const input = form.querySelector(`[name="${field}"]`);
                input.value = option.dataset[field];
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    });

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
        const gameSpeed = parseFloat(form.dataset.gameSpeed) || 1;
        const field = (name) => form.querySelector(`[name="${name}"]`);
        // Start and speed may change (fleet selection), so they are read on every update.
        const update = () => {
            if (!form.dataset.from) {
                preview.textContent = 'Wähle eine Flotte aus.';
                return;
            }
            const from = form.dataset.from.split(':').map(Number);
            const shipSpeed = parseFloat(form.dataset.speed);
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
        form.addEventListener('flight-origin', update);
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
    const capacity = document.getElementById('cargo-capacity');
    if (fleetForm && capacity) {
        const shipInputs = [...fleetForm.querySelectorAll('input[data-cargo]')];
        const updateCapacity = () => {
            const total = shipInputs.reduce((sum, input) => sum + (parseInt(input.value, 10) || 0) * parseInt(input.dataset.cargo, 10), 0);
            capacity.textContent = fmt.format(total);
        };
        shipInputs.forEach((input) => input.addEventListener('input', updateCapacity));
        updateCapacity();
    }
})();
