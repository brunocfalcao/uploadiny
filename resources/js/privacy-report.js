// Readable view of an App Privacy Report page returned by /images/{id}/report.
// Every value comes from an uploaded file, so it is written as text, never markup.
const number = value => Number(value).toLocaleString('en-GB');

export function reportSummary(page) {
    if (!page.total_entries) return 'This report has no entries.';
    const pages = Math.max(1, Math.ceil(page.total_entries / page.per_page));
    const unreadable = page.entries.filter(entry => entry.kind === 'unreadable').length;
    const range = page.first_entry === null
        ? `No entries on page ${number(page.page)}. The report has ${number(page.total_entries)} ${page.total_entries === 1 ? 'entry' : 'entries'}.`
        : `Showing entries ${number(page.first_entry)}–${number(page.last_entry)} of ${number(page.total_entries)} · Page ${number(page.page)} of ${number(pages)}`;
    return unreadable ? `${range} · ${unreadable} unreadable ${unreadable === 1 ? 'line is' : 'lines are'} shown as saved` : range;
}

function rawText(entry) {
    const note = entry.raw_truncated ? ` … (shortened from ${number(entry.raw_bytes ?? 0)} bytes; download the original for the full line)` : '';
    return `${entry.raw ?? ''}${note}`;
}

function cell(document, text, className = '') {
    const element = document.createElement('td');
    if (className) element.className = className;
    element.textContent = text ?? '—';
    return element;
}

export function reportRow(document, entry) {
    const row = document.createElement('tr');
    row.className = `align-middle report-entry report-entry-${entry.kind}`;
    const raw = entry.kind === 'unreadable' || entry.kind === 'other';
    const accessed = entry.kind === 'unreadable' ? 'Unreadable line' : entry.kind === 'other' ? `Other entry${entry.accessed ? ` (${entry.accessed})` : ''}` : entry.accessed;
    const detail = cell(document, raw ? null : entry.detail, raw ? 'report-raw' : '');
    if (raw) {
        const code = document.createElement('code');
        code.textContent = rawText(entry);
        detail.replaceChildren(code);
    }
    row.append(cell(document, String(entry.line), 'report-line'), cell(document, entry.app), cell(document, accessed), detail, cell(document, entry.time, 'report-time'));
    return row;
}

export function renderReportEntries(document, body, entries) {
    body.replaceChildren(...entries.map(entry => reportRow(document, entry)));
}
