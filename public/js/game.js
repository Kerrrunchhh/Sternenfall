// Server clock: all fleet times follow the server, so the browser clock is corrected by
// the offset measured when the page arrived (server render time vs. first response byte).
// Texts in the page's language (window.I18N from the layout, German text => translation).
const LOCALE_TAG = window.LOCALE === 'en' ? 'en-GB' : 'de-DE';
const t = (key, params = {}) => Object.entries(params).reduce(
    (text, [name, value]) => text.split('{' + name + '}').join(String(value)),
    (window.I18N && window.I18N[key]) || key,
);

const serverClock = (() => {
    const el = document.querySelector('[data-server-clock]');
    let offset = 0;
    if (el) {
        const nav = performance.getEntriesByType?.('navigation')?.[0];
        const received = nav && nav.responseStart > 0 ? performance.timeOrigin + nav.responseStart : Date.now();
        offset = parseInt(el.dataset.serverMs, 10) - received;
    }
    const now = () => new Date(Date.now() + offset);
    // Times show in the player's time zone (Konto); the game itself runs on server time.
    const zone = { timeZone: window.TIMEZONE || 'Europe/Berlin' };
    const format = (date, options) => date.toLocaleString(LOCALE_TAG, { ...zone, ...options });
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

// Orbits – the same as App\Game\Orbit and Universe::planetsInSystem (keep both in step): the
// planets circle their sun, so flights within a system get shorter and longer all the time; the
// galaxies circle the centre of the universe, so flights between galaxies change too. A galaxy
// turns as a whole (only shown on the galaxy map, distances between systems stay the same).
const orbit = (() => {
    const EPOCH = 1767225600; // 01.01.2026 00:00 UTC
    const INNER_DAYS = 1;
    const OUTER_DAYS = 14;
    const RADIUS_BASE = 200;
    const RADIUS_STEP = 100;
    const TRAVEL_BASE = 600;
    const GALAXIES = 25;
    const GALAXY_INNER_DAYS = 90;
    const GALAXY_OUTER_DAYS = 365;
    const GALAXY_RADIUS_STEP = 41000;
    const GALAXY_TRAVEL_BASE = 100000;
    const GALAXY_SPIN_DAYS = 30;
    const table = Array.from({ length: 256 }, (_, n) => {
        let c = n;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        return c >>> 0;
    });
    // CRC-32 like PHP's crc32() (the texts are plain ASCII).
    const crc32 = (text) => {
        let c = 0xffffffff;
        for (let i = 0; i < text.length; i++) c = table[(c ^ text.charCodeAt(i)) & 0xff] ^ (c >>> 8);
        return (c ^ 0xffffffff) >>> 0;
    };
    const slots = (g, s) => {
        const roll = crc32(`slots:${g}:${s}`) % 10001;
        // The trade guild's central systems (250) keep their old size; all others use 41 steps.
        return s === 250
            ? 10 + Math.floor(40 * roll * roll / 100000000)
            : 10 + Math.floor(41 * roll * roll / (10001 * 10001));
    };
    const period = (g, s, p) => {
        const n = slots(g, s);
        const share = n > 1 ? Math.max(0, Math.min(1, (p - 1) / (n - 1))) : 0;
        return 86400 * (INNER_DAYS + (OUTER_DAYS - INNER_DAYS) * share ** 1.5);
    };
    const angle = (g, s, p, ms) => {
        const phase = crc32(`orbit:${g}:${s}:${p}`) / 4294967296 * 2 * Math.PI;
        const turns = (Math.floor(ms / 1000) - EPOCH) / period(g, s, p);
        return (phase + 2 * Math.PI * turns) % (2 * Math.PI);
    };
    const radius = (p) => RADIUS_BASE + RADIUS_STEP * p;
    const distance = (g, s, from, to, ms) => {
        const a = radius(from);
        const b = radius(to);
        return TRAVEL_BASE + Math.round(Math.sqrt(Math.max(0, a * a + b * b - 2 * a * b * Math.cos(angle(g, s, from, ms) - angle(g, s, to, ms)))));
    };

    const galaxyPeriod = (g) => {
        const share = Math.max(0, Math.min(1, (g - 1) / (GALAXIES - 1)));
        return 86400 * (GALAXY_INNER_DAYS + (GALAXY_OUTER_DAYS - GALAXY_INNER_DAYS) * share ** 1.5);
    };
    const galaxyAngle = (g, ms) => {
        const phase = crc32(`galaxy:${g}`) / 4294967296 * 2 * Math.PI;
        return (phase + 2 * Math.PI * (Math.floor(ms / 1000) - EPOCH) / galaxyPeriod(g)) % (2 * Math.PI);
    };
    const galaxyDistance = (from, to, ms) => {
        if (from === to) return 0;
        const a = GALAXY_RADIUS_STEP * from;
        const b = GALAXY_RADIUS_STEP * to;
        return GALAXY_TRAVEL_BASE + Math.round(Math.sqrt(Math.max(0, a * a + b * b - 2 * a * b * Math.cos(galaxyAngle(from, ms) - galaxyAngle(to, ms)))));
    };
    // How far the galaxy has turned around its own centre (galaxy map only).
    const spin = (g, ms) => (crc32(`spin:${g}`) / 4294967296 * 2 * Math.PI + 2 * Math.PI * (ms / 1000 - EPOCH) / (GALAXY_SPIN_DAYS * 86400)) % (2 * Math.PI);

    return { slots, angle, distance, crc32, galaxyAngle, galaxyDistance, spin };
})();

// Battle report: replay of the course of the battle, step by step in firing order.
(() => {
    const stage = document.querySelector('[data-battle-replay]');
    if (!stage) return;
    const data = JSON.parse(stage.dataset.battleReplay);
    const fmt = new Intl.NumberFormat(LOCALE_TAG, { maximumFractionDigits: 0 });
    const beams = stage.querySelector('[data-replay-beams]');
    const caption = document.querySelector('[data-replay-caption]');
    const position = document.querySelector('[data-replay-position]');
    const playButton = document.querySelector('[data-replay="play"]');
    const span = (className, text) => {
        const element = document.createElement('span');
        element.className = className;
        element.textContent = text;
        return element;
    };
    const items = data.groups.map((group) => {
        const li = document.createElement('li');
        li.className = 'replay-group' + (group.own ? ' own' : '');
        const count = span('replay-count', '');
        li.append(span('replay-label', group.label), span('replay-owner muted small', group.owner), count);
        stage.querySelector(`[data-side="${group.side}"]`).append(li);
        return { group, li, count };
    });
    const name = (index) => `${data.groups[index].label} (${data.groups[index].owner})`;
    let shown = 0; // steps applied
    let timer = null;

    const draw = () => {
        // Units still alive after the steps shown so far – and before the step just shown.
        const lostAfter = (steps) => {
            const lost = data.groups.map(() => 0);
            data.steps.slice(0, steps).forEach((step) => step.shots.forEach((shot) => shot.hits.forEach((hit) => { lost[hit.group] += hit.destroyed; })));
            return lost;
        };
        const lost = lostAfter(shown);
        const lostBefore = lostAfter(Math.max(0, shown - 1));
        items.forEach(({ group, li, count }, index) => {
            const alive = group.count - lost[index];
            count.textContent = `${fmt.format(alive)} / ${fmt.format(group.count)}`;
            // Destroyed in this step: still there, struck through; from the next step on: gone.
            li.classList.toggle('gone', alive <= 0);
            li.hidden = shown > 0 && group.count - lostBefore[index] <= 0;
            li.classList.remove('firing', 'hit');
        });
        beams.replaceChildren();
        const step = shown > 0 ? data.steps[shown - 1] : null;
        if (position) {
            position.textContent = step
                ? t('Runde {round} · Schritt {step} von {steps}', { round: step.round ?? 1, step: shown, steps: data.steps.length })
                : t('Schritt {step} von {steps}', { step: shown, steps: data.steps.length });
        }
        if (!step) {
            caption.textContent = t('Vor dem ersten Schuss.');
            return;
        }
        // The step just fired: shooters glow, arrows go from them to their targets.
        const box = stage.getBoundingClientRect();
        // Attackers stand left, defenders right: an arrow leaves the shooter's inner edge and ends at the target's.
        const edge = (element, side) => {
            const r = element.getBoundingClientRect();
            return [(side === 'attacker' ? r.right : r.left) - box.left, r.top - box.top + r.height / 2];
        };
        beams.setAttribute('viewBox', `0 0 ${box.width} ${box.height}`);
        const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
        ['attacker', 'defender'].forEach((side) => {
            const marker = document.createElementNS('http://www.w3.org/2000/svg', 'marker');
            Object.entries({ id: `arrow-${side}`, viewBox: '0 0 10 10', refX: 9, refY: 5, markerWidth: 12, markerHeight: 12, markerUnits: 'userSpaceOnUse', orient: 'auto-start-reverse' })
                .forEach(([key, value]) => marker.setAttribute(key, value));
            const head = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            head.setAttribute('d', 'M0,0 L10,5 L0,10 z');
            head.setAttribute('class', `arrow-head arrow-${side}`);
            marker.append(head);
            defs.append(marker);
        });
        beams.append(defs);
        const total = Math.max(1, ...step.shots.flatMap((shot) => shot.hits.map((hit) => hit.damage)));
        const lines = [];
        step.shots.forEach((shot) => {
            items[shot.group].li.classList.add('firing');
            const side = data.groups[shot.group].side;
            const [x1, y1] = edge(items[shot.group].li, side);
            shot.hits.forEach((hit) => {
                items[hit.group].li.classList.add('hit');
                const [x2, y2] = edge(items[hit.group].li, data.groups[hit.group].side);
                const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                Object.entries({ x1, y1, x2, y2, 'stroke-width': (1 + 4 * hit.damage / total).toFixed(1), class: `beam beam-${side}`, 'marker-end': `url(#arrow-${side})` })
                    .forEach(([key, value]) => line.setAttribute(key, value));
                beams.append(line);
            });
            const targets = shot.hits.map((hit) => (hit.destroyed > 0
                ? t('{target}: {damage} Schaden, {destroyed} zerstört', { target: name(hit.group), damage: fmt.format(hit.damage), destroyed: fmt.format(hit.destroyed) })
                : t('{target}: {damage} Schaden', { target: name(hit.group), damage: fmt.format(hit.damage) })));
            lines.push(targets.length
                ? t('{shooter} ({units}) schießt auf {targets}', { shooter: name(shot.group), units: fmt.format(shot.units), targets: targets.join(' · ') })
                : t('{shooter} ({units}) findet kein Ziel mehr', { shooter: name(shot.group), units: fmt.format(shot.units) }));
        });
        caption.replaceChildren(...lines.map((text) => {
            const p = document.createElement('p');
            p.textContent = text;
            return p;
        }));
    };
    const stop = () => {
        clearInterval(timer);
        timer = null;
        playButton.textContent = t('▶ Abspielen');
    };
    const go = (to) => {
        shown = Math.max(0, Math.min(data.steps.length, to));
        draw();
    };
    document.querySelectorAll('[data-replay]').forEach((button) => button.addEventListener('click', () => {
        const action = button.dataset.replay;
        if (action === 'play') {
            if (timer) { stop(); return; }
            if (shown >= data.steps.length) go(0);
            playButton.textContent = t('⏸ Anhalten');
            timer = setInterval(() => {
                if (shown >= data.steps.length) { stop(); return; }
                go(shown + 1);
            }, 1400);
            return;
        }
        stop();
        go({ first: 0, prev: shown - 1, next: shown + 1, last: data.steps.length }[action]);
    }));
    window.addEventListener('resize', draw);
    draw();
})();

// Live resource counters and construction countdowns.
(() => {
    const fmt = new Intl.NumberFormat(LOCALE_TAG, { maximumFractionDigits: 0 });

    const duration = (s) => {
        s = Math.max(0, Math.floor(s));
        const d = Math.floor(s / 86400);
        const h = Math.floor((s % 86400) / 3600);
        const m = Math.floor((s % 3600) / 60);
        const pad = (n) => String(n).padStart(2, '0');
        const time = (h || d) ? `${pad(h)}:${pad(m)}:${pad(s % 60)}` : `${pad(m)}:${pad(s % 60)}`;
        return d ? `${d}d ${time}` : time;
    };

    // Resource counters, countdowns and buttons waiting for resources. Restartable: after parts
    // of the page were swapped in without a reload (data-swap forms) they start again.
    const startCounters = () => {
    const started = Date.now();
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

    // Costs shown on the page (buildings, research, …) and the buttons waiting for them:
    // as soon as the counters reach a cost it loses its red mark, and a button whose
    // card is fully affordable unlocks (the server checks again on submit).
    const needs = [...document.querySelectorAll('[data-need]')].map((el) => ({
        el,
        resource: el.dataset.need,
        amount: parseFloat(el.dataset.needAmount),
    }));
    const waiting = [...document.querySelectorAll('[data-unlock-when-affordable]')].map((button) => ({
        button,
        needs: needs.filter((n) => button.closest('article, .panel')?.contains(n.el)),
    }));

    let reloading = false;
    const tick = () => {
        const elapsed = (Date.now() - started) / 1000;

        const current = {};
        for (const r of resources) {
            const value = Math.max(0, r.amount + r.rate * elapsed / 3600);
            r.el.textContent = fmt.format(Math.floor(value));
            r.el.classList.toggle('res-exposed', value > r.protectedAmount);
            if (r.el.dataset.resource) {
                current[r.el.dataset.resource] = value;
            }
        }

        const covered = (n) => !(n.resource in current) || current[n.resource] + 1e-6 >= n.amount;
        for (const n of needs) {
            n.el.classList.toggle('lacking', !covered(n));
        }
        for (const w of waiting) {
            if (w.button.disabled && w.needs.length > 0 && w.needs.every(covered)) {
                w.button.disabled = false;
                w.button.classList.add('just-unlocked');
            }
        }
        // Other parts of the page (e.g. the shipyard maximum) follow the counters too.
        document.dispatchEvent(new CustomEvent('resources:tick', { detail: current }));

        for (const c of countdowns) {
            const left = c.remaining - elapsed;
            c.el.textContent = left > 0 ? duration(left) : 'fertig';
            if (left <= 0 && !reloading) {
                reloading = true;
                setTimeout(reloadWhenIdle, 1000);
            }
        }
    };

    tick();
    const timer = setInterval(tick, 1000);

    return () => clearInterval(timer);
    };

    // A finished countdown reloads the page – but never while the player is typing or has
    // unsent input (a ticker post, a mail, amounts): then it waits until that is gone.
    const hasUnsentInput = () => {
        const active = document.activeElement;
        if (active && active.matches('textarea, input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=checkbox]):not([type=radio]), select, [contenteditable]')) {
            return true;
        }
        return [...document.querySelectorAll('textarea, input[type=text], input[type=number], input[type=search], input:not([type])')]
            .some((field) => field.value !== field.defaultValue);
    };
    const reloadWhenIdle = () => {
        if (hasUnsentInput()) {
            setTimeout(reloadWhenIdle, 5000);
            return;
        }
        location.reload();
    };
    let stopCounters = startCounters();

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
                // The folder tabs count their own unread messages.
                document.querySelectorAll('[data-folder-badge]').forEach((badge) => {
                    const count = (data.folders || {})[badge.dataset.folderBadge] || 0;
                    badge.textContent = count;
                    badge.hidden = count < 1;
                });
                if (data.unread < 1) {
                    const button = document.querySelector('[data-read-all] button');
                    if (button) {
                        button.disabled = true;
                        button.title = t('Alles gelesen');
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
            button.textContent = t('Kopiert ✓');
            setTimeout(() => { button.textContent = label; }, 1500);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(link).then(done, () => window.prompt(t('Link kopieren:'), link));
        } else {
            window.prompt(t('Link kopieren:'), link);
        }
    });

    // Vote reminder (overview): shown when this browser hasn't voted for a day; "Später" hides it for some hours.
    (() => {
        const VOTED = 'sternenfall.votedAt', LATER = 'sternenfall.voteLaterAt';
        const get = (key) => { try { return Number(localStorage.getItem(key)) || 0; } catch (e) { return 0; } };
        const set = (key) => { try { localStorage.setItem(key, String(Date.now())); } catch (e) { /* ignore */ } };
        const reminder = document.querySelector('[data-vote-reminder]');
        document.querySelectorAll('[data-vote-link]').forEach((link) => link.addEventListener('click', () => {
            set(VOTED);
            if (reminder) { reminder.hidden = true; }
        }));
        if (!reminder) {
            return;
        }
        reminder.querySelector('[data-vote-later]')?.addEventListener('click', () => {
            set(LATER);
            reminder.hidden = true;
        });
        const now = Date.now();
        reminder.hidden = now - get(VOTED) < 24 * 3600 * 1000 || now - get(LATER) < 6 * 3600 * 1000;
    })();

    // Card pictures on/off (buildings, research, shipyard), remembered per browser; <html> gets the class early in base.html.twig.
    const initPictureToggle = (root) => root.querySelectorAll('[data-picture-toggle]').forEach((button) => {
        const root = document.documentElement;
        const label = () => { button.textContent = root.classList.contains('hide-pictures') ? button.dataset.labelShow : button.dataset.labelHide; };
        button.addEventListener('click', () => {
            const hide = root.classList.toggle('hide-pictures');
            try { localStorage.setItem('sternenfall.hidePictures', hide ? '1' : '0'); } catch (e) { /* ignore */ }
            label();
        });
        label();
    });
    initPictureToggle(document);

    // Lists ordered with ↑/↓ (overview boxes in the account settings, plunder order): moves the row; the form saves the order.
    document.querySelectorAll('[data-reorder]').forEach((list) => {
        list.addEventListener('click', (event) => {
            const button = event.target.closest('[data-reorder-up], [data-reorder-down]');
            if (!button) return;
            // Sorting the plunder order means: use it.
            const ordered = list.closest('.loot-order')?.querySelector('input[name="loot_mode"][value="ordered"]');
            if (ordered) ordered.checked = true;
            const item = button.closest('[data-reorder-item]');
            if (button.matches('[data-reorder-up]') && item.previousElementSibling) {
                item.parentNode.insertBefore(item, item.previousElementSibling);
            } else if (button.matches('[data-reorder-down]') && item.nextElementSibling) {
                item.parentNode.insertBefore(item.nextElementSibling, item);
            }
            button.focus();
        });
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

    // Boxes marked with data-fold fold up when their heading is clicked; the state is remembered (per browser).
    document.querySelectorAll('[data-fold]').forEach((box) => {
        const head = box.querySelector(':scope > h2');
        if (!head) {
            return;
        }
        const key = 'sternenfall.fold.' + box.dataset.fold;
        const set = (folded) => {
            box.classList.toggle('folded', folded);
            head.setAttribute('aria-expanded', folded ? 'false' : 'true');
        };
        head.classList.add('fold-head');
        head.setAttribute('role', 'button');
        head.tabIndex = 0;
        let folded = false;
        try { folded = localStorage.getItem(key) === '1'; } catch (e) { /* ignore */ }
        // data-fold-newest: something new since the last visit (e.g. a black board post) opens the box once.
        const newest = box.dataset.foldNewest;
        if (newest) {
            let seen = null;
            try { seen = localStorage.getItem(key + ':seen'); } catch (e) { /* ignore */ }
            if (null !== seen && seen !== newest) {
                folded = false;
                box.classList.add('fold-new');
            }
            try { localStorage.setItem(key + ':seen', newest); } catch (e) { /* ignore */ }
        }
        // A link straight to the box (#id) opens it.
        set(folded && !(box.id && window.location.hash === '#' + box.id));
        const toggle = (event) => {
            if (event.target.closest('a, button, input, select, form')) {
                return; // links inside the heading keep working
            }
            const now = !box.classList.contains('folded');
            set(now);
            try { localStorage.setItem(key, now ? '1' : '0'); } catch (e) { /* ignore */ }
        };
        head.addEventListener('click', toggle);
        head.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggle(event);
            }
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
            state.textContent = dirty ? t('Ungespeicherte Änderungen') : state.textContent;
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

    // Vacation: show when it would end for the days typed in.
    document.querySelectorAll('[data-vacation-days]').forEach((input) => {
        const out = input.closest('form').querySelector('[data-vacation-end]');
        const show = () => {
            const days = parseFloat(input.value.replace(',', '.'));
            out.textContent = days >= 1 && days <= 30
                ? t('endet am {date}', { date: new Date(Date.now() + days * 86400000).toLocaleString(LOCALE_TAG, { timeZone: window.TIMEZONE || 'Europe/Berlin', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) })
                : (input.value ? t('1 bis 30 Tage') : '');
        };
        input.addEventListener('input', show);
    });

    // Support form: the player field only for reports.
    document.querySelectorAll('[data-support-form]').forEach((form) => {
        const kind = form.querySelector('[data-support-kind]');
        const only = form.querySelector('[data-report-only]');
        kind.addEventListener('change', () => { only.hidden = kind.value !== 'report'; });
    });

    // Extended account: PayPal subscription button (the SDK script loads deferred).
    document.querySelectorAll('[data-extended-subscribe]').forEach((box) => {
        const error = document.querySelector('[data-paypal-error]');
        // The PayPal button only shows once the terms and the immediate start are accepted.
        const consent = document.querySelector('[data-subscribe-consent]');
        const hint = document.querySelector('[data-consent-hint]');
        const toggle = () => { box.hidden = !consent.checked; if (hint) hint.hidden = consent.checked; };
        if (consent) { consent.addEventListener('change', toggle); toggle(); } else { box.hidden = false; }
        const show = (message) => { error.textContent = message; error.hidden = false; };
        // Problems go to the admins (digest mail), so failed bookings can be looked into.
        const report = (kind, message = '') => {
            if (!box.dataset.problemUrl) return;
            const body = new FormData();
            body.append('_token', box.dataset.token);
            body.append('kind', kind);
            body.append('message', String(message).slice(0, 300));
            fetch(box.dataset.problemUrl, { method: 'POST', body, credentials: 'same-origin', keepalive: true }).catch(() => {});
        };
        const render = () => {
            if (!window.paypal) { show(t('PayPal konnte nicht geladen werden.')); report('load'); return; }
            window.paypal.Buttons({
                style: { label: 'subscribe' },
                createSubscription: (data, actions) => actions.subscription.create({ plan_id: box.dataset.plan, custom_id: box.dataset.custom }),
                onApprove: (data) => {
                    const body = new FormData();
                    body.append('_token', box.dataset.token);
                    body.append('subscription', data.subscriptionID);
                    body.append('consent', consent && consent.checked ? '1' : '0');
                    return fetch(box.dataset.url, { method: 'POST', body, credentials: 'same-origin' })
                        .then((response) => response.json())
                        .then((result) => { if (result.ok) { window.location.reload(); } else { show(result.error); } })
                        .catch((e) => { show(t('Die Bestätigung ist fehlgeschlagen. Lade die Seite neu – das Abo wird über PayPal nachgetragen.')); report('confirm', e && e.message); });
                },
                onCancel: () => report('cancel'),
                onError: (e) => { show(t('PayPal meldet einen Fehler. Bitte versuche es später noch einmal.')); report('error', e && (e.message || e)); },
            }).render(box);
        };
        if (window.paypal) { render(); } else { window.addEventListener('load', render); }
    });

    // "Select all" checkbox for a list of checkboxes with the given name.
    document.querySelectorAll('[data-check-all]').forEach((all) => {
        all.addEventListener('change', () => {
            document.querySelectorAll(`input[type=checkbox][name="${all.dataset.checkAll}"]`).forEach((box) => { box.checked = all.checked; });
        });
    });

    // Transport request form: running total and the fixed price (one credit per N resources).
    document.querySelectorAll('[data-transport-offer]').forEach((form) => {
        const per = Number(form.dataset.resourcesPerCredit) || 10;
        const update = () => {
            const total = [...form.querySelectorAll('[data-goods]')].reduce((sum, input) => sum + Math.max(0, Number(input.value) || 0), 0);
            form.querySelector('[data-goods-total]').textContent = total.toLocaleString(LOCALE_TAG);
            form.querySelector('[data-offer-hint]').textContent = Math.ceil(total / per).toLocaleString(LOCALE_TAG);
        };
        form.querySelectorAll('[data-goods]').forEach((input) => input.addEventListener('input', update));
        update();
    });

    // Buttons that open or close all fold-out sections of a page (e.g. queues per planet).
    document.querySelectorAll('[data-details-all]').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelectorAll('details[data-details-group]').forEach((details) => {
                details.open = 'open' === button.dataset.detailsAll;
            });
        });
    });

    // Trade posts: show only those accessible for the own life form and alliance.
    document.querySelectorAll('[data-only-accessible]').forEach((box) => {
        const key = 'sternenfall.tradeOnlyAccessible';
        const apply = () => {
            document.querySelectorAll('[data-post-row]').forEach((row) => { row.hidden = box.checked && row.dataset.accessible !== '1'; });
            try { localStorage.setItem(key, box.checked ? '1' : '0'); } catch (e) { /* ignore */ }
        };
        try { box.checked = localStorage.getItem(key) === '1'; } catch (e) { /* ignore */ }
        box.addEventListener('change', apply);
        apply();
    });

    // Fleet places: a click selects all visible waiting fleets there, a second click deselects them.
    document.querySelectorAll('[data-select-place]').forEach((button) => {
        button.addEventListener('click', () => {
            const boxes = [...document.querySelectorAll('input[name="fleets[]"]')]
                .filter((box) => box.dataset.from === button.dataset.selectPlace && !box.closest('tr').hidden && !box.closest('tr').classList.contains('moving'));
            const select = !boxes.every((box) => box.checked);
            boxes.forEach((box) => {
                box.checked = select;
                box.dispatchEvent(new Event('change', { bubbles: true }));
            });
            button.classList.toggle('active', select);
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
        const number = (n) => Math.floor(n).toLocaleString(LOCALE_TAG);
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
                    ? t('Diese Flotte hat keinen Frachtraum – nur Frachter und Großfrachter transportieren Ladung.')
                    : t('Die ausgewählten Flotten haben keinen Frachtraum.');
            } else if (picked.length > 1) {
                const typed = cargoInputs.reduce((sum, input) => sum + (parseFloat(input.value) || 0), 0);
                const free = capacity - loaded - typed;
                room.textContent = t('📦 Frachtraum der Auswahl {capacity} · bereits geladen {loaded} · neue Ladung {typed} · frei {free} – beim Transport im Konvoi wird die Ladung auf die Frachter verteilt, bei anderen Aufträgen nimmt jede Flotte sie von ihrem Planeten mit.', { capacity: number(capacity), loaded: number(loaded), typed: number(typed), free: free < 0 ? t('{free} – geladen wird, so viel passt', { free: '−' + number(-free) }) : number(free) });
                room.classList.toggle('lacking', free < 0);
            } else {
                const typed = cargoInputs.reduce((sum, input) => sum + (parseFloat(input.value) || 0), 0);
                const free = capacity - loaded - typed;
                room.textContent = t('📦 Frachtraum {capacity} · bereits geladen {loaded} · neue Ladung {typed} · frei {free}', { capacity: number(capacity), loaded: number(loaded), typed: number(typed), free: free < 0 ? t('{free} – geladen wird, so viel passt', { free: '−' + number(-free) }) : number(free) });
                room.classList.toggle('lacking', free < 0);
            }
        };
        cargoInputs.forEach((input) => input.addEventListener('input', () => showCargoRoom(boxes.filter((box) => box.checked))));

        const update = () => {
            const picked = boxes.filter((box) => box.checked);
            const value = action.value;
            const single = picked.length === 1 ? picked[0].value : null;
            count.textContent = picked.length === 0 ? t('Keine Flotte ausgewählt') : (picked.length === 1 ? t('1 Flotte ausgewählt') : t('{count} Flotten ausgewählt', { count: picked.length }));
            // Ships and fighting values of the ticked fleets, added up.
            const strength = document.querySelector('[data-picked-strength]');
            if (strength) {
                const format = new Intl.NumberFormat(LOCALE_TAG, { maximumFractionDigits: 0 });
                [['count', 'shipCount'], ['attack', 'attack'], ['defense', 'defense'], ['shield', 'shield']].forEach(([key, data]) => {
                    strength.querySelector(`[data-sum="${key}"]`).textContent = format.format(picked.reduce((sum, box) => sum + (parseInt(box.dataset[data], 10) || 0), 0));
                });
                strength.hidden = picked.length === 0;
            }
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
                    // data-panel-except: not for these actions (e.g. no cargo to take along on a pickup).
                    const own = matches(part, value) && !(part.dataset.panelExcept || '').split(' ').includes(value);
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
                form.dataset.fuel = picked.reduce((sum, box) => sum + (parseFloat(box.dataset.fuel) || 0), 0);
                form.dataset.crew = picked.reduce((sum, box) => sum + (parseFloat(box.dataset.crew) || 0), 0);
                form.dataset.named = picked.every((box) => box.dataset.named === '1') ? '1' : '0';
            } else {
                delete form.dataset.from;
            }
            form.dispatchEvent(new Event('flight-origin'));
            showCargoRoom(picked);
            submit.disabled = picked.length === 0 || value === '';
            const hint = form.querySelector('[data-submit-hint]');
            if (hint) {
                hint.textContent = picked.length === 0
                    ? t('← Hake oben in der Tabelle die Flotte(n) an, die losfliegen sollen.')
                    : (value === '' ? t('← Wähle oben eine Aktion aus.') : '');
            }
            if (help) {
                help.textContent = action.selectedOptions[0]?.dataset.help ?? '';
            }
            // A fleet waiting at a trade post trades on the post's page: go there instead of flying.
            if (value === 'send:trade' && picked.length === 1 && picked[0].dataset.tradePost) {
                if (help) {
                    help.textContent = t('Die Flotte wartet an einem Handelsstützpunkt – weiter zum Handel …');
                }
                window.location.href = picked[0].dataset.tradePost;
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
                let money = credits;
                // Selling comes first: it frees cargo space and brings credits.
                form.querySelectorAll('[data-sell-good]').forEach((sell) => {
                    const sold = Math.min(number(sell), sellCap(sell));
                    free += sold;
                    money += sold * (parseFloat(sell.dataset.sellPrice) || 0);
                });
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
                update();
            });
        });

        // What the selected fleet has on board, "max" for selling it, and a live preview of
        // credits and cargo space (selling first, like the server; the trader's credits may still cap a sale).
        const fmt = new Intl.NumberFormat(LOCALE_TAG, { maximumFractionDigits: 0 });
        const own = form.dataset.own === '1';
        const preview = form.querySelector('[data-trade-preview]');
        const loaded = () => {
            try { return JSON.parse(fleet.selectedOptions[0]?.dataset.loaded || '{}'); } catch (e) { return {}; }
        };
        const sellCap = (input) => {
            let cap = loaded()[input.dataset.sellGood] || 0;
            if (input.dataset.sellLimit !== '') {
                cap = Math.min(cap, parseFloat(input.dataset.sellLimit) || 0);
            }
            return Math.max(0, Math.floor(cap));
        };
        form.querySelectorAll('[data-sell-max]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = button.previousElementSibling;
                input.value = sellCap(input);
                update();
            });
        });
        function update() {
            const cargo = loaded();
            form.querySelectorAll('[data-loaded-good]').forEach((cell) => {
                const amount = cargo[cell.dataset.loadedGood] || 0;
                cell.textContent = amount > 0 ? fmt.format(amount) : '–';
            });
            if (!preview) {
                return;
            }
            let income = 0;
            let cost = 0;
            let free = parseFloat(fleet.selectedOptions[0]?.dataset.freeCargo) || 0;
            form.querySelectorAll('[data-sell-good]').forEach((input) => {
                const amount = Math.min(number(input), sellCap(input));
                income += amount * (parseFloat(input.dataset.sellPrice) || 0);
                free += amount;
            });
            form.querySelectorAll('[data-buy-good]').forEach((input) => {
                cost += number(input) * (parseFloat(input.dataset.price) || 0);
                if (input.dataset.cargo === '1') {
                    free -= number(input);
                }
            });
            const parts = [];
            if (!own) {
                parts.push(t('Kauf: {cost} Credits', { cost: fmt.format(cost) }));
                parts.push(t('Verkauf: {income} Credits', { income: fmt.format(income) }));
                parts.push(t('Saldo: {balance} Credits', { balance: (income - cost > 0 ? '+' : '') + fmt.format(income - cost) }));
            }
            parts.push(free >= 0 ? t('Frachtraum danach: {free} frei', { free: fmt.format(free) }) : t('Zu wenig Frachtraum: {missing} fehlen', { missing: fmt.format(-free) }));
            const short = cost - income - credits;
            if (!own && short > 0) {
                parts.push(t('Zu wenig Credits: {missing} fehlen', { missing: fmt.format(short) }));
            }
            preview.textContent = parts.join(' · ');
            preview.classList.toggle('lacking', free < 0 || (!own && short > 0));
        }
        fleet.addEventListener('change', update);
        form.addEventListener('input', update);
        update();
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
            summary.textContent = !enabled.checked ? t('(ausgeschaltet)') : (active ? `„${active.name}“ ` : '') + (shown === inView ? '' : t('– {shown} von {total} Flotten', { shown, total: inView }));
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

    // Phones: "Menü" in the tab bar opens the Imperium menu over the page (without JS the link jumps down to it).
    const menuOpener = document.querySelector('[data-menu-open]');
    if (menuOpener) {
        const setMenu = (open) => {
            document.body.classList.toggle('menu-open', open);
            menuOpener.setAttribute('aria-expanded', open ? 'true' : 'false');
        };
        menuOpener.addEventListener('click', (event) => {
            event.preventDefault();
            setMenu(true);
            const close = document.querySelector('[data-menu-close]');
            if (close) close.focus();
        });
        document.querySelectorAll('[data-menu-close]').forEach((button) => button.addEventListener('click', () => {
            setMenu(false);
            menuOpener.focus();
        }));
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && document.body.classList.contains('menu-open')) {
                setMenu(false);
                menuOpener.focus();
            }
        });
        // Back from a page left via the menu (bfcache): start with the menu closed.
        window.addEventListener('pageshow', () => setMenu(false));
    }

    // Quick contact menus: one open at a time, closed by a click elsewhere or Escape, kept on screen.
    const contactMenus = [...document.querySelectorAll('[data-contact-menu]')];
    contactMenus.forEach((menu) => menu.addEventListener('toggle', () => {
        if (!menu.open) {
            return;
        }
        contactMenus.forEach((other) => { if (other !== menu) other.open = false; });
        const list = menu.querySelector('.contact-menu-list');
        list.classList.remove('align-right');
        if (list.getBoundingClientRect().right > document.documentElement.clientWidth - 8) {
            list.classList.add('align-right');
        }
    }));
    if (contactMenus.length) {
        document.addEventListener('click', (event) => {
            contactMenus.forEach((menu) => { if (menu.open && !menu.contains(event.target)) menu.open = false; });
        });
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }
            contactMenus.filter((menu) => menu.open).forEach((menu) => {
                menu.open = false;
                menu.querySelector('summary').focus();
            });
        });
    }

    // Remembered coordinates: own order by drag & drop (handle ⠿, also arrow keys), "clear selection".
    document.querySelectorAll('[data-bookmark-sort]').forEach((body) => {
        document.querySelectorAll('[data-bookmark-clear]').forEach((clear) => clear.addEventListener('click', (event) => {
            event.preventDefault();
            body.querySelectorAll('input[type=checkbox]').forEach((box) => { box.checked = false; });
        }));
        const order = () => [...body.querySelectorAll('tr[data-bookmark-id]')].map((row) => row.dataset.bookmarkId);
        const save = () => {
            const params = new URLSearchParams({ _token: body.dataset.token });
            order().forEach((id) => params.append('ids[]', id));
            fetch(body.dataset.url, { method: 'POST', body: params, credentials: 'same-origin' })
                .then((response) => { if (!response.ok) throw new Error(String(response.status)); })
                .catch(() => window.location.reload());
        };
        const middle = (row) => { const box = row.getBoundingClientRect(); return box.top + box.height / 2; };
        // Swaps the neighbours past the dragged row – the row itself stays in the DOM, so the pointer capture holds.
        const place = (row, y) => {
            for (;;) {
                const prev = row.previousElementSibling;
                const next = row.nextElementSibling;
                if (prev && y < middle(prev)) {
                    row.after(prev);
                } else if (next && y > middle(next)) {
                    row.before(next);
                } else {
                    return;
                }
            }
        };
        body.querySelectorAll('[data-drag-handle]').forEach((handle) => {
            handle.hidden = false;
            const row = handle.closest('tr');
            handle.addEventListener('pointerdown', (event) => {
                if (event.button !== 0) {
                    return;
                }
                event.preventDefault();
                const before = order().join();
                let y = event.clientY;
                handle.setPointerCapture(event.pointerId);
                row.classList.add('dragging');
                // Near the top or bottom edge the page scrolls along (long lists on the phone).
                let frame = 0;
                const scroll = () => {
                    const step = y < 110 ? -12 : (y > window.innerHeight - 50 ? 12 : 0);
                    if (step) {
                        window.scrollBy(0, step);
                        place(row, y);
                    }
                    frame = requestAnimationFrame(scroll);
                };
                frame = requestAnimationFrame(scroll);
                const move = (e) => { y = e.clientY; place(row, y); };
                const end = () => {
                    cancelAnimationFrame(frame);
                    handle.removeEventListener('pointermove', move);
                    handle.removeEventListener('pointerup', end);
                    handle.removeEventListener('pointercancel', end);
                    row.classList.remove('dragging');
                    if (order().join() !== before) {
                        save();
                    }
                };
                handle.addEventListener('pointermove', move);
                handle.addEventListener('pointerup', end);
                handle.addEventListener('pointercancel', end);
            });
            handle.addEventListener('keydown', (event) => {
                const prev = row.previousElementSibling;
                const next = row.nextElementSibling;
                if (event.key === 'ArrowUp' && prev) {
                    row.after(prev);
                } else if (event.key === 'ArrowDown' && next) {
                    row.before(next);
                } else {
                    return;
                }
                event.preventDefault();
                save();
            });
        });
    });

    // Listeners of the page's content on the document: dropped when the content is swapped (data-swap).
    let pageScope = new AbortController();

    // Shipyard build rate (100–200 %): highlight the cost row of the chosen rate and use its maximum.
    const initBuildRates = (root, signal) => root.querySelectorAll('[data-build-rates]').forEach((box) => {
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
        // The affordable maximum of every rate grows with the resource counters.
        document.addEventListener('resources:tick', (event) => {
            const current = event.detail;
            let changed = false;
            box.querySelectorAll('[data-rate-percent]').forEach((row) => {
                let max = parseInt(row.dataset.rateCap, 10);
                row.querySelectorAll('[data-need]').forEach((need) => {
                    if (need.dataset.need in current) {
                        max = Math.min(max, Math.floor((current[need.dataset.need] + 1e-6) / parseFloat(need.dataset.needAmount)));
                    }
                });
                max = Math.max(0, max);
                if (String(max) !== row.dataset.rateMax) {
                    if (parseInt(row.dataset.rateMax, 10) < 1 && max >= 1 && row.dataset.ratePercent === select.value) {
                        button.classList.add('just-unlocked');
                    }
                    row.dataset.rateMax = String(max);
                    changed = true;
                }
            });
            if (changed) {
                update();
            }
        }, { signal });
        // Clicking a cost row picks that rate.
        box.querySelectorAll('[data-rate-percent]').forEach((row) => row.addEventListener('click', () => {
            select.value = row.dataset.ratePercent;
            update();
        }));
        update();
    });
    initBuildRates(document, pageScope.signal);

    // Account: offer the time zone of this device if it differs from the saved one.
    const zoneSelect = document.querySelector('[data-timezone-select]');
    const zoneButton = document.querySelector('[data-browser-timezone]');
    if (zoneSelect && zoneButton) {
        const deviceZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if (deviceZone && deviceZone !== zoneSelect.value && [...zoneSelect.options].some((o) => o.value === deviceZone)) {
            zoneButton.hidden = false;
            zoneButton.textContent += ' (' + deviceZone + ')';
            zoneButton.addEventListener('click', () => {
                zoneSelect.value = deviceZone;
                zoneSelect.form.requestSubmit();
            });
        }
    }

    // Galaxy: browse with the keyboard – ←/→ or A/D the system, ↑/↓ or W/S the galaxy (not while typing in a field).
    const galaxyKeys = document.querySelector('[data-galaxy-keys]');
    if (galaxyKeys) {
        const { galaxy, system, galaxies, systems } = Object.fromEntries(Object.entries(galaxyKeys.dataset).map(([key, value]) => [key, parseInt(value, 10)]));
        const moves = {
            arrowleft: [0, -1], a: [0, -1],
            arrowright: [0, 1], d: [0, 1],
            arrowup: [-1, 0], w: [-1, 0],
            arrowdown: [1, 0], s: [1, 0],
        };
        document.addEventListener('keydown', (event) => {
            const move = moves[event.key.toLowerCase()];
            const typing = event.target.closest('input, textarea, select, [contenteditable="true"]');
            if (!move || typing || event.defaultPrevented || event.ctrlKey || event.altKey || event.metaKey) {
                return;
            }
            const g = Math.min(galaxies, Math.max(1, galaxy + move[0]));
            const s = Math.min(systems, Math.max(1, system + move[1]));
            event.preventDefault();
            if (g !== galaxy || s !== system) {
                const url = new URL(galaxyKeys.action, window.location.href);
                url.searchParams.set('galaxy', g);
                url.searchParams.set('system', s);
                window.location.href = url.toString();
            }
        });
    }

    // Galaxy: the system view – the sun, and the planets on their orbits (see orbit above).
    const systemView = document.querySelector('[data-system-view]');
    if (systemView) {
        const cfg = JSON.parse(systemView.dataset.config);
        const { galaxy: g, system: s, slots, sun, origin } = cfg;
        const NS = 'http://www.w3.org/2000/svg';
        const node = (name, attrs) => {
            const element = document.createElementNS(NS, name);
            Object.entries(attrs).forEach(([key, value]) => element.setAttribute(key, value));
            return element;
        };
        const fmt = new Intl.NumberFormat(LOCALE_TAG, { maximumFractionDigits: 0 });
        const sunRadius = 6 + 4 * sun.size;
        const last = slots.length;
        // Rings evenly spaced for the eye; the distances use the real orbit radii.
        const ring = (p) => sunRadius + 6 + (p - 1) * (100 - sunRadius - 8) / Math.max(1, last - 1);
        systemView.append(node('circle', { r: sunRadius * 1.9, fill: sun.color, opacity: 0.15 }));
        if (sun.binary) {
            systemView.append(node('circle', { cx: -sunRadius * 0.45, r: sunRadius * 0.6, fill: sun.color }));
            systemView.append(node('circle', { cx: sunRadius * 0.45, r: sunRadius * 0.5, fill: '#ffcc80' }));
        } else {
            systemView.append(node('circle', { r: sunRadius, fill: sun.color }));
        }
        const line = node('line', { class: 'sv-line', x1: 0, y1: 0, x2: 0, y2: 0, visibility: 'hidden' });
        const dots = [];
        slots.filter((slot) => slot.fusedInto === null).forEach((slot) => {
            systemView.append(node('circle', { class: 'sv-orbit', r: ring(slot.position) }));
        });
        systemView.append(line);
        slots.filter((slot) => slot.fusedInto === null).forEach((slot) => {
            const kind = slot.name === null ? (slot.post ? 'sv-post' : 'sv-free')
                // Same colours as the planet list: own, alliance, bond/NAP, war; then trade post and status.
                : slot.own ? 'sv-own' : slot.relation ? `sv-${slot.relation}` : slot.post ? 'sv-post' : slot.criminal ? 'sv-criminal' : slot.inactive ? 'sv-inactive' : slot.vacation ? 'sv-vacation' : 'sv-other';
            const dot = node('circle', { class: `sv-planet ${kind}`, r: slot.name === null ? (slot.post ? 1.8 : 1) : slot.own ? 3.2 : 2.6, tabindex: slot.name === null ? -1 : 0 });
            const title = node('title', {});
            title.textContent = slot.name === null ? t('Platz {position}: frei', { position: slot.position }) : t('Platz {position}: {name} ({owner})', { position: slot.position, name: slot.name, owner: slot.alliance ? `${slot.owner} [${slot.alliance}]` : slot.owner });
            dot.append(title);
            systemView.append(dot);
            dots.push({ slot, dot });
        });

        const slider = document.querySelector('[data-system-time]');
        const sliderLabel = document.querySelector('[data-system-time-label]');
        const info = document.querySelector('[data-system-info]');
        let selected = null;
        const moment = () => serverClock.now().getTime() + (slider ? parseInt(slider.value, 10) : 0) * 3600000;
        const at = (p, ms) => {
            const a = orbit.angle(g, s, p, ms);
            return [ring(p) * Math.cos(a), ring(p) * Math.sin(a)];
        };
        const describe = () => {
            if (!selected || !info) return;
            const { slot } = selected;
            const label = slot.name === null ? t('Platz {position}: frei', { position: slot.position }) : t('Platz {position}: {name} ({owner})', { position: slot.position, name: slot.name, owner: slot.owner });
            info.textContent = '';
            if (origin === null || origin === slot.position) {
                info.textContent = origin === slot.position ? label + ' – ' + t('Das ist dein gewählter Planet.') : label;
            } else {
                // The shortest distance in the next 14 days, hour by hour.
                const start = serverClock.now().getTime();
                let best = Infinity;
                let bestAt = start;
                for (let hour = 0; hour <= 336; hour++) {
                    const d = orbit.distance(g, s, origin, slot.position, start + hour * 3600000);
                    if (d < best) { best = d; bestAt = start + hour * 3600000; }
                }
                info.textContent = t('{label} – Strecke von {origin}: {now} · am kürzesten in den nächsten 14 Tagen: {best} ({when})', {
                    label,
                    origin: cfg.originName,
                    now: fmt.format(orbit.distance(g, s, origin, slot.position, moment())),
                    best: fmt.format(best),
                    when: serverClock.format(new Date(bestAt), { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }),
                });
            }
            const link = document.createElement('a');
            link.href = `#slot-${slot.position}`;
            link.textContent = ' ' + t('→ in der Tabelle');
            info.append(link);
        };
        const draw = () => {
            const ms = moment();
            dots.forEach(({ slot, dot }) => {
                const [x, y] = at(slot.position, ms);
                dot.setAttribute('cx', x.toFixed(2));
                dot.setAttribute('cy', y.toFixed(2));
            });
            if (selected && origin !== null && origin !== selected.slot.position) {
                const [x1, y1] = at(origin, ms);
                const [x2, y2] = at(selected.slot.position, ms);
                Object.entries({ x1, y1, x2, y2 }).forEach(([key, value]) => line.setAttribute(key, value.toFixed(2)));
                line.setAttribute('visibility', 'visible');
            } else {
                line.setAttribute('visibility', 'hidden');
            }
            if (sliderLabel && slider) {
                const hours = parseInt(slider.value, 10);
                sliderLabel.textContent = hours === 0 ? t('jetzt') : serverClock.format(new Date(ms), { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
            }
            describe();
        };
        dots.forEach((entry) => {
            const choose = () => {
                dots.forEach(({ dot }) => dot.classList.remove('sv-selected'));
                entry.dot.classList.add('sv-selected');
                selected = entry;
                draw();
            };
            entry.dot.addEventListener('click', choose);
            entry.dot.addEventListener('keydown', (event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); choose(); } });
        });
        slider?.addEventListener('input', draw);
        draw();
        setInterval(draw, 60000);
    }

    // Time slider and ▶ for the maps: the slider goes up to its max in steps of unitMs ahead of now;
    // ▶ plays the whole range in about 12 seconds (smoothly, between the steps) and then starts again.
    const timeLapse = ({ slider, label, play, unitMs, format }, render) => {
        let ahead = 0;
        let frame = 0;
        let last = 0;
        const draw = () => {
            const ms = serverClock.now().getTime() + ahead * unitMs;
            render(ms);
            if (label) label.textContent = ahead < 0.5 ? t('jetzt') : serverClock.format(new Date(ms), format);
        };
        const stop = () => {
            cancelAnimationFrame(frame);
            frame = 0;
            if (play) {
                play.textContent = t('▶ Abspielen');
                play.setAttribute('aria-pressed', 'false');
            }
        };
        const step = (time) => {
            const max = parseFloat(slider.max);
            ahead += last ? (time - last) * max / 12000 : 0;
            last = time;
            if (ahead > max) ahead = 0;
            slider.value = String(Math.round(ahead));
            draw();
            frame = requestAnimationFrame(step);
        };
        play?.addEventListener('click', () => {
            if (frame) {
                stop();
                return;
            }
            last = 0;
            play.textContent = t('⏸ Anhalten');
            play.setAttribute('aria-pressed', 'true');
            frame = requestAnimationFrame(step);
        });
        slider?.addEventListener('input', () => {
            stop();
            ahead = parseFloat(slider.value);
            draw();
        });
        draw();
        setInterval(() => { if (!document.hidden && !frame) draw(); }, 60000);

        return { now: () => serverClock.now().getTime() + ahead * unitMs, redraw: draw };
    };

    // Galaxy map: the systems on four spiral arms around the centre; the galaxy turns as a whole
    // (orbit.spin), so the arms move but the systems keep their distances. Tap to pick a system.
    const galaxyMap = document.querySelector('[data-galaxy-map]');
    if (galaxyMap) {
        const cfg = JSON.parse(galaxyMap.dataset.config);
        const NS = 'http://www.w3.org/2000/svg';
        const node = (name, attrs) => {
            const element = document.createElementNS(NS, name);
            Object.entries(attrs).forEach(([key, value]) => element.setAttribute(key, value));
            return element;
        };
        const sets = { own: new Set(cfg.own), alliance: new Set(cfg.alliance), occupied: new Set(cfg.occupied) };
        const ARMS = 4;
        const perArm = Math.ceil(cfg.systems / ARMS);
        // Fixed place of every system before the galaxy's turn: along its arm from the core outwards, slightly scattered.
        const places = [];
        for (let s = 1; s <= cfg.systems; s++) {
            const arm = Math.floor((s - 1) / perArm);
            const f = ((s - 1) % perArm) / Math.max(1, perArm - 1);
            const jitter = orbit.crc32(`star:${cfg.galaxy}:${s}`);
            const r = 12 + 84 * f + ((jitter & 0xff) / 255 - 0.5) * 4;
            const a = arm * 2 * Math.PI / ARMS + 2.8 * f + (((jitter >>> 8) & 0xff) / 255 - 0.5) * 0.12;
            places[s] = { r, a };
        }
        const kindOf = (s) => (s === cfg.system ? 'gm-current' : sets.own.has(s) ? 'gm-own' : sets.alliance.has(s) ? 'gm-alliance'
            : s === cfg.guild ? 'gm-guild' : sets.occupied.has(s) ? 'gm-occupied' : 'gm-empty');
        const radiusOf = { 'gm-current': 2.4, 'gm-own': 2.1, 'gm-alliance': 1.8, 'gm-guild': 2.1, 'gm-occupied': 1.1, 'gm-empty': 0.7 };

        const defs = node('defs', {});
        const glow = node('radialGradient', { id: 'gm-core' });
        glow.append(node('stop', { offset: '0%', 'stop-color': '#fff8e1', 'stop-opacity': '0.95' }));
        glow.append(node('stop', { offset: '35%', 'stop-color': '#ffcc80', 'stop-opacity': '0.35' }));
        glow.append(node('stop', { offset: '100%', 'stop-color': '#ffcc80', 'stop-opacity': '0' }));
        defs.append(glow);
        galaxyMap.append(defs, node('circle', { r: 30, fill: 'url(#gm-core)' }));
        const turning = node('g', {});
        galaxyMap.append(turning);
        // Faint arms behind the systems.
        for (let arm = 0; arm < ARMS; arm++) {
            const points = [];
            for (let i = 0; i <= 40; i++) {
                const f = i / 40;
                const a = arm * 2 * Math.PI / ARMS + 2.8 * f;
                points.push(`${((12 + 84 * f) * Math.cos(a)).toFixed(1)},${((12 + 84 * f) * Math.sin(a)).toFixed(1)}`);
            }
            turning.append(node('polyline', { class: 'gm-arm', points: points.join(' ') }));
        }
        const dots = [];
        // Empty systems first, so the marked ones lie on top.
        const order = Array.from({ length: cfg.systems }, (_, i) => i + 1).sort((x, y) => (kindOf(x) === 'gm-empty' ? 0 : 1) - (kindOf(y) === 'gm-empty' ? 0 : 1));
        order.forEach((s) => {
            const kind = kindOf(s);
            const { r, a } = places[s];
            const dot = node('circle', { class: `gm-system ${kind}`, cx: (r * Math.cos(a)).toFixed(2), cy: (r * Math.sin(a)).toFixed(2), r: radiusOf[kind] });
            turning.append(dot);
            dots[s] = dot;
        });
        const marker = node('circle', { class: 'gm-selected', r: 4, visibility: 'hidden' });
        turning.append(marker);

        timeLapse({
            slider: document.querySelector('[data-galaxy-time]'),
            label: document.querySelector('[data-galaxy-time-label]'),
            play: document.querySelector('[data-galaxy-play]'),
            unitMs: 3600000,
            format: { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' },
        }, (ms) => {
            turning.setAttribute('transform', `rotate(${(orbit.spin(cfg.galaxy, ms) * 180 / Math.PI).toFixed(3)})`);
        });
        const info = document.querySelector('[data-galaxy-map-info]');
        const select = (s) => {
            const { r, a } = places[s];
            marker.setAttribute('cx', (r * Math.cos(a)).toFixed(2));
            marker.setAttribute('cy', (r * Math.sin(a)).toFixed(2));
            marker.setAttribute('visibility', 'visible');
            if (!info) return;
            const notes = [
                sets.own.has(s) ? t('deine Planeten') : null,
                sets.alliance.has(s) ? t('Allianz') : null,
                s === cfg.guild ? t('Handelsgilde') : null,
                !sets.own.has(s) && !sets.alliance.has(s) && s !== cfg.guild ? (sets.occupied.has(s) ? t('bewohnt') : t('unbewohnt')) : null,
            ].filter(Boolean);
            info.textContent = `${t('System {galaxy}:{system}', { galaxy: cfg.galaxy, system: s })} · ${notes.join(', ')} `;
            const link = document.createElement('a');
            link.href = `${cfg.url}${cfg.url.includes('?') ? '&' : '?'}system=${s}#systemansicht`;
            link.textContent = t('→ anzeigen');
            info.append(link);
        };
        // A tap picks the nearest system (the dots are too small to hit on a phone).
        galaxyMap.addEventListener('click', (event) => {
            const matrix = turning.getScreenCTM();
            if (!matrix) return;
            const point = new DOMPoint(event.clientX, event.clientY).matrixTransform(matrix.inverse());
            let best = null;
            let bestDistance = Infinity;
            for (let s = 1; s <= cfg.systems; s++) {
                const { r, a } = places[s];
                const d = (r * Math.cos(a) - point.x) ** 2 + (r * Math.sin(a) - point.y) ** 2;
                if (d < bestDistance) { bestDistance = d; best = s; }
            }
            if (best !== null && bestDistance < 100) select(best);
        });
    }

    // Universe map: the galaxies on their orbits around the centre of the universe (orbit.galaxyAngle),
    // each turning around itself (orbit.spin). Tap to pick a galaxy.
    const universeMap = document.querySelector('[data-universe-map]');
    if (universeMap) {
        const cfg = JSON.parse(universeMap.dataset.config);
        const NS = 'http://www.w3.org/2000/svg';
        const node = (name, attrs) => {
            const element = document.createElementNS(NS, name);
            Object.entries(attrs).forEach(([key, value]) => element.setAttribute(key, value));
            return element;
        };
        const fmt = new Intl.NumberFormat(LOCALE_TAG, { maximumFractionDigits: 0 });
        const own = new Set(cfg.own);
        // Rings evenly spaced for the eye; the distances use the real orbit radii.
        const ring = (g) => 10 + 85 * (g - 1) / Math.max(1, cfg.galaxies - 1);
        const defs = node('defs', {});
        const core = node('radialGradient', { id: 'um-core' });
        core.append(node('stop', { offset: '0%', 'stop-color': '#ffffff', 'stop-opacity': '0.9' }));
        core.append(node('stop', { offset: '40%', 'stop-color': '#b39ddb', 'stop-opacity': '0.3' }));
        core.append(node('stop', { offset: '100%', 'stop-color': '#b39ddb', 'stop-opacity': '0' }));
        const glow = node('radialGradient', { id: 'um-glow' });
        glow.append(node('stop', { offset: '0%', 'stop-color': '#fff8e1', 'stop-opacity': '1' }));
        glow.append(node('stop', { offset: '100%', 'stop-color': '#b39ddb', 'stop-opacity': '0' }));
        defs.append(core, glow);
        universeMap.append(defs, node('circle', { r: 9, fill: 'url(#um-core)' }));
        for (let g = 1; g <= cfg.galaxies; g++) {
            universeMap.append(node('circle', { class: 'um-orbit' + (g === cfg.galaxy ? ' um-orbit-current' : ''), r: ring(g) }));
        }
        const line = node('line', { class: 'sv-line', visibility: 'hidden' });
        universeMap.append(line);
        const galaxies = [];
        for (let g = 1; g <= cfg.galaxies; g++) {
            const kind = g === cfg.galaxy ? 'um-current' : own.has(g) ? 'um-own' : 'um-other';
            const group = node('g', { class: `um-galaxy ${kind}` });
            const body = node('g', {});
            const size = g === cfg.galaxy ? 1.9 : own.has(g) ? 1.6 : 1.3;
            body.append(node('circle', { r: 3.2 * size, fill: 'url(#um-glow)', opacity: 0.55 }));
            // Two little spiral arms, so one sees the galaxy turn.
            body.append(node('path', { class: 'um-arm', d: `M0,0 q${2.2 * size},${-0.6 * size} ${3 * size},${1.8 * size}` }));
            body.append(node('path', { class: 'um-arm', d: `M0,0 q${-2.2 * size},${0.6 * size} ${-3 * size},${-1.8 * size}` }));
            body.append(node('circle', { class: 'um-dot', r: 0.9 * size }));
            const label = node('text', { class: 'um-label', x: 3.6 * size, y: -2.4 * size });
            label.textContent = String(g);
            group.append(body, label);
            universeMap.append(group);
            galaxies[g] = { group, body };
        }
        const marker = node('circle', { class: 'gm-selected', r: 5.5, visibility: 'hidden' });
        universeMap.append(marker);
        const at = (g, ms) => {
            const a = orbit.galaxyAngle(g, ms);
            return [ring(g) * Math.cos(a), ring(g) * Math.sin(a)];
        };
        const info = document.querySelector('[data-universe-map-info]');
        let selected = null;
        const describe = (ms) => {
            if (!info || selected === null) return;
            const notes = [t('Galaxie {galaxy}', { galaxy: selected }), t('Umlauf: {days} Tage', { days: cfg.periods[selected - 1] })];
            if (own.has(selected)) notes.push(t('deine Planeten'));
            if (selected !== cfg.galaxy) {
                notes.push(t('Weg von Galaxie {from}: {distance}', { from: cfg.galaxy, distance: fmt.format(orbit.galaxyDistance(cfg.galaxy, selected, ms)) }));
            }
            info.textContent = notes.join(' · ') + ' ';
            const link = document.createElement('a');
            link.href = `${cfg.url}?galaxy=${selected}#galaxiekarte`;
            link.textContent = t('→ anzeigen');
            info.append(link);
        };
        const lapse = timeLapse({
            slider: document.querySelector('[data-universe-time]'),
            label: document.querySelector('[data-universe-time-label]'),
            play: document.querySelector('[data-universe-play]'),
            unitMs: 86400000,
            format: { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' },
        }, (ms) => {
            for (let g = 1; g <= cfg.galaxies; g++) {
                const [x, y] = at(g, ms);
                galaxies[g].group.setAttribute('transform', `translate(${x.toFixed(2)} ${y.toFixed(2)})`);
                galaxies[g].body.setAttribute('transform', `rotate(${(orbit.spin(g, ms) * 180 / Math.PI).toFixed(2)})`);
            }
            if (selected !== null) {
                const [x, y] = at(selected, ms);
                marker.setAttribute('cx', x.toFixed(2));
                marker.setAttribute('cy', y.toFixed(2));
                marker.setAttribute('visibility', 'visible');
                if (selected !== cfg.galaxy) {
                    const [x1, y1] = at(cfg.galaxy, ms);
                    Object.entries({ x1, y1, x2: x, y2: y }).forEach(([key, value]) => line.setAttribute(key, value.toFixed(2)));
                    line.setAttribute('visibility', 'visible');
                } else {
                    line.setAttribute('visibility', 'hidden');
                }
            }
            describe(ms);
        });
        // A tap picks the nearest galaxy where it is at the moment shown.
        universeMap.addEventListener('click', (event) => {
            const matrix = universeMap.getScreenCTM();
            if (!matrix) return;
            const point = new DOMPoint(event.clientX, event.clientY).matrixTransform(matrix.inverse());
            const ms = lapse.now();
            let best = null;
            let bestDistance = Infinity;
            for (let g = 1; g <= cfg.galaxies; g++) {
                const [x, y] = at(g, ms);
                const d = (x - point.x) ** 2 + (y - point.y) ** 2;
                if (d < bestDistance) { bestDistance = d; best = g; }
            }
            if (best !== null && bestDistance < 150) {
                selected = best;
                lapse.redraw();
            }
        });
    }

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
            dialog.querySelector('[data-confirm-ok]').textContent = button.dataset.confirmLabel || t('Bestätigen');
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
    const initSortable = (root) => root.querySelectorAll('[data-sortable]').forEach((list) => {
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
                // requestSubmit: a data-swap form then saves without reloading the page.
                const form = list.closest('form');
                if (form.requestSubmit) form.requestSubmit(); else form.submit();
            }
        });
    });
    initSortable(document);

    // Forms marked data-swap (building, ordering ships and defenses, their queues) are sent in the background; the page
    // they lead to replaces only the parts that change – resources, planet list, content – so the
    // page neither reloads nor jumps. Anything unexpected falls back to a normal submit.
    const swapRegions = ['.topbar .resources', '.sidebar-left .planet-list', 'main.content'];
    const swapIn = (html) => {
        const next = new DOMParser().parseFromString(html, 'text/html');
        const parts = swapRegions.map((selector) => [document.querySelector(selector), next.querySelector(selector)]);
        if (parts.some(([now, then]) => !now || !then)) return false;
        parts.forEach(([now, then]) => now.replaceWith(document.importNode(then, true)));
        document.title = next.title;
        stopCounters();
        stopCounters = startCounters();
        pageScope.abort();
        pageScope = new AbortController();
        const main = document.querySelector('main.content');
        initSortable(main);
        initPictureToggle(main);
        initBuildRates(main, pageScope.signal);
        return true;
    };
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-swap]') || event.defaultPrevented || form.dataset.busy) return;
        event.preventDefault();
        const submitter = event.submitter || null;
        const action = submitter?.getAttribute('formaction') || form.action;
        const fallback = () => {
            // A normal submit with the clicked button's value.
            if (submitter?.name) {
                const field = document.createElement('input');
                Object.assign(field, { type: 'hidden', name: submitter.name, value: submitter.value });
                form.append(field);
            }
            form.action = action;
            HTMLFormElement.prototype.submit.call(form);
        };
        form.dataset.busy = '1';
        document.body.classList.add('swapping');
        fetch(action, { method: 'POST', body: new FormData(form, submitter), credentials: 'same-origin' })
            .then((response) => {
                // Only a page like this one can be swapped in (not e.g. the login after a timeout).
                if (!response.ok || new URL(response.url).pathname !== window.location.pathname) throw new Error('other page');
                return response.text();
            })
            .then((html) => { if (!swapIn(html)) throw new Error('parts missing'); })
            .catch(fallback)
            .finally(() => { delete form.dataset.busy; document.body.classList.remove('swapping'); });
    });

    // Flight preview: duration and arrival for the chosen target and speed (same formula as the server).
    const distance = (a, b) => {
        // The same as App\Game\Navigation::distance (keep both in step).
        if (a[0] !== b[0] || a[1] !== b[1]) {
            return 5000 + 500 * Math.abs(a[1] - b[1]) + Math.floor((200 + 100 * a[2] + 200 + 100 * b[2]) / 2) + orbit.galaxyDistance(a[0], b[0], serverClock.now().getTime());
        }
        if (a[2] !== b[2]) return orbit.distance(a[0], a[1], a[2], b[2], serverClock.now().getTime());
        return 5;
    };
    // Distance calculator: the same distance and speed formulas as the game, live while typing.
    const calculator = document.querySelector('[data-distance-calculator]');
    if (calculator) {
        const cfg = JSON.parse(calculator.dataset.distanceCalculator);
        const num = (n) => Math.round(n).toLocaleString(LOCALE_TAG);
        const value = (name) => parseFloat(String(calculator.querySelector(`[data-calc="${name}"]`).value).replace(',', '.'));
        const level = (drive) => parseInt(calculator.querySelector(`[data-calc-drive="${drive}"]`)?.value, 10) || 0;
        const update = () => {
            const from = ['g', 's', 'p'].map((k) => parseInt(value(`from-${k}`), 10));
            const to = ['g', 's', 'p'].map((k) => parseInt(value(`to-${k}`), 10));
            const percent = Math.min(100, Math.max(0.1, value('percent') || 100));
            const lifeform = value('lifeform') || 1;
            if ([...from, ...to].some(Number.isNaN)) {
                return;
            }
            const d = distance(from, to);
            calculator.querySelector('[data-calc-out="distance"]').textContent = t('Entfernung: {distance}', { distance: num(d) });
            // Speeds like Economy::shipSpeed: drive research, life form; probes and fusionators are faster than the fastest.
            const own = Object.fromEntries(cfg.ships.map((s) => [s.type, Math.round(s.speed * (1 + cfg.researchBonus * level(s.drive)) * lifeform)]));
            const fastestRegular = Math.max(...cfg.ships.filter((s) => s.type !== 'scout' && s.type !== 'fusionator').map((s) => own[s.type]));
            own.fusionator = Math.max(own.fusionator, Math.ceil(1.1 * fastestRegular));
            own.scout = Math.max(own.scout, Math.ceil(1.1 * own.fusionator));
            const seconds = (speed) => Math.max(5, Math.round(3600 * d / (speed * percent / 100) / cfg.gameSpeed));
            const at = (s) => serverClock.format(new Date(serverClock.now().getTime() + s * 1000), { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
            let slowest = null;
            let fuel = 0;
            let crew = 0;
            cfg.ships.forEach((s) => {
                const row = calculator.querySelector(`[data-calc-ship="${s.type}"]`);
                const secs = seconds(own[s.type]);
                row.querySelector('[data-calc-speed]').textContent = num(own[s.type]);
                row.querySelector('[data-calc-time]').textContent = duration(secs);
                row.querySelector('[data-calc-arrival]').textContent = at(secs);
                const count = parseInt(row.querySelector('[data-calc-count]').value, 10) || 0;
                row.classList.remove('slowest');
                if (count > 0) {
                    fuel += s.fuel * count;
                    crew += s.crew * count;
                    if (null === slowest || own[s.type] < own[slowest.type]) {
                        slowest = s;
                    }
                }
            });
            const box = calculator.querySelector('[data-calc-fleet]');
            box.hidden = null === slowest;
            if (slowest) {
                calculator.querySelector(`[data-calc-ship="${slowest.type}"]`).classList.add('slowest');
                const secs = seconds(own[slowest.type]);
                // Consumption like Navigation::fuel and the crew's food and water on the way (there and back).
                const hydrogen = 2 * fuel * (1 + d / 10000) * percent / 100;
                const hours = 2 * secs / 3600;
                calculator.querySelector('[data-calc-out="fleet"]').textContent = t('Tempo {speed} ({ship}) · hin {there} · hin und zurück {both} · Verbrauch hin und zurück (±5 %): {hydrogen} Wasserstoff, {food} Nahrung, {water} Wasser', {
                    speed: num(own[slowest.type]),
                    ship: slowest.label,
                    there: duration(secs),
                    both: duration(2 * secs),
                    hydrogen: num(Math.ceil(hydrogen)),
                    food: num(Math.ceil(crew * cfg.foodPerCrew * cfg.gameSpeed * hours)),
                    water: num(Math.ceil(crew * cfg.waterPerCrew * cfg.gameSpeed * hours)),
                });
            }
        };
        calculator.querySelectorAll('input, select').forEach((input) => input.addEventListener('input', update));
        update();
        // Within a system the planets move: keep the times right for "now".
        setInterval(() => { if (!document.hidden) update(); }, 30000);
    }

    document.querySelectorAll('form[data-flight]').forEach((form) => {
        const preview = form.querySelector('[data-flight-preview]');
        const gameSpeed = parseFloat(form.dataset.gameSpeed) || 1;
        const field = (name) => form.querySelector(`[name="${name}"]`);
        // Start and speed may change (fleet selection), so they are read on every update.
        const update = () => {
            if (!form.dataset.from) {
                preview.textContent = t('Wähle eine Flotte aus.');
                return;
            }
            const from = form.dataset.from.split(':').map(Number);
            const shipSpeed = parseFloat(form.dataset.speed);
            const to = ['galaxy', 'system', 'position'].map((n) => parseInt(field(n).value, 10));
            const percent = parseFloat(String(field('speed').value).replace(',', '.'));
            if (to.some(Number.isNaN) || Number.isNaN(percent) || percent < 0.1 || percent > 100 || !shipSpeed) {
                preview.textContent = t('Geschwindigkeit: 0,1 bis 100 %');
                return;
            }
            // Already there: nothing to fly (trading at a post happens on its page).
            if (from.every((value, i) => value === to[i])) {
                preview.textContent = t('Die Flotte ist schon an diesem Ziel – es gibt nichts zu fliegen. Handeln kannst du auf der Seite des Handelsstützpunkts.');
                return;
            }
            const seconds = Math.max(5, Math.round(3600 * distance(from, to) / (shipSpeed * percent / 100) / gameSpeed));
            const arrival = new Date(serverClock.now().getTime() + seconds * 1000);
            const sameDay = serverClock.day(arrival) === serverClock.day(serverClock.now());
            const when = serverClock.format(arrival, sameDay
                ? { hour: '2-digit', minute: '2-digit', second: '2-digit' }
                : { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
            // Fleets that stay at the target don't fly back (same rule as FleetMission::isOneWay).
            const actionValue = form.querySelector('[data-bulk-action]')?.value || '';
            const mission = actionValue.startsWith('send:') ? actionValue.slice(5) : '';
            const oneWay = ['spy', 'station', 'jump', 'trade'].includes(mission) || (mission === 'transport_station' && form.dataset.named === '1');
            const legs = oneWay ? 1 : 2;
            let text = oneWay
                ? t(sameDay ? 'Flugdauer {duration} · Ankunft heute {time} Uhr · die Flotte bleibt am Ziel' : 'Flugdauer {duration} · Ankunft {time} Uhr · die Flotte bleibt am Ziel', { duration: duration(seconds), time: when })
                : t(sameDay ? 'Flugdauer {duration} · Ankunft heute {time} Uhr · Rückkehr {back} nach Start' : 'Flugdauer {duration} · Ankunft {time} Uhr · Rückkehr {back} nach Start', { duration: duration(seconds), time: when, back: duration(2 * seconds) });
            // Consumption: hydrogen shrinks with the speed, food and water grow with the flight time.
            if (form.dataset.fuel !== undefined) {
                const fmt = (n) => Math.ceil(n).toLocaleString(LOCALE_TAG);
                const hydrogen = legs * parseFloat(form.dataset.fuel) * (1 + distance(from, to) / 10000) * percent / 100;
                const crew = parseFloat(form.dataset.crew) || 0;
                const hours = legs * seconds / 3600;
                const food = crew * (parseFloat(form.dataset.foodPerCrew) || 0) * gameSpeed * hours;
                const water = crew * (parseFloat(form.dataset.waterPerCrew) || 0) * gameSpeed * hours;
                text += '\n' + t(oneWay ? 'Verbrauch für den Hinflug (±5 %): {hydrogen} Wasserstoff · {food} Nahrung · {water} Wasser' : 'Verbrauch hin und zurück (±5 %): {hydrogen} Wasserstoff · {food} Nahrung · {water} Wasser', { hydrogen: fmt(hydrogen), food: fmt(food), water: fmt(water) });
            }
            preview.textContent = text;
        };
        form.querySelectorAll('[data-flight-input]').forEach((input) => input.addEventListener('input', update));
        form.addEventListener('flight-origin', update);
        update();
        // The arrival moves with the clock: refreshed every second, so it is right for "now".
        setInterval(() => { if (form.dataset.from && !document.hidden) update(); }, 1000);
    });

    // Short notices at the bottom of the screen (e.g. after sending a probe without reloading).
    const toast = (message, ok) => {
        let stack = document.querySelector('.toast-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'toast-stack';
            stack.setAttribute('aria-live', 'polite');
            document.body.append(stack);
        }
        const note = document.createElement('div');
        note.className = 'flash ' + (ok ? 'flash-success' : 'flash-error') + ' toast';
        note.textContent = message;
        note.addEventListener('click', () => note.remove());
        stack.append(note);
        setTimeout(() => note.remove(), ok ? 5000 : 8000);
    };

    // Spy buttons (🔭 in the universe and in profiles): the probe is sent in the background, the page stays.
    document.querySelectorAll('form[action$="/galaxy/spy"]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const button = form.querySelector('button');
            if (button.disabled) return;
            button.disabled = true;
            fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
                .then((response) => response.json())
                .then((result) => {
                    toast(result.message, result.ok);
                    if (result.ok) button.classList.add('spy-sent');
                })
                .catch(() => form.submit())
                .finally(() => { setTimeout(() => { button.disabled = false; }, 800); });
        });
    });

    // Ship pickers: as soon as ships are picked, a box adds up their number and fighting values.
    document.querySelectorAll('[data-ship-picker]').forEach((picker) => {
        const box = picker.querySelector('[data-fleet-strength]');
        const inputs = [...picker.querySelectorAll('input[data-attack]')];
        if (!box || inputs.length === 0) return;
        const number = new Intl.NumberFormat(LOCALE_TAG, { maximumFractionDigits: 0 });
        const update = () => {
            const sums = { count: 0, attack: 0, defense: 0, shield: 0 };
            inputs.forEach((input) => {
                const count = Math.max(0, parseInt(input.value, 10) || 0);
                sums.count += count;
                sums.attack += count * (parseInt(input.dataset.attack, 10) || 0);
                sums.defense += count * (parseInt(input.dataset.defense, 10) || 0);
                sums.shield += count * (parseInt(input.dataset.shield, 10) || 0);
            });
            Object.entries(sums).forEach(([key, value]) => { box.querySelector(`[data-sum="${key}"]`).textContent = number.format(value); });
            box.hidden = sums.count === 0;
        };
        inputs.forEach((input) => input.addEventListener('input', update));
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

// Highscores, "Zu meinem Platz": the own row in the middle of the screen, not hidden under the sticky top bar.
(function () {
    'use strict';
    if (window.location.hash !== '#own') {
        return;
    }
    const row = document.getElementById('own');
    if (!row) {
        return;
    }
    // After the layout settled (pictures, fonts), so the jump isn't undone by content moving down.
    const centre = () => row.scrollIntoView({ block: 'center' });
    if (document.readyState === 'complete') {
        centre();
    } else {
        window.addEventListener('load', centre, { once: true });
    }
})();

// Amounts: k, m and g after a number multiply it by a thousand, a million, a billion
// (25k → 25000, 1,5m → 1500000) – in every field for resources, goods, prices and ships.
(function () {
    'use strict';
    const FACTORS = { k: 1e3, m: 1e6, g: 1e9 };
    const AMOUNT = /^(cargo|pickup|sell|buy|goods|prices|buy_prices|buy_limits|ships|load|drop|split|quantity|amount|credits|resources)(\[|$)|\[ships\]/;
    const isAmount = (el) => el instanceof HTMLInputElement && el.type === 'number'
        && (AMOUNT.test(el.name) || el.hasAttribute('data-count') || el.hasAttribute('data-goods'));
    // Delegated, so fields swapped in later (AJAX) take part too.
    document.addEventListener('keydown', (event) => {
        const factor = FACTORS[event.key.toLowerCase()];
        const input = event.target;
        if (!factor || event.ctrlKey || event.metaKey || event.altKey || !isAmount(input)) {
            return;
        }
        event.preventDefault();
        const value = input.valueAsNumber;
        if (Number.isNaN(value)) {
            return;
        }
        input.value = String(Math.round(value * factor));
        // Live sums (cargo room, costs) follow as if the digits had been typed.
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    });
})();

// Universe: "Hervorheben" – only the chosen kinds of planets stay bright (own alliance, allies,
// enemies, trade posts); the choice is remembered in this browser.
(function () {
    'use strict';
    const box = document.querySelector('[data-galaxy-filters]');
    const table = document.querySelector('.galaxy-table');
    if (!box || !table) {
        return;
    }
    const KEY = 'sternenfall.galaxyFilters';
    let active = [];
    try {
        active = JSON.parse(localStorage.getItem(KEY) || '[]').filter((f) => typeof f === 'string');
    } catch (e) { /* ignore */ }
    const apply = () => {
        box.querySelectorAll('[data-galaxy-filter]').forEach((chip) => chip.setAttribute('aria-pressed', String(active.includes(chip.dataset.galaxyFilter))));
        table.classList.toggle('filtering', active.length > 0);
        table.querySelectorAll('tbody tr').forEach((row) => {
            const kinds = [row.dataset.rel || '', row.hasAttribute('data-post') ? 'post' : ''];
            row.classList.toggle('filter-match', kinds.some((k) => k !== '' && active.includes(k)));
        });
    };
    box.addEventListener('click', (event) => {
        const chip = event.target.closest('[data-galaxy-filter]');
        if (!chip) {
            return;
        }
        const kind = chip.dataset.galaxyFilter;
        active = active.includes(kind) ? active.filter((k) => k !== kind) : [...active, kind];
        try {
            localStorage.setItem(KEY, JSON.stringify(active));
        } catch (e) { /* ignore */ }
        apply();
    });
    apply();
})();

// Overview, fleet movements: chips show only some kinds of flights (combat, trade, …); remembered in this browser.
(function () {
    'use strict';
    const box = document.querySelector('[data-movement-filters]');
    const table = document.querySelector('.movement-table');
    if (!box || !table) {
        return;
    }
    const KEY = 'sternenfall.movementFilters';
    let active = [];
    try {
        active = JSON.parse(localStorage.getItem(KEY) || '[]').filter((k) => typeof k === 'string');
    } catch (e) { /* ignore */ }
    const chips = [...box.querySelectorAll('[data-movement-filter]')];
    // Kinds no longer present (no such flight right now) don't hide everything.
    const apply = () => {
        const shown = active.filter((k) => chips.some((c) => c.dataset.movementFilter === k));
        chips.forEach((chip) => chip.setAttribute('aria-pressed', String(shown.includes(chip.dataset.movementFilter))));
        table.classList.toggle('filtering', shown.length > 0);
        table.querySelectorAll('tr[data-mv]').forEach((row) => row.classList.toggle('mv-match', shown.includes(row.dataset.mv)));
    };
    box.addEventListener('click', (event) => {
        const chip = event.target.closest('[data-movement-filter]');
        if (!chip) {
            return;
        }
        const kind = chip.dataset.movementFilter;
        active = active.includes(kind) ? active.filter((k) => k !== kind) : [...active, kind];
        try {
            localStorage.setItem(KEY, JSON.stringify(active));
        } catch (e) { /* ignore */ }
        apply();
    });
    apply();
})();
