import assert from 'node:assert/strict';
import test from 'node:test';
import { chunkStartFile, LAST_VIEWED_KEY, rememberChunkFile } from './chunk-memory.js';

function memoryStore(initial = {}) {
    return { data: { ...initial }, getItem(key) { return this.data[key] ?? null; }, setItem(key, value) { this.data[key] = value; } };
}

test('a chunk opens on its first file until another file was viewed, then on that file', () => {
    const store = memoryStore();
    assert.equal(chunkStartFile(store, 'chunk-a', ['first', 'second', 'third']), 'first');
    rememberChunkFile(store, 'chunk-a', 'third');
    assert.equal(chunkStartFile(store, 'chunk-a', ['first', 'second', 'third']), 'third');
    assert.equal(chunkStartFile(store, 'chunk-b', ['other-first', 'other-second']), 'other-first');
});

test('a remembered file that left the chunk falls back to the first file', () => {
    const store = memoryStore();
    rememberChunkFile(store, 'chunk-a', 'deleted');
    assert.equal(chunkStartFile(store, 'chunk-a', ['first', 'second']), 'first');
    assert.equal(chunkStartFile(store, 'chunk-a', []), undefined);
});

test('broken or blocked storage never stops a chunk from opening', () => {
    assert.equal(chunkStartFile(memoryStore({ [LAST_VIEWED_KEY]: 'not json' }), 'chunk-a', ['first']), 'first');
    assert.equal(chunkStartFile(memoryStore({ [LAST_VIEWED_KEY]: '[1]' }), 'chunk-a', ['first']), 'first');
    assert.equal(chunkStartFile(null, 'chunk-a', ['first']), 'first');
    assert.doesNotThrow(() => rememberChunkFile({ getItem: () => null, setItem() { throw new Error('blocked'); } }, 'chunk-a', 'first'));
});

test('only the 500 most recently viewed chunks are remembered', () => {
    const store = memoryStore();
    for (let index = 0; index < 501; index++) rememberChunkFile(store, `chunk-${index}`, `file-${index}`);
    rememberChunkFile(store, 'chunk-1', 'file-1b');
    const map = JSON.parse(store.data[LAST_VIEWED_KEY]);
    assert.equal(Object.keys(map).length, 500);
    assert.equal(map['chunk-0'], undefined);
    assert.equal(map['chunk-1'], 'file-1b');
    assert.equal(map['chunk-500'], 'file-500');
});
