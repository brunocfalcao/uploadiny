import test from 'node:test';
import assert from 'node:assert/strict';
import { clipboardFiles } from './clipboard-files.js';

const at = new Date('2026-10-07T00:12:03Z');
const png = (name = 'image.png') => new File([new Uint8Array([1, 2, 3])], name, { type: 'image/png' });

test('reads files directly from the clipboard and gives generic screenshots a dated name', () => {
    const [file] = clipboardFiles({ files: [png()] }, at);
    assert.equal(file.name, 'pasted-2026-10-07-00-12-03.png');
    assert.equal(file.type, 'image/png');
    assert.equal(file.size, 3);
});

test('falls back to clipboard items when Safari leaves the file list empty', () => {
    const items = [{ kind: 'string', getAsFile: () => null }, { kind: 'file', getAsFile: () => png('') }, { kind: 'file', getAsFile: () => new File(['x'], 'image.jpeg', { type: 'image/jpeg' }) }];
    const files = clipboardFiles({ files: [], items }, at);
    assert.deepEqual(files.map(file => file.name), ['pasted-2026-10-07-00-12-03-1.png', 'pasted-2026-10-07-00-12-03-2.jpg']);
});

test('keeps real file names and returns nothing for text-only clipboards', () => {
    assert.equal(clipboardFiles({ files: [png('Login screen.png')] }, at)[0].name, 'Login screen.png');
    assert.deepEqual(clipboardFiles({ files: [], items: [{ kind: 'string', getAsFile: () => null }] }, at), []);
    assert.deepEqual(clipboardFiles(null, at), []);
});
