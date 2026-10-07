import assert from 'node:assert/strict';
import test from 'node:test';
import { keepLoadedImages } from './gallery-images.js';

function image(src, { complete = true, naturalWidth = 10, attributes = {} } = {}) {
    const attrs = { src, ...attributes };
    return {
        complete, naturalWidth, replacedBy: null,
        get attributes() { return Object.entries(attrs).map(([name, value]) => ({ name, value })); },
        getAttribute: name => attrs[name] ?? null,
        setAttribute(name, value) { attrs[name] = value; },
        replaceWith(other) { this.replacedBy = other; },
    };
}
const root = images => ({ querySelectorAll: selector => selector === 'img[src]' ? images : [] });

test('a refreshed gallery reuses already loaded previews with the same address and takes the new attributes', () => {
    const shown = image('/images/a/preview');
    const fresh = image('/images/a/preview', { attributes: { alt: 'new', loading: 'lazy' } });
    keepLoadedImages(root([shown]), root([fresh]));
    assert.equal(fresh.replacedBy, shown);
    assert.equal(shown.getAttribute('alt'), 'new');
    assert.equal(shown.getAttribute('loading'), 'lazy');
});

test('new, changed, unfinished or broken previews still load fresh', () => {
    const unfinished = image('/images/b/preview', { complete: false });
    const broken = image('/images/c/preview', { naturalWidth: 0 });
    const shown = image('/images/a/preview');
    const next = [image('/images/b/preview'), image('/images/c/preview'), image('/images/d/preview')];
    keepLoadedImages(root([unfinished, broken, shown]), root(next));
    assert.deepEqual(next.map(item => item.replacedBy), [null, null, null]);
});

test('one loaded element is moved into only one place when the same preview appears twice', () => {
    const shown = image('/images/a/preview');
    const first = image('/images/a/preview');
    const second = image('/images/a/preview');
    keepLoadedImages(root([shown]), root([first, second]));
    assert.equal(first.replacedBy, shown);
    assert.equal(second.replacedBy, null);
});
