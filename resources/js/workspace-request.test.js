import assert from 'node:assert/strict';
import test from 'node:test';
import { requestJson } from './workspace-request.js';

function untilAbort(signal) {
    return new Promise((resolve, reject) => {
        if (signal.aborted) reject(new DOMException('Aborted', 'AbortError'));
        else signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')), { once: true });
    });
}

test('caller cancellation remains effective with an independent request deadline', async t => {
    const cancel = new AbortController();
    let observed;
    t.mock.method(globalThis, 'fetch', (_url, settings) => { observed = settings.signal; return untilAbort(observed); });
    const pending = requestJson('/start', { signal: cancel.signal });
    assert.notEqual(observed, cancel.signal);
    cancel.abort();
    await assert.rejects(pending, /cancelled/);
    assert.equal(observed.aborted, true);
});

test('a request deadline still fires when a caller signal is supplied', async t => {
    t.mock.method(globalThis, 'fetch', (_url, settings) => untilAbort(settings.signal));
    await assert.rejects(requestJson('/complete', { signal: new AbortController().signal, timeoutMs: 5 }), /timed out/);
});

test('the deadline covers a stalled response body after headers arrive', async t => {
    t.mock.method(globalThis, 'fetch', async (_url, settings) => ({ ok: true, json: () => untilAbort(settings.signal) }));
    await assert.rejects(requestJson('/complete', { timeoutMs: 5 }), /timed out/);
});

test('an already cancelled request cannot proceed', async t => {
    const cancel = new AbortController(); cancel.abort();
    t.mock.method(globalThis, 'fetch', (_url, settings) => untilAbort(settings.signal));
    await assert.rejects(requestJson('/start', { signal: cancel.signal }), /cancelled/);
});

test('successful responses preserve headers and server failures retain their status', async t => {
    t.mock.method(globalThis, 'fetch', async (_url, settings) => {
        assert.equal(settings.headers['X-CSRF-TOKEN'], 'fixture');
        assert.equal(settings.headers.Accept, 'application/json');
        assert.equal(settings.timeoutMs, undefined);
        return { ok: false, status: 409, json: async () => ({ message: 'Updated feedback requires review.' }) };
    });
    await assert.rejects(requestJson('/delete', { timeoutMs: 100 }, 'fixture'), error => error.status === 409 && error.message === 'Updated feedback requires review.');
});
