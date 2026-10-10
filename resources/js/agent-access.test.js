import assert from 'node:assert/strict';
import test from 'node:test';
import { enhanceAgentAccess } from './agent-access.js';

function controls(confirmation = {}) {
    const field = { id: 'agent-api-key', value: 'sample-api-key-not-a-credential', type: 'password', focus() { this.focused = true; }, select() { this.selected = true; } };
    const status = { textContent: '' };
    const reveal = {
        attributes: { 'aria-controls': field.id, 'aria-pressed': 'false' },
        textContent: 'Show key',
        getAttribute(name) { return this.attributes[name]; },
        setAttribute(name, value) { this.attributes[name] = value; },
        addEventListener(type, callback) { this[type] = callback; },
    };
    const copy = { dataset: { copyField: field.id }, addEventListener(type, callback) { this[type] = callback; } };
    const submit = { disabled: false, textContent: 'Rotate API key' };
    const form = {
        dataset: { confirmAction: 'Rotate the API key?', ...confirmation },
        querySelector() { return submit; },
        addEventListener(type, callback) { this[type] = callback; },
    };
    const root = {
        getElementById(id) { return id === field.id ? field : null; },
        querySelector(selector) { return selector === '[data-reveal-key]' ? reveal : status; },
        querySelectorAll(selector) { return selector === '[data-copy-field]' ? [copy] : [form]; },
    };
    enhanceAgentAccess(root);
    return { field, status, reveal, copy, form, submit };
}

function clipboard(t, writeText) {
    const previous = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { clipboard: { writeText } } });
    t.after(() => {
        if (previous) Object.defineProperty(globalThis, 'navigator', previous);
        else delete globalThis.navigator;
    });
}

test('copy sends the exact key to the clipboard while keeping it masked', async t => {
    let copied = null;
    clipboard(t, async value => { copied = value; });
    const { field, copy, status } = controls();
    assert.equal(field.type, 'password');
    assert.equal(status.textContent, '');

    await copy.click();

    assert.equal(copied, 'sample-api-key-not-a-credential');
    assert.equal(field.type, 'password');
    assert.equal(status.textContent, 'Copied.');
});

test('clipboard failure selects the key for manual copy and keeps the visibility control accurate', async t => {
    clipboard(t, async () => { throw new Error('Clipboard unavailable'); });
    const { field, copy, status, reveal } = controls();
    assert.equal(field.type, 'password');

    await copy.click();

    assert.equal(field.type, 'text');
    assert.equal(field.focused, true);
    assert.equal(field.selected, true);
    assert.equal(reveal.textContent, 'Hide key');
    assert.equal(reveal.getAttribute('aria-pressed'), 'true');
    assert.equal(status.textContent, 'Copy failed. The value is selected; copy it manually.');
    reveal.click({ currentTarget: reveal });
    assert.equal(field.type, 'password');
    assert.equal(reveal.textContent, 'Show key');
    assert.equal(reveal.getAttribute('aria-pressed'), 'false');
});

test('cancelled rotation leaves the form usable and confirmed rotation blocks repeated submission', t => {
    const previous = globalThis.confirm;
    t.after(() => { if (previous) globalThis.confirm = previous; else delete globalThis.confirm; });
    const { form, submit } = controls();
    let prevented = false;
    globalThis.confirm = () => false;

    form.submit({ preventDefault() { prevented = true; } });

    assert.equal(prevented, true);
    assert.equal(submit.disabled, false);
    assert.equal(submit.textContent, 'Rotate API key');
    globalThis.confirm = () => true;
    prevented = false;
    form.submit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, false);
    assert.equal(submit.disabled, true);
    assert.equal(submit.textContent, 'Saving…');
});

test('cancelled chunk deletion keeps the form usable and confirmation shows the deletion label', t => {
    const previous = globalThis.confirm;
    t.after(() => { if (previous) globalThis.confirm = previous; else delete globalThis.confirm; });
    const warning = 'Permanently delete all chunks in "Feedback project", including drafts, files, comments, and annotations? The project will be kept.';
    const { form, submit } = controls({ confirmAction: warning, confirmLabel: 'Deleting…' });
    submit.textContent = 'Delete all Chunks';
    let accepted = false;
    const messages = [];
    globalThis.confirm = message => { messages.push(message); return accepted; };
    const cancelled = new Event('submit', { cancelable: true });

    form.submit(cancelled);

    assert.equal(cancelled.defaultPrevented, true);
    assert.equal(submit.disabled, false);
    assert.equal(submit.textContent, 'Delete all Chunks');
    accepted = true;
    const confirmed = new Event('submit', { cancelable: true });

    form.submit(confirmed);

    assert.deepEqual(messages, [warning, warning]);
    assert.equal(confirmed.defaultPrevented, false);
    assert.equal(submit.disabled, true);
    assert.equal(submit.textContent, 'Deleting…');
});
