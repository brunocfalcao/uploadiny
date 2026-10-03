import assert from 'node:assert/strict';
import test from 'node:test';
import { drawingShortcutAction } from './drawing-shortcuts.js';

function shortcutEvent(overrides = {}) {
    return {
        altKey: false,
        ctrlKey: true,
        defaultPrevented: false,
        isComposing: false,
        key: 'z',
        metaKey: false,
        shiftKey: false,
        target: { closest: () => null },
        ...overrides,
    };
}

const readyEditor = { active: { id: 'image-1' }, source: { naturalWidth: 1280 }, saving: false, draft: null, modalOpen: false };

test('maps Cmd or Ctrl drawing shortcuts to undo and redo', () => {
    assert.equal(drawingShortcutAction(shortcutEvent(), readyEditor), 'undo');
    assert.equal(drawingShortcutAction(shortcutEvent({ metaKey: true, ctrlKey: false, shiftKey: true }), readyEditor), 'redo');
    assert.equal(drawingShortcutAction(shortcutEvent({ key: 'y' }), readyEditor), 'redo');
});

test('does not intercept drawing shortcuts in editable fields or while the project dialog is open', () => {
    const editableEvent = shortcutEvent({ target: { closest: selector => selector.includes('textarea') ? {} : null } });

    assert.equal(drawingShortcutAction(editableEvent, readyEditor), null);
    assert.equal(drawingShortcutAction(shortcutEvent(), { ...readyEditor, modalOpen: true }), null);
    assert.equal(drawingShortcutAction(shortcutEvent(), { ...readyEditor, draft: { tool: 'pen' } }), null);
});
