import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import * as drawing from './drawing.js';
import { createCalloutEditor } from './callout-editor.js';
import { drawingShortcutAction } from './drawing-shortcuts.js';

const app = readFileSync(new URL('./app.js', import.meta.url), 'utf8').replace(/^import[\s\S]*?;\n/gm, '');
const tick = () => new Promise(resolve => setImmediate(resolve));

function workspace(t) {
    const previousDocument = globalThis.document;
    const nodes = new Map(); const timers = new Map(); let timerId = 0;
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
    document.querySelector = selector => selector === '[data-workspace]' ? node('workspace') : new Element();
    document.querySelectorAll = selector => selector === '[data-chunk-images]' || selector === '[data-open-image]' ? [card] : selector === '[data-tool]' ? [selectTool, tool] : [];
    node('workspace-config').textContent = JSON.stringify({ project: { id: 1 }, latest_url: null });
    node('drawing-color').value = '#ef4444'; node('drawing-color-hex').value = '#ef4444'; node('drawing-width').value = '6';
    const assets = Object.fromEntries(['one', 'two', 'three'].map(id => [id, { id, name: id, comments: '', annotations: [], revision: 0, media_type: 'image', description_status: 'ready', preview_url: id }]));
    const writes = []; const deletions = []; let respond = async () => {};
    globalThis.document = document;
    t.after(() => { globalThis.document = previousDocument; });
    runInNewContext(app, {
        ...drawing, createCalloutEditor, drawingShortcutAction, document,
        enhanceAgentAccess() {}, enhanceProjectSelect() {}, enhanceRecordingPreviews() {}, setRecordingPoster() {},
        window: { innerWidth: 1200, addEventListener() {} },
        ResizeObserver: class { observe() {} }, Event: class { constructor(type) { this.type = type; } },
        Image: class { naturalWidth = 640; naturalHeight = 480; set src(value) { this.onload(); } },
        Option: class {}, FormData: class {}, structuredClone, clearTimeout: id => timers.delete(id),
        setTimeout: callback => { timers.set(++timerId, callback); return timerId; }, setInterval() {},
        location: { reload() {} }, confirm: () => true,
        fetch: async (url, options = {}) => {
            if (url === '/chunks') return { ok: true, json: async () => ({ destinations: [] }) };
            const id = url.split('/')[2];
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
        node, assets, writes, deletions, document, tool, selectTool,
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
