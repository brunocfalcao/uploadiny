export const APPEND_PREFERENCE_KEY = 'uploadiny.appendToLast';

export function readAppendPreference(storage) {
    try { return storage?.getItem(APPEND_PREFERENCE_KEY) !== '0'; } catch { return true; }
}

export function writeAppendPreference(storage, enabled) {
    try { storage?.setItem(APPEND_PREFERENCE_KEY, enabled ? '1' : '0'); } catch { /* Private mode: the choice just is not remembered. */ }
}

export function appendTarget(enabled, latestId) {
    return enabled && latestId ? latestId : null;
}

export function formatLastUpload(completedAt, count, now = new Date(), locale = undefined) {
    const total = Number(count) || 0;
    const files = `${total} ${total === 1 ? 'file' : 'files'}`;
    const date = new Date(completedAt);
    if (!completedAt || Number.isNaN(date.getTime())) return `Last upload: ${files}`;
    const time = new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(date);
    const sameDay = (a, b) => a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
    let when;
    if (sameDay(date, now)) when = `Today ${time}`;
    else if (sameDay(date, yesterday)) when = `Yesterday ${time}`;
    else when = `${new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short' }).format(date)} ${time}`;
    return `Last upload: ${when} · ${files}`;
}

export function formatChunkTime(value, locale = 'en-GB') {
    const date = new Date(value);
    if (!value || Number.isNaN(date.getTime())) return null;
    return new Intl.DateTimeFormat(locale, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(date);
}

export function localizeTimes(root) {
    for (const time of root.querySelectorAll('time[data-local-time]')) {
        const text = formatChunkTime(time.dateTime);
        if (text) time.textContent = text;
    }
}
