// Device fingerprint for multi account detection, sent along with every login.
// No third-party code: a SHA-256 hash of a few browser properties and a small canvas drawing.
(() => {
    const field = document.querySelector('[data-device-fingerprint]');
    if (!field || !window.crypto || !window.crypto.subtle) {
        return;
    }

    const canvasSample = () => {
        try {
            const canvas = document.createElement('canvas');
            canvas.width = 220;
            canvas.height = 40;
            const ctx = canvas.getContext('2d');
            ctx.textBaseline = 'top';
            ctx.font = '16px Arial';
            ctx.fillStyle = '#f60';
            ctx.fillRect(10, 5, 90, 20);
            ctx.fillStyle = '#069';
            ctx.fillText('Starfall ✦ 1.234,5', 4, 12);
            ctx.strokeStyle = 'rgba(120, 200, 80, 0.7)';
            ctx.arc(160, 20, 14, 0, Math.PI * 2);
            ctx.stroke();
            return canvas.toDataURL();
        } catch (e) {
            return '';
        }
    };
    const compute = async () => {
        const parts = [
            navigator.userAgent, navigator.language, (navigator.languages || []).join(','), navigator.platform,
            navigator.hardwareConcurrency, navigator.deviceMemory, screen.width + 'x' + screen.height + 'x' + screen.colorDepth,
            window.devicePixelRatio, Intl.DateTimeFormat().resolvedOptions().timeZone, new Date().getTimezoneOffset(),
            canvasSample(),
        ].join('|');
        const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(parts));
        return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
    };
    compute().then((hash) => { field.value = hash; }).catch(() => { /* login works without it */ });
})();
