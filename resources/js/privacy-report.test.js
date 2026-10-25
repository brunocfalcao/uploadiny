import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { renderReportEntries, reportRow, reportSummary } from './privacy-report.js';

class Element {
    constructor(tagName) { this.tagName = tagName; this.children = []; this.textContent = ''; this.className = ''; }
    set innerHTML(_value) { throw new Error('Report values must never be written as markup'); }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.textContent = ''; this.children = [...children]; }
}
const document = { createElement: tag => new Element(tag) };
const text = element => element.children.length ? element.children.map(text).join('') : element.textContent;

test('report entries are written as text in every column, including markup from the file', () => {
    const row = reportRow(document, { line: 7, kind: 'access', app: '<img src=x onerror=alert(1)>', accessed: 'Photos', detail: 'Access started', time: '20 Oct 2026, 09:12:01 +01:00' });
    assert.equal(row.tagName, 'tr');
    assert.match(row.className, /\balign-middle\b/);
    assert.deepEqual(row.children.map(text), ['7', '<img src=x onerror=alert(1)>', 'Photos', 'Access started', '20 Oct 2026, 09:12:01 +01:00']);
    assert.deepEqual(row.children.map(cell => cell.tagName), ['td', 'td', 'td', 'td', 'td']);
});

test('unreadable and unknown lines show their saved text, with a note when it was shortened', () => {
    const unreadable = reportRow(document, { line: 3, kind: 'unreadable', app: null, accessed: null, detail: null, time: null, raw: '<b>not json</b>', raw_truncated: false, raw_bytes: 15 });
    assert.deepEqual(unreadable.children.map(text), ['3', '—', 'Unreadable line', '<b>not json</b>', '—']);
    assert.equal(unreadable.children[3].children[0].tagName, 'code');
    const other = reportRow(document, { line: 9, kind: 'other', app: 'com.example.future', accessed: 'futureType', detail: null, time: null, raw: '{"type":"futureType"}', raw_truncated: true, raw_bytes: 70000 });
    assert.deepEqual(other.children.map(text), ['9', 'com.example.future', 'Other entry (futureType)', '{"type":"futureType"} … (shortened from 70,000 bytes; download the original for the full line)', '—']);
});

test('rendering replaces the previous page instead of appending to it', () => {
    const body = new Element('tbody');
    renderReportEntries(document, body, [{ line: 1, kind: 'network', app: 'a', accessed: 'b', detail: 'c', time: 'd' }, { line: 2, kind: 'network', app: 'e', accessed: 'f', detail: 'g', time: 'h' }]);
    renderReportEntries(document, body, [{ line: 3, kind: 'network', app: 'i', accessed: 'j', detail: 'k', time: 'l' }]);
    assert.deepEqual(body.children.map(row => row.children[0].textContent), ['3']);
    renderReportEntries(document, body, []);
    assert.deepEqual(body.children, []);
});

test('the summary always states which entries are shown out of the whole report', () => {
    const entries = Array.from({ length: 200 }, (_, index) => ({ kind: index < 2 ? 'unreadable' : 'network' }));
    assert.equal(reportSummary({ page: 1, per_page: 200, total_entries: 3412, first_entry: 1, last_entry: 200, entries }), 'Showing entries 1–200 of 3,412 · Page 1 of 18 · 2 unreadable lines are shown as saved');
    assert.equal(reportSummary({ page: 18, per_page: 200, total_entries: 3412, first_entry: 3401, last_entry: 3412, entries: [{ kind: 'unreadable' }] }), 'Showing entries 3,401–3,412 of 3,412 · Page 18 of 18 · 1 unreadable line is shown as saved');
    assert.equal(reportSummary({ page: 1, per_page: 200, total_entries: 1, first_entry: 1, last_entry: 1, entries: [{ kind: 'access' }] }), 'Showing entries 1–1 of 1 · Page 1 of 1');
    assert.equal(reportSummary({ page: 1, per_page: 200, total_entries: 0, first_entry: null, last_entry: null, entries: [] }), 'This report has no entries.');
    assert.equal(reportSummary({ page: 5, per_page: 200, total_entries: 10, first_entry: null, last_entry: null, entries: [] }), 'No entries on page 5. The report has 10 entries.');
});

test('the report view never builds markup from report data', () => {
    const source = readFileSync(new URL('./privacy-report.js', import.meta.url), 'utf8');
    assert.doesNotMatch(source, /innerHTML|outerHTML|insertAdjacentHTML|document\.write/);
});
