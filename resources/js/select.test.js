import assert from 'node:assert/strict';
import test from 'node:test';
import { enhanceProjectSelect } from './select.js';

class FakeClassList {
    constructor() {
        this.values = new Set();
    }

    toggle(value, enabled) {
        if (enabled) this.values.add(value);
        else this.values.delete(value);
    }

    contains(value) {
        return this.values.has(value);
    }
}

class FakeElement {
    constructor(tagName) {
        this.tagName = tagName;
        this.attributes = new Map();
        this.children = [];
        this.classList = new FakeClassList();
        this.hidden = false;
        this.listeners = new Map();
        this.style = {};
        this.textContent = '';
    }

    addEventListener(type, callback) {
        this.listeners.set(type, [...(this.listeners.get(type) ?? []), callback]);
    }

    after(element) {
        this.afterElement = element;
    }

    append(element) {
        this.children.push(element);
    }

    contains(element) {
        return element === this || this.children.some(child => child.contains(element));
    }

    dispatchEvent(event) {
        for (const callback of this.listeners.get(event.type) ?? []) callback(event);

        return true;
    }

    emit(type, properties = {}) {
        let prevented = false;
        this.dispatchEvent({ type, preventDefault: () => { prevented = true; }, ...properties });

        return prevented;
    }

    focus() {}

    getBoundingClientRect() {
        return { left: 20, width: 240, top: 100, bottom: 140 };
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    scrollIntoView() {}

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }
}

class FakeSelect extends FakeElement {
    constructor() {
        super('select');
        this.id = 'move-project';
        this.options = [
            { label: 'Alpha', value: '1' },
            { label: 'Beta', value: '2' },
            { label: 'Gamma', value: '3' },
        ];
        this.selectedIndex = 0;
        this.disabled = false;
    }

    get selectedOptions() {
        return this.selectedIndex >= 0 ? [this.options[this.selectedIndex]] : [];
    }
}

function installDocument() {
    const label = new FakeElement('label');
    const body = new FakeElement('body');
    const document = {
        body,
        createElement: tagName => new FakeElement(tagName),
        querySelector: selector => selector === 'label[for="move-project"]' ? label : null,
        addEventListener: () => {},
    };
    const window = { addEventListener: () => {}, innerHeight: 900 };

    return { document, window };
}

function withFakeDom(callback) {
    const originalDocument = globalThis.document;
    const originalWindow = globalThis.window;
    const { document, window } = installDocument();
    globalThis.document = document;
    globalThis.window = window;

    try {
        callback(document);
    } finally {
        globalThis.document = originalDocument;
        globalThis.window = originalWindow;
    }
}

test('keyboard selection and programmatic changes stay synchronized with the project picker', () => {
    withFakeDom(document => {
        const select = new FakeSelect();
        let changes = 0;
        select.addEventListener('change', () => { changes++; });
        enhanceProjectSelect(select);
        const trigger = select.afterElement;
        const menu = document.body.children[0];

        assert.equal(trigger.textContent, 'Alpha');
        assert.equal(trigger.emit('keydown', { key: 'ArrowDown' }), true);
        assert.equal(menu.hidden, false);
        trigger.emit('keydown', { key: 'ArrowDown' });
        trigger.emit('keydown', { key: 'Enter' });
        assert.equal(select.selectedIndex, 1);
        assert.equal(changes, 1);
        assert.equal(trigger.textContent, 'Beta');
        assert.equal(menu.hidden, true);

        select.selectedIndex = 2;
        select.dispatchEvent(new Event('change'));
        assert.equal(trigger.textContent, 'Gamma');
        assert.equal(menu.children[2].getAttribute('aria-selected'), 'true');
    });
});

test('typeahead highlights a project and Escape closes the picker without changing it', () => {
    withFakeDom(document => {
        const select = new FakeSelect();
        enhanceProjectSelect(select);
        const trigger = select.afterElement;
        const menu = document.body.children[0];

        trigger.emit('keydown', { key: 'g', altKey: false, ctrlKey: false, metaKey: false });
        assert.equal(menu.hidden, false);
        assert.equal(menu.children[2].classList.contains('qr-select-option-active'), true);
        trigger.emit('keydown', { key: 'Escape' });
        assert.equal(menu.hidden, true);
        assert.equal(select.selectedIndex, 0);
    });
});
