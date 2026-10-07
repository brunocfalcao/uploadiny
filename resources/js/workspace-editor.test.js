import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import * as drawing from './drawing.js';
import { createCalloutEditor } from './callout-editor.js';
import { drawingShortcutAction } from './drawing-shortcuts.js';
import { appendTarget, formatChunkTime, formatLastUpload, localizeTimes as localizeChunkTimes, readAppendPreference, writeAppendPreference } from './append-to-last.js';

const app = readFileSync(new URL('./app.js', import.meta.url), 'utf8').replace(/^import[\s\S]*?;\n/gm, '');
const tick = () => new Promise(resolve => setImmediate(resolve));

function memoryStorage(initial = {}) {
    const data = { ...initial };
    return { getItem: key => data[key] ?? null, setItem: (key, value) => { data[key] = String(value); }, data };
}

function workspace(t, search = '', storage = memoryStorage()) {
    const previousDocument = globalThis.document;
    const nodes = new Map(); const timers = new Map(); const intervals = []; let timerId = 0; let reloads = 0; const popstate = []; const urlCalls = [];
    const where = { pathname: '/projects/1', search, hash: '', reload() { reloads++; } };
    const history = { pushState(_s, _t, url) { urlCalls.push(['push', url]); where.search = url.includes('?') ? url.slice(url.indexOf('?')) : ''; }, replaceState(_s, _t, url) { urlCalls.push(['replace', url]); where.search = url.includes('?') ? url.slice(url.indexOf('?')) : ''; } };
    const remote = { chunk: { id: 'chunk-one', completed_at: 't1', file_count: 3 }, page: null, chunkResponse: null, pageResponse: null, chunkRequests: 0, pageRequests: 0 };
    class Element {
        constructor(id = '', tagName = 'div') {
            Object.assign(this, { id, tagName, value: '', textContent: '', children: [], listeners: {}, dataset: {}, style: { setProperty() {} }, classList: { toggle() {}, add() {}, remove() {} }, parentElement: {}, disabled: false, hidden: false, open: false, clientWidth: 800, clientHeight: 600 });
        }
        addEventListener(type, listener) { (this.listeners[type] ??= []).push(listener); }
        emit(type, properties = {}) {
            const event = { type, target: this, currentTarget: this, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, stopPropagation() {}, ...properties };
            for (const listener of this.listeners[type] ?? []) listener(event);
            return event;
        }
        dispatchEvent(event) { this.emit(event.type); }
        click() { if (!this.disabled) this.emit('click'); }
        focus() { document.activeElement?.blur(); document.activeElement = this; this.emit('focus'); }
        blur() { if (document.activeElement === this) { document.activeElement = null; this.emit('blur'); } }
        closest(selector) { return (selector === '.callout-text' && this.className === 'callout-text') || (selector.includes('textarea') && this.tagName === 'textarea') || (selector.startsWith('input,') && this.tagName === 'input') ? this : null; }
        append(...children) { this.children.push(...children); }
        replaceChildren(...children) { this.children = children; }
        setAttribute() {} removeAttribute() {} toggleAttribute() {} setPointerCapture() {}
        getBoundingClientRect() { return { left: 0, top: 0, width: 640, height: 480 }; }
        getContext() { return new Proxy({ measureText: text => ({ width: text.length * 10 }) }, { get: (target, key) => target[key] ?? (() => {}) }); }
        toDataURL() { return 'data:image/png;base64,snapshot'; }
        pause() {} load() {}
    }
    const node = id => { if (!nodes.has(id)) nodes.set(id, new Element(id, id === 'image-comments' ? 'textarea' : id === 'drawing-color-hex' ? 'input' : 'div')); return nodes.get(id); };
    const document = new Element(); document.getElementById = node; document.createElement = tag => new Element('', tag);
    const card = new Element(); card.dataset = { chunkImages: '["one","two","three"]', openImage: 'one', chunk: 'chunk-one' };
    const tool = new Element(); tool.dataset.tool = 'callout';
    const selectTool = new Element(); selectTool.dataset.tool = 'select';
    document.visibilityState = 'visible';
    node('workspace').dataset = { latest: 'chunk-one', latestCompleted: 't1', latestCount: '3' };
    node('workspace-message').hidden = true;
    document.querySelector = selector => selector === '[data-workspace]' ? node('workspace') : new Element();
    const cards = () => node('chunk-list').children.length ? node('chunk-list').children : [card];
    document.querySelectorAll = selector => selector === '[data-chunk-images]' || selector === '[data-open-image]' ? cards() : selector === '[data-tool]' ? [selectTool, tool] : [];
    node('workspace-config').textContent = JSON.stringify({ project: { id: 1 }, project_url: '/projects/1', last_chunk_url: '/projects/1/last-chunk', upload_url: '/projects/1/chunks/start' });
    node('drawing-color').value = '#ef4444'; node('drawing-color-hex').value = '#ef4444'; node('drawing-width').value = '6';
    const assets = Object.fromEntries(['one', 'two', 'three'].map(id => [id, { id, name: id, comments: '', annotations: [], revision: 0, media_type: 'image', description_status: 'ready', preview_url: id }]));
    const writes = []; const starts = []; const deletions = []; const duplicates = []; let respond = async () => {};
    globalThis.document = document;
    t.after(() => { globalThis.document = previousDocument; });
    runInNewContext(app, {
        ...drawing, createCalloutEditor, drawingShortcutAction, document, appendTarget, formatLastUpload, readAppendPreference, writeAppendPreference, localStorage: storage,
        enhanceAgentAccess() {}, enhanceProjectSelect() {}, enhanceRecordingPreviews() {}, localizeTimes() {}, setRecordingPoster() {},
        window: { innerWidth: 1200, addEventListener(type, listener) { if (type === 'popstate') popstate.push(listener); } },
        history, URLSearchParams,
        ResizeObserver: class { observe() {} }, Event: class { constructor(type) { this.type = type; } },
        Image: class { naturalWidth = 640; naturalHeight = 480; set src(value) { this.onload(); } },
        Option: class {}, FormData: class {}, structuredClone, clearTimeout: id => timers.delete(id),
        setTimeout: callback => { timers.set(++timerId, callback); return timerId; }, setInterval: callback => { intervals.push(callback); return intervals.length; }, clearInterval() {},
        location: where, confirm: () => true,
        DOMParser: class {
            parseFromString() {
                const next = remote.page;
                return { getElementById: id => id === 'chunk-list' && !next.noList ? { childNodes: next.cards } : null, querySelector: selector => selector === '[data-workspace]' ? { dataset: next.dataset } : selector === '.project-nav' ? { childNodes: ['nav'] } : null };
            }
        },
        fetch: async (url, options = {}) => {
            if (url === '/projects/1/last-chunk') {
                remote.chunkRequests++;
                if (remote.chunkResponse) return remote.chunkResponse();
                return { ok: true, json: async () => ({ chunk: remote.chunk }) };
            }
            if (url === '/projects/1') {
                remote.pageRequests++;
                if (remote.pageResponse) return remote.pageResponse();
                return { ok: true, text: async () => '<html></html>' };
            }
            if (url === '/projects/1/chunks/start') { starts.push(JSON.parse(options.body)); return { ok: true, status: 201, json: async () => ({ id: 'draft-1' }) }; }
            if (url === '/chunks') return { ok: true, json: async () => ({ destinations: [] }) };
            const id = url.split('/')[2];
            if (options.method === 'POST' && url.endsWith('/duplicate')) {
                duplicates.push({ id, saved: writes.length });
                assets.copy = { ...structuredClone(assets[id]), id: 'copy', name: 'copy', annotations: [], comments: '', revision: 0, preview_url: 'copy' };
                return { ok: true, status: 201, json: async () => structuredClone(assets.copy) };
            }
            if (options.method === 'DELETE') { deletions.push(id); delete assets[id]; return { ok: true, json: async () => ({}) }; }
            if (options.method === 'PATCH') {
                const body = JSON.parse(options.body); writes.push({ id, ...body });
                const result = await respond(body, id);
                if (result === false) return { ok: false, json: async () => ({ message: 'Connection lost' }) };
                assert.equal(body.revision, assets[id].revision);
                Object.assign(assets[id], { annotations: body.annotations, comments: body.comments, revision: body.revision + 1 });
            }
            return { ok: true, json: async () => structuredClone(assets[id]) };
        },
    });
    return {
        where, urlCalls, async pop(search) { where.search = search; for (const listener of popstate) listener({}); await tick(); await tick(); },
        node, assets, writes, starts, storage, deletions, duplicates, document, tool, selectTool, remote, timers,
        get reloads() { return reloads; },
        async poll() { intervals[0](); await tick(); },
        arrive(id = 'chunk-two', count = 1) {
            const fresh = new Element(); fresh.dataset = { chunkImages: JSON.stringify(['two']), openImage: 'two', chunk: id };
            remote.chunk = { id, completed_at: 't2', file_count: count };
            remote.page = { cards: [fresh], dataset: { latest: id, latestCompleted: 't2', latestCount: String(count) } };
            return fresh;
        },
        async uploadFiles() { const input = node('image-input'); input.files = [{ name: 'shot.png', size: 10 }]; input.emit('change'); await tick(); await tick(); await tick(); },
        async open() { card.click(); await tick(); assert.equal(node("editor-name").textContent, "one", node("workspace-message").textContent); assert.equal(node("annotation-canvas").hidden, false, node("workspace-message").textContent); },
        async settle() { await tick(); },
        async autosave() { const pending = [...timers.values()]; timers.clear(); for (const callback of pending) callback(); await tick(); },
        respondWith(callback) { respond = callback; },
        type(element, value) { element.focus(); element.value = value; element.emit('input'); },
        key(key, target = document, extra = {}) { const event = target.emit('keydown', { key, metaKey: true, ctrlKey: false, altKey: false, shiftKey: false, ...extra }); if (target !== document && !event.defaultPrevented) { event.currentTarget = document; for (const listener of document.listeners.keydown) listener(event); } return event; },
        annotate(text) { tool.click(); node('annotation-canvas').emit('pointerdown', { button: 0, clientX: 320, clientY: 240, pointerId: 1 }); assert.equal(node('callout-overlay').hidden, false, node('workspace-message').textContent); const field = node('callout-overlay').children[1].children[1]; field.value = text; field.emit('input'); return field; },
    };
}

test('autosaves callout text and written feedback without pressing Save', async t => {
    const ui = workspace(t); await ui.open();
    assert.deepEqual(ui.assets.one.annotations, []);
    const field = ui.annotate('Change CRUISE to SPEED');
    ui.type(ui.node('image-comments'), 'Make these buttons bigger.');
    await ui.autosave();
    assert.equal(ui.writes.length, 1);
    assert.equal(ui.assets.one.annotations[0].text, 'Change CRUISE to SPEED');
    assert.equal(ui.assets.one.comments, 'Make these buttons bigger.');
    assert.equal(ui.assets.one.revision, 1);
    assert.equal(ui.node('save-state').textContent, 'Saved');
    assert.equal(ui.assets.two.revision, 0);
    assert.equal(field.value, 'Change CRUISE to SPEED');
});

test('Cmd+Z undoes focused annotation text and Cmd+Enter saves immediately', async t => {
    const ui = workspace(t); await ui.open();
    const field = ui.annotate('Wrong label');
    assert.equal(ui.key('z', field).defaultPrevented, true);
    ui.key('Enter'); await ui.settle();
    assert.equal(ui.assets.one.annotations[0].text, '');
    assert.notEqual(ui.document.activeElement, field);
    ui.key('z'); ui.key('Enter'); await ui.settle();
    assert.deepEqual(ui.assets.one.annotations, []);
});

test('Cmd arrows save first, stay within the chunk and leave ordinary callout arrows available', async t => {
    const ui = workspace(t); await ui.open();
    ui.key('ArrowLeft'); await ui.settle();
    assert.equal(ui.node('editor-name').textContent, 'one');
    ui.annotate('Keep this exact annotation');
    const target = ui.node('callout-overlay').children[0];
    assert.equal(target.emit('keydown', { key: 'ArrowLeft' }).defaultPrevented, true);
    assert.equal(ui.key('ArrowRight', target).defaultPrevented, true);
    await ui.settle();
    assert.equal(ui.node('editor-name').textContent, 'two');
    assert.equal(ui.assets.one.annotations[0].text, 'Keep this exact annotation');
    ui.key('ArrowRight'); await ui.settle(); ui.key('ArrowRight'); await ui.settle();
    assert.equal(ui.node('editor-name').textContent, 'three');
    ui.key('ArrowLeft'); await ui.settle();
    assert.equal(ui.node('editor-name').textContent, 'two');
});

test('autosave serializes edits made during a request, then navigation uses the latest revision', async t => {
    const ui = workspace(t); await ui.open();
    let release; ui.respondWith(() => new Promise(resolve => { release = resolve; }));
    ui.type(ui.node('image-comments'), 'First edit'); await ui.autosave();
    assert.equal(ui.writes.length, 1);
    assert.equal(ui.node('image-comments').disabled, false);
    ui.type(ui.node('image-comments'), 'Second edit');
    assert.equal(ui.key('ArrowRight', ui.node('image-comments')).defaultPrevented, true);
    ui.respondWith(async () => {}); release(); await ui.settle();
    assert.equal(ui.writes.length, 2);
    assert.equal(ui.writes[1].revision, 1);
    assert.equal(ui.assets.one.comments, 'Second edit');
    assert.equal(ui.node('editor-name').textContent, 'two');
    assert.equal(ui.assets.two.revision, 0);
});

test('failed autosave keeps the draft and failed navigation stays on the same picture until retry', async t => {
    const ui = workspace(t); await ui.open();
    ui.respondWith(async () => false);
    ui.type(ui.node('image-comments'), 'Do not lose this feedback'); await ui.autosave();
    assert.equal(ui.assets.one.comments, '');
    assert.equal(ui.node('save-state').textContent, 'Not saved');
    ui.key('ArrowRight'); await ui.settle();
    assert.equal(ui.node('editor-name').textContent, 'one');
    assert.equal(ui.node('image-comments').value, 'Do not lose this feedback');
    ui.respondWith(async () => {}); ui.key('Enter', ui.node('image-comments')); await ui.settle();
    assert.equal(ui.assets.one.comments, 'Do not lose this feedback');
    assert.equal(ui.node('save-state').textContent, 'Saved');
});

test('autosave preserves annotation text focus and undo still works after it has saved', async t => {
    const ui = workspace(t); await ui.open();
    const field = ui.annotate('First annotation'); await ui.autosave();
    assert.equal(ui.document.activeElement, field);
    assert.equal(ui.node('callout-overlay').hidden, false);
    assert.equal(ui.assets.one.annotations[0].text, 'First annotation');
    field.value = 'Revised annotation'; field.emit('input'); await ui.autosave();
    assert.equal(ui.assets.one.annotations[0].text, 'Revised annotation');
    ui.key('z', field); await ui.autosave();
    assert.equal(ui.assets.one.annotations[0].text, 'First annotation');
    assert.equal(ui.assets.one.revision, 3);
});

test('edits during autosave receive a follow-up save without navigation or another keystroke', async t => {
    const ui = workspace(t); await ui.open();
    let release; ui.respondWith(() => new Promise(resolve => { release = resolve; }));
    const field = ui.annotate('First text'); await ui.autosave();
    assert.equal(ui.assets.one.revision, 0);
    assert.equal(ui.node('callout-overlay').hidden, false);
    field.value = 'Latest exact text'; field.emit('input');
    await ui.autosave();
    assert.equal(ui.writes.length, 1);
    ui.respondWith(async () => {}); release(); await ui.settle();
    assert.equal(ui.node('save-state').textContent, 'Unsaved changes');
    await ui.autosave();
    assert.equal(ui.writes.length, 2);
    assert.equal(ui.writes[1].revision, 1);
    assert.equal(ui.assets.one.annotations[0].text, 'Latest exact text');
    assert.equal(ui.node('save-state').textContent, 'Saved');
});

test('autosave never captures an unfinished callout drag and cancellation restores its boxes', async t => {
    const ui = workspace(t); await ui.open(); ui.annotate('Keep this box');
    const target = ui.node('callout-overlay').children[0];
    target.emit('pointerdown', { button: 0, clientX: 320, clientY: 240, pointerId: 7 });
    ui.node('callout-overlay').emit('pointermove', { clientX: 350, clientY: 270, pointerId: 7 });
    await ui.autosave(); assert.equal(ui.writes.length, 0);
    ui.node('callout-overlay').emit('pointercancel', { pointerId: 7 });
    await ui.autosave();
    assert.equal(ui.writes.length, 1);
    assert.equal(ui.assets.one.annotations[0].points[0].x, 0.4);
    assert.equal(ui.assets.one.annotations[0].points[0].y, 0.47);
    assert.equal(ui.assets.one.annotations[0].text, 'Keep this box');
});

test('deleting a file waits for its background save and cancels pending autosaves', async t => {
    const ui = workspace(t); await ui.open();
    let release; ui.respondWith(() => new Promise(resolve => { release = resolve; }));
    ui.type(ui.node('image-comments'), 'Saving before deletion'); await ui.autosave();
    ui.node('delete-image').click(); await ui.settle();
    assert.deepEqual(ui.deletions, []);
    assert.equal(ui.node('image-comments').disabled, true);
    release(); await ui.settle(); await ui.autosave();
    assert.deepEqual(ui.deletions, ['one']);
    assert.equal(ui.assets.one, undefined);
    assert.equal(ui.writes.length, 1);
    assert.equal(ui.assets.two.revision, 0);
});

test('deleting a file stays in the editor on the next file with one less in the chunk', async t => {
    const ui = workspace(t); await ui.open();
    ui.node('delete-image').click(); await ui.settle(); await ui.settle();
    assert.deepEqual(ui.deletions, ['one']);
    assert.equal(ui.reloads, 0);
    assert.equal(ui.node('editor').hidden, false);
    assert.equal(ui.node('editor-name').textContent, 'two');
    assert.equal(ui.node('chunk-position').textContent, '1 of 2');
    assert.equal(ui.where.search, '?image=two');
});

test('deleting the only file in a chunk returns to the project', async t => {
    const ui = workspace(t); await ui.open();
    for (let remaining = 3; remaining > 0; remaining--) { ui.node('delete-image').click(); await ui.settle(); await ui.settle(); }
    assert.deepEqual(ui.deletions, ['one', 'two', 'three']);
    assert.equal(ui.reloads, 1);
});

test('Select tool picks a mark, Delete removes it through autosave and Undo restores it', async t => {
    const ui = workspace(t); await ui.open(); ui.annotate('Remove me'); await ui.autosave();
    assert.equal(ui.assets.one.annotations.length, 1);
    const canvas = ui.node('annotation-canvas');
    ui.selectTool.click();
    assert.equal(ui.node('delete-mark').disabled, true);
    assert.equal(ui.key('Delete', ui.document, { metaKey: false }).defaultPrevented, false);
    canvas.emit('pointerdown', { button: 0, clientX: 10, clientY: 10, pointerId: 2 });
    assert.equal(ui.node('delete-mark').disabled, true);
    canvas.emit('pointerdown', { button: 0, clientX: 320, clientY: 240, pointerId: 2 });
    assert.equal(ui.node('delete-mark').disabled, false);
    assert.equal(ui.key('Escape', ui.document, { metaKey: false }).defaultPrevented, false);
    assert.equal(ui.node('delete-mark').disabled, true);
    canvas.emit('pointerdown', { button: 0, clientX: 320, clientY: 240, pointerId: 2 });
    assert.equal(ui.key('Delete', ui.document, { metaKey: false }).defaultPrevented, true);
    assert.equal(ui.node('delete-mark').disabled, true);
    await ui.autosave();
    assert.deepEqual(ui.assets.one.annotations, []);
    assert.equal(ui.writes.at(-1).annotated_image, null);
    ui.key('z'); await ui.autosave();
    assert.equal(ui.assets.one.annotations.length, 1);
    assert.equal(ui.assets.one.annotations[0].text, 'Remove me');
    assert.equal(ui.node('delete-mark').disabled, true);
    ui.key('z', ui.document, { shiftKey: true }); await ui.autosave();
    assert.deepEqual(ui.assets.one.annotations, []);
});

test('Delete and Backspace leave marks alone while typing in feedback or a note', async t => {
    const ui = workspace(t); await ui.open(); const field = ui.annotate('Keep me'); await ui.autosave();
    ui.selectTool.click();
    ui.node('annotation-canvas').emit('pointerdown', { button: 0, clientX: 320, clientY: 240, pointerId: 3 });
    for (const target of [ui.node('image-comments'), field]) for (const key of ['Delete', 'Backspace']) {
        assert.equal(ui.key(key, target, { metaKey: false }).defaultPrevented, false);
    }
    await ui.autosave();
    assert.equal(ui.assets.one.annotations.length, 1);
    assert.equal(ui.key('Backspace', ui.document, { metaKey: false }).defaultPrevented, true);
    await ui.autosave();
    assert.deepEqual(ui.assets.one.annotations, []);
});

test('hex field applies a valid colour to the next stroke and the selected note, and syncs', async t => {
    const ui = workspace(t); await ui.open(); const field = ui.annotate('Tint me'); await ui.autosave();
    ui.tool.click();
    ui.node('annotation-canvas').emit('pointerdown', { button: 0, clientX: 320, clientY: 240, pointerId: 3 });
    const hex = ui.node('drawing-color-hex'); hex.value = 'ABC'; hex.emit('change');
    assert.equal(ui.node('drawing-color').value, '#aabbcc');
    await ui.autosave();
    assert.equal(ui.assets.one.annotations[0].color, '#aabbcc');
    hex.value = '12ff00'; hex.emit('keydown', { key: 'Enter' });
    assert.equal(ui.node('drawing-color').value, '#12ff00');
    hex.blur(); assert.equal(hex.value, '#12ff00');
});

test('gradient strip applies the colour under the pointer, syncs the hex field and keeps arrows away from shortcuts', async t => {
    const ui = workspace(t); await ui.open();
    const strip = ui.node('color-gradient'); strip.emit('pointerdown', { button: 0, clientX: 320, clientY: 120, pointerId: 5 });
    assert.equal(ui.node('drawing-color').value, '#80ffff'); assert.equal(ui.node('drawing-color-hex').value, '#80ffff');
    strip.emit('pointermove', { clientX: 0, clientY: 240, pointerId: 5 });
    assert.equal(ui.node('drawing-color').value, '#ff0000');
    strip.emit('pointerup', { pointerId: 5 }); strip.emit('pointermove', { clientX: 320, clientY: 240, pointerId: 5 });
    assert.equal(ui.node('drawing-color').value, '#ff0000');
    for (const extra of [{}, { metaKey: false }]) assert.equal(ui.key('ArrowRight', strip, extra).defaultPrevented, true);
    assert.notEqual(ui.node('drawing-color').value, '#ff0000');
});

test('invalid hex keeps the previous colour and blur restores the field', async t => {
    const ui = workspace(t); await ui.open();
    const hex = ui.node('drawing-color-hex'); hex.focus();
    for (const bad of ['#12', 'ggg', '#1234567', '']) { hex.value = bad; hex.emit('change'); hex.emit('keydown', { key: 'Enter' }); assert.equal(ui.node('drawing-color').value, '#ef4444'); }
    hex.blur(); assert.equal(hex.value, '#ef4444');
});

test('Backspace in the hex field does not delete a selected mark', async t => {
    const ui = workspace(t); await ui.open(); ui.annotate('Keep me'); await ui.autosave();
    ui.selectTool.click();
    ui.node('annotation-canvas').emit('pointerdown', { button: 0, clientX: 320, clientY: 240, pointerId: 3 });
    const hex = ui.node('drawing-color-hex');
    for (const key of ['Delete', 'Backspace']) assert.equal(ui.key(key, hex, { metaKey: false }).defaultPrevented, false);
    await ui.autosave();
    assert.equal(ui.assets.one.annotations.length, 1);
});

test('dragging the note frame moves only the note and autosaves the new position', async t => {
    const ui = workspace(t); await ui.open(); ui.annotate('Move me');
    await ui.autosave();
    const before = structuredClone(ui.assets.one.annotations[0].points);
    const note = ui.node('callout-overlay').children[1];
    note.emit('pointerdown', { button: 0, clientX: 320, clientY: 240, pointerId: 9 });
    ui.node('callout-overlay').emit('pointermove', { clientX: 360, clientY: 280, pointerId: 9 });
    ui.node('callout-overlay').emit('pointerup', { clientX: 360, clientY: 280, pointerId: 9 });
    await ui.autosave();
    const after = ui.assets.one.annotations[0].points;
    assert.deepEqual(after.slice(0, 2), before.slice(0, 2));
    assert.ok(after[2].x > before[2].x && after[2].y > before[2].y);
    assert.equal(ui.assets.one.annotations[0].text, 'Move me');
});

test('dragging across the gradient recolours a selected note in one undo step', async t => {
    const ui = workspace(t); await ui.open(); ui.annotate('Recolour me'); await ui.autosave();
    ui.tool.click();
    ui.node('annotation-canvas').emit('pointerdown', { button: 0, clientX: 320, clientY: 240, pointerId: 3 });
    const original = ui.assets.one.annotations[0].color;
    const strip = ui.node('color-gradient');
    strip.emit('pointerdown', { button: 0, clientX: 320, clientY: 120, pointerId: 5 });
    for (const x of [40, 120, 200, 0]) strip.emit('pointermove', { clientX: x, clientY: 240, pointerId: 5 });
    strip.emit('pointerup', { pointerId: 5 });
    await ui.autosave();
    assert.equal(ui.assets.one.annotations[0].color, '#ff0000');
    ui.node('undo-drawing').click(); await ui.autosave();
    assert.equal(ui.assets.one.annotations[0].color, original);
});

const arrivalNotice = 'New upload arrived — it will appear when you go back to the project.';

test('polling an unchanged project fetches nothing and shows no notice', async t => {
    const ui = workspace(t);
    await ui.poll(); await ui.poll();
    assert.equal(ui.remote.chunkRequests, 2);
    assert.equal(ui.remote.pageRequests, 0);
    assert.equal(ui.node('workspace-message').hidden, true);
});

test('a new upload refreshes the visible gallery in place, rebinds the new tiles and shows a notice', async t => {
    const ui = workspace(t);
    const fresh = ui.arrive('chunk-two', 1);
    await ui.poll();
    assert.equal(ui.remote.pageRequests, 1);
    assert.deepEqual(ui.node('chunk-list').children, [fresh]);
    assert.deepEqual(ui.node('workspace').dataset, { latest: 'chunk-two', latestCompleted: 't2', latestCount: '1' });
    assert.equal(ui.node('workspace-message').textContent, 'New upload arrived.');
    assert.equal(ui.node('workspace-message').hidden, false);
    assert.equal(ui.reloads, 0);
    await ui.poll();
    assert.equal(ui.remote.pageRequests, 1);
    fresh.click(); await ui.settle();
    assert.equal(ui.node('editor-name').textContent, 'two');
    ui.timers.forEach(callback => callback());
});

test('the arrival notice hides itself after a while but not when another message replaced it', async t => {
    const ui = workspace(t); ui.arrive();
    await ui.poll();
    ui.timers.forEach(callback => callback()); ui.timers.clear();
    assert.equal(ui.node('workspace-message').hidden, true);
    ui.arrive('chunk-three', 2); await ui.poll();
    ui.node('workspace-message').textContent = 'Different message';
    ui.timers.forEach(callback => callback());
    assert.equal(ui.node('workspace-message').hidden, false);
});

test('a changed file count or completion time in the same chunk also refreshes the gallery', async t => {
    const ui = workspace(t);
    ui.remote.chunk = { id: 'chunk-one', completed_at: 't1', file_count: 4 };
    ui.remote.page = { cards: [], dataset: { latest: 'chunk-one', latestCompleted: 't1', latestCount: '4' } };
    await ui.poll();
    assert.equal(ui.remote.pageRequests, 1);
    assert.equal(ui.node('workspace').dataset.latestCount, '4');
    ui.remote.chunk = { id: 'chunk-one', completed_at: 't9', file_count: 4 };
    ui.remote.page = { cards: [], dataset: { latest: 'chunk-one', latestCompleted: 't9', latestCount: '4' } };
    await ui.poll();
    assert.equal(ui.remote.pageRequests, 2);
    assert.equal(ui.node('workspace').dataset.latestCompleted, 't9');
});

test('an open editor keeps the gallery untouched, shows one notice and refreshes when it closes', async t => {
    const ui = workspace(t); await ui.open();
    const fresh = ui.arrive();
    ui.type(ui.node('image-comments'), 'Unsaved thoughts');
    await ui.poll(); await ui.poll();
    assert.equal(ui.remote.pageRequests, 0);
    assert.notDeepEqual(ui.node('chunk-list').children, [fresh]);
    assert.equal(ui.node('workspace').dataset.latest, 'chunk-one');
    assert.equal(ui.node('workspace-message').textContent, arrivalNotice);
    assert.equal(ui.node('image-comments').value, 'Unsaved thoughts');
    assert.equal(ui.node('editor-name').textContent, 'one');
    await ui.autosave();
    ui.node('close-editor').click(); await ui.settle();
    assert.equal(ui.remote.pageRequests, 1);
    assert.deepEqual(ui.node('chunk-list').children, [fresh]);
    assert.equal(ui.node('workspace').dataset.latest, 'chunk-two');
    assert.equal(ui.node('workspace-message').textContent, 'New upload arrived.');
    assert.equal(ui.reloads, 0);
});

test('closing the editor refreshes the gallery without a reload, and reloads only when the refresh fails', async t => {
    const ui = workspace(t); await ui.open();
    ui.remote.page = { cards: [], dataset: { latest: 'chunk-one', latestCompleted: 't1', latestCount: '3' } };
    ui.node('close-editor').click(); await ui.settle();
    assert.equal(ui.remote.pageRequests, 1);
    assert.equal(ui.reloads, 0);
    assert.equal(ui.node('workspace-message').hidden, true);
    await ui.open();
    ui.remote.pageResponse = async () => ({ ok: false });
    ui.node('close-editor').click(); await ui.settle();
    assert.equal(ui.reloads, 1);
});

test('an open project dialog blocks the swap until the next poll after it clears', async t => {
    const ui = workspace(t); ui.arrive();
    ui.node('project-dialog').open = true;
    await ui.poll();
    assert.equal(ui.remote.pageRequests, 0);
    assert.equal(ui.node('workspace-message').textContent, arrivalNotice);
    ui.node('project-dialog').open = false;
    await ui.poll();
    assert.equal(ui.remote.pageRequests, 1);
    assert.equal(ui.node('workspace').dataset.latest, 'chunk-two');
    assert.equal(ui.node('workspace-message').textContent, 'New upload arrived.');
});

test('failed polls and failed page fetches stay silent and the next tick retries', async t => {
    const ui = workspace(t); ui.arrive();
    ui.remote.chunkResponse = async () => { throw new Error('offline'); };
    await ui.poll();
    ui.remote.chunkResponse = async () => ({ ok: false, json: async () => ({ message: 'Server error' }) });
    await ui.poll();
    ui.remote.chunkResponse = async () => ({ ok: true, json: async () => { throw new Error('not json'); } });
    await ui.poll();
    assert.equal(ui.remote.pageRequests, 0);
    ui.remote.chunkResponse = null;
    ui.remote.pageResponse = async () => ({ ok: false });
    await ui.poll();
    ui.remote.pageResponse = async () => { throw new Error('offline'); };
    await ui.poll();
    assert.equal(ui.remote.pageRequests, 2);
    assert.equal(ui.node('workspace-message').hidden, true);
    assert.equal(ui.node('workspace').dataset.latest, 'chunk-one');
    assert.equal(ui.reloads, 0);
    ui.remote.pageResponse = null;
    await ui.poll();
    assert.equal(ui.node('workspace').dataset.latest, 'chunk-two');
});

test('a page without the upload list (for example a login redirect) is never swapped in', async t => {
    const ui = workspace(t); ui.arrive();
    ui.remote.page.noList = true;
    ui.node('chunk-list').children = ['kept'];
    await ui.poll();
    assert.equal(ui.remote.pageRequests, 1);
    assert.deepEqual(ui.node('chunk-list').children, ['kept']);
    assert.equal(ui.node('workspace').dataset.latest, 'chunk-one');
    assert.equal(ui.node('workspace-message').hidden, true);
    assert.equal(ui.reloads, 0);
});

test('polling pauses while the tab is hidden and polls immediately when it becomes visible again', async t => {
    const ui = workspace(t);
    ui.document.visibilityState = 'hidden';
    await ui.poll();
    ui.document.emit('visibilitychange'); await ui.settle();
    assert.equal(ui.remote.chunkRequests, 0);
    ui.document.visibilityState = 'visible';
    ui.document.emit('visibilitychange'); await ui.settle();
    assert.equal(ui.remote.chunkRequests, 1);
});

test('overlapping polls never run together and a response that predates a swap is discarded', async t => {
    const ui = workspace(t); await ui.open();
    ui.arrive();
    let release;
    ui.remote.chunkResponse = () => new Promise(resolve => { release = () => resolve({ ok: true, json: async () => ({ chunk: { id: 'chunk-one', completed_at: 't1', file_count: 3 } }) }); });
    ui.document.emit('visibilitychange');
    await ui.poll(); await ui.poll();
    assert.equal(ui.remote.chunkRequests, 1);
    ui.node('close-editor').click(); await ui.settle();
    assert.equal(ui.remote.pageRequests, 1);
    assert.equal(ui.node('workspace').dataset.latest, 'chunk-two');
    release(); await ui.settle();
    assert.equal(ui.remote.pageRequests, 1);
    assert.equal(ui.node('workspace').dataset.latest, 'chunk-two');
    assert.equal(ui.reloads, 0);
});

test('Duplicate saves pending feedback, then opens the clean copy as the last file', async t => {
    const ui = workspace(t); await ui.open();
    ui.type(ui.node('image-comments'), 'Before duplicating.');
    ui.node('duplicate-image').click(); await ui.settle(); await ui.settle();
    assert.equal(ui.assets.one.comments, 'Before duplicating.');
    assert.deepEqual(ui.duplicates, [{ id: 'one', saved: 1 }]);
    assert.equal(ui.node('editor-name').textContent, 'copy');
    assert.equal(ui.node('chunk-position').textContent, '4 of 4');
    assert.equal(ui.node('image-comments').value, '');
    assert.equal(ui.node('workspace-message').textContent, "Duplicated — you're editing the copy.");
    assert.equal(ui.node('duplicate-image').disabled, false);
});

test('opening a file puts it in the address bar, next-file replaces it and closing removes it', async t => {
    const ui = workspace(t); await ui.open();
    assert.deepEqual(ui.urlCalls, [['push', '/projects/1?image=one']]);
    ui.node('next-file').click(); await ui.settle();
    assert.deepEqual(ui.urlCalls.at(-1), ['replace', '/projects/1?image=two']);
    assert.equal(ui.urlCalls.filter(([mode]) => mode === 'push').length, 1);
    ui.node('close-editor').click(); await ui.settle();
    assert.deepEqual(ui.urlCalls.at(-1), ['replace', '/projects/1']);
    assert.equal(ui.where.search, '');
});

test('loading with an image from the middle of a group opens it with the right position', async t => {
    const ui = workspace(t, '?image=two&x=1'); await ui.settle(); await ui.settle();
    assert.equal(ui.node('editor-name').textContent, 'two');
    assert.equal(ui.node('chunk-position').textContent, '2 of 3');
    assert.equal(ui.node('editor').hidden, false);
    assert.equal(ui.urlCalls.some(([mode]) => mode === 'push'), false);
    assert.equal(ui.where.search, '?image=two&x=1');
});

test('loading with an unknown image shows the gallery and drops the parameter', async t => {
    const ui = workspace(t, '?image=gone&x=1'); await ui.settle();
    assert.equal(ui.node('editor-name').textContent, '');
    assert.equal(ui.where.search, '?x=1');
    assert.deepEqual(ui.urlCalls, [['replace', '/projects/1?x=1']]);
});

test('Back (popstate without an image) saves pending feedback, then closes the editor', async t => {
    const ui = workspace(t); await ui.open();
    ui.type(ui.node('image-comments'), 'Keep this.');
    await ui.pop('');
    assert.equal(ui.writes.length, 1);
    assert.equal(ui.assets.one.comments, 'Keep this.');
    assert.equal(ui.node('editor').hidden, true);
    assert.equal(ui.node('gallery').hidden, false);
});

test('Back keeps the editor and restores the address when saving fails', async t => {
    const ui = workspace(t); await ui.open();
    ui.respondWith(async () => false);
    ui.type(ui.node('image-comments'), 'Keep this.');
    await ui.pop('');
    assert.equal(ui.node('editor').hidden, false);
    assert.equal(ui.where.search, '?image=one');
});

test('opening a file focuses its title, not the Back button, so Space cannot leave the editor', async t => {
    const ui = workspace(t); await ui.open();
    assert.equal(ui.document.activeElement, ui.node('editor-name'));
    assert.notEqual(ui.document.activeElement, ui.node('close-editor'));
    ui.key(' ', ui.document.activeElement, { metaKey: false });
    assert.equal(ui.node('editor').hidden, false);
});

function viewport(ui) {
    const stage = ui.node('canvas-stage'); const canvas = ui.node('annotation-canvas');
    stage.scrollLeft = 0; stage.scrollTop = 0;
    canvas.getBoundingClientRect = () => { const width = parseFloat(canvas.style.width); return { left: 100 - stage.scrollLeft, top: 50 - stage.scrollTop, width, height: width * 0.75 }; };
    const rect = () => canvas.getBoundingClientRect();
    const percent = () => parseInt(ui.node('canvas-zoom').textContent, 10);
    const wheel = (properties = {}) => stage.emit('wheel', { deltaY: -100, deltaMode: 0, ctrlKey: false, clientX: 420, clientY: 290, ...properties });
    const draw = (from, to, pointerId = 1) => { canvas.emit('pointerdown', { button: 0, clientX: from[0], clientY: from[1], pointerId }); canvas.emit('pointermove', { clientX: to[0], clientY: to[1], pointerId }); canvas.emit('pointerup', { clientX: to[0], clientY: to[1], pointerId }); };
    return { stage, canvas, rect, percent, wheel, draw };
}

test('mouse wheel and pinch zoom keep the point under the pointer, and marks keep their image coordinates', async t => {
    const ui = workspace(t); await ui.open(); const view = viewport(ui);
    assert.equal(view.percent(), 100);
    view.draw([420, 290], [260, 170]);
    const wheeled = view.wheel();
    assert.equal(wheeled.defaultPrevented, true);
    assert.ok(view.percent() > 100);
    const rect = view.rect();
    assert.ok(Math.abs(rect.left + 0.5 * rect.width - 420) < 0.01 && Math.abs(rect.top + 0.5 * rect.height - 290) < 0.01);
    view.draw([420, 290], [rect.left + 0.1 * rect.width, rect.top + 0.2 * rect.height], 2);
    await ui.autosave();
    const [first, second] = ui.assets.one.annotations;
    assert.deepEqual(first.points[0], { x: 0.5, y: 0.5 }); assert.deepEqual(first.points.at(-1), { x: 0.25, y: 0.25 });
    assert.ok(Math.abs(second.points[0].x - 0.5) < 1e-9 && Math.abs(second.points.at(-1).x - 0.1) < 1e-9 && Math.abs(second.points.at(-1).y - 0.2) < 1e-9);
    assert.equal(second.width, first.width);
    const before = view.percent();
    const pinch = view.wheel({ ctrlKey: true, deltaY: 10 });
    assert.equal(pinch.defaultPrevented, true); assert.ok(view.percent() < before);
    for (let i = 0; i < 80; i++) view.wheel();
    assert.equal(view.percent(), 400); assert.ok(view.stage.scrollLeft >= 0 && view.stage.scrollTop >= 0);
    for (let i = 0; i < 200; i++) view.wheel({ deltaY: 100 });
    assert.equal(view.percent(), 10);
    assert.equal(ui.node('zoom-out').disabled, true);
});

test('wheel is ignored while a stroke is being drawn and when no image is loaded', async t => {
    const ui = workspace(t); await ui.open(); const view = viewport(ui);
    view.canvas.emit('pointerdown', { button: 0, clientX: 300, clientY: 200, pointerId: 1 });
    const event = view.wheel();
    assert.equal(view.percent(), 100); assert.equal(event.defaultPrevented, false);
    view.canvas.emit('pointerup', { clientX: 300, clientY: 200, pointerId: 1 });
    view.wheel(); assert.ok(view.percent() > 100);
});

test('holding Space pans the zoomed image instead of drawing, and Space still types in text fields', async t => {
    const ui = workspace(t); await ui.open(); const view = viewport(ui);
    view.wheel(); const zoomed = view.percent();
    const hint = ui.node('canvas-tool-hint'); hint.textContent = 'Pen: draw freely on the image.';
    const space = ui.key(' ', ui.document.activeElement, { metaKey: false });
    assert.equal(ui.document.activeElement, ui.node('editor-name'));
    assert.equal(space.defaultPrevented, true); assert.equal(ui.node('editor').hidden, false);
    assert.equal(hint.textContent, 'Hold Space and drag to move · scroll to zoom');
    const stage = view.stage; const left = stage.scrollLeft; const top = stage.scrollTop;
    stage.emit('pointerdown', { button: 0, clientX: 300, clientY: 300, pointerId: 4 });
    view.canvas.emit('pointerdown', { button: 0, clientX: 300, clientY: 300, pointerId: 4 });
    stage.emit('pointermove', { clientX: 250, clientY: 280, pointerId: 4 });
    assert.equal(stage.scrollLeft, left + 50); assert.equal(stage.scrollTop, top + 20);
    stage.emit('pointerup', { clientX: 250, clientY: 280, pointerId: 4 });
    stage.emit('pointermove', { clientX: 0, clientY: 0, pointerId: 4 });
    assert.equal(stage.scrollLeft, left + 50);
    await ui.autosave(); assert.equal(ui.writes.length, 0); assert.equal(view.percent(), zoomed);
    ui.document.emit('keyup', { key: ' ' });
    assert.equal(hint.textContent, 'Pen: draw freely on the image.');
    view.draw([300, 300], [200, 200], 5); await ui.autosave();
    assert.equal(ui.assets.one.annotations.length, 1);
    const feedback = ui.node('image-comments'); feedback.focus();
    assert.equal(ui.key(' ', feedback, { metaKey: false }).defaultPrevented, false);
    assert.equal(hint.textContent, 'Pen: draw freely on the image.');
});

test('append helpers: preference defaults ON, remembers OFF, survives broken storage', () => {
    assert.equal(readAppendPreference(memoryStorage()), true);
    assert.equal(readAppendPreference(memoryStorage({ 'uploadiny.appendToLast': '0' })), false);
    assert.equal(readAppendPreference(memoryStorage({ 'uploadiny.appendToLast': '1' })), true);
    assert.equal(readAppendPreference({ getItem() { throw new Error('blocked'); } }), true);
    const store = memoryStorage();
    writeAppendPreference(store, false); assert.equal(store.data['uploadiny.appendToLast'], '0');
    writeAppendPreference(store, true); assert.equal(store.data['uploadiny.appendToLast'], '1');
    assert.doesNotThrow(() => writeAppendPreference({ setItem() { throw new Error('blocked'); } }, false));
    assert.equal(appendTarget(true, 'chunk-one'), 'chunk-one');
    assert.equal(appendTarget(false, 'chunk-one'), null);
    assert.equal(appendTarget(true, ''), null);
});

test('append caption reads Today, Yesterday or a short date with 24h time', () => {
    const now = new Date(2026, 9, 7, 12, 0);
    assert.equal(formatLastUpload(new Date(2026, 9, 7, 23, 41).toISOString(), 3, now, 'en-GB'), 'Last upload: Today 23:41 · 3 files');
    assert.equal(formatLastUpload(new Date(2026, 9, 6, 9, 10).toISOString(), 1, now, 'en-GB'), 'Last upload: Yesterday 09:10 · 1 file');
    assert.equal(formatLastUpload(new Date(2026, 9, 3, 18, 5).toISOString(), 2, now, 'en-GB'), 'Last upload: 3 Oct 18:05 · 2 files');
    assert.equal(formatLastUpload('', 4, now, 'en-GB'), 'Last upload: 4 files');
});

test('upload cards show the latest upload time in the viewer timezone', () => {
    const local = new Date(2026, 9, 7, 22, 50);
    assert.equal(formatChunkTime(local.toISOString()), '07 Oct 2026, 22:50');
    assert.equal(formatChunkTime(''), null);
    assert.equal(formatChunkTime('not a date'), null);
    const valid = { dateTime: local.toISOString(), textContent: '07 Oct 2026, 20:50' };
    const invalid = { dateTime: 'broken', textContent: 'server text' };
    localizeChunkTimes({ querySelectorAll: selector => selector === 'time[data-local-time]' ? [valid, invalid] : [] });
    assert.equal(valid.textContent, '07 Oct 2026, 22:50');
    assert.equal(invalid.textContent, 'server text');
});

test('upload asks to join the last upload when the switch is ON, pinned at start', async t => {
    const ui = workspace(t);
    assert.equal(ui.node('append-to-last').checked, true);
    await ui.uploadFiles();
    assert.deepEqual(ui.starts, [{ image_count: 1, append_to: 'chunk-one' }]);
});

test('upload stays a new upload when the switch is OFF, and the choice is remembered', async t => {
    const ui = workspace(t);
    ui.node('append-to-last').checked = false; ui.node('append-to-last').emit('change');
    assert.equal(ui.storage.data['uploadiny.appendToLast'], '0');
    await ui.uploadFiles();
    assert.deepEqual(ui.starts, [{ image_count: 1 }]);
    const later = workspace(t, '', memoryStorage({ 'uploadiny.appendToLast': '0' }));
    assert.equal(later.node('append-to-last').checked, false);
});

test('upload has no append_to when no upload exists, and the row hides and follows refreshes', async t => {
    const ui = workspace(t);
    assert.equal(ui.node('append-row').hidden, false);
    assert.equal(ui.node('append-target').textContent, 'Last upload: 3 files');
    ui.arrive('chunk-two', 1);
    await ui.poll();
    assert.equal(ui.node('append-target').textContent, 'Last upload: 1 file');
    ui.remote.page = { cards: [], dataset: { latest: '', latestCompleted: '', latestCount: '' } };
    ui.remote.chunk = null;
    await ui.poll();
    assert.equal(ui.node('append-row').hidden, true);
    await ui.uploadFiles();
    assert.deepEqual(ui.starts, [{ image_count: 1 }]);
});

test('a browser that blocks storage still uploads and defaults the switch on', async t => {
    const blocked = { getItem() { throw new Error('SecurityError'); }, setItem() { throw new Error('SecurityError'); } };
    const ui = workspace(t, '', blocked);
    assert.equal(ui.node('append-to-last').checked, true);
    ui.node('append-to-last').checked = false; ui.node('append-to-last').emit('change');
    await ui.open();
    assert.equal(ui.node('editor').hidden, false);
});
