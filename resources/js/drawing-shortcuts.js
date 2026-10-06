const editableSelector = 'input,textarea,select,[contenteditable],[role=combobox]';

export function drawingShortcutAction(event, { active, source, saving, draft, modalOpen }) {
    if (!active || saving || draft || modalOpen || event.defaultPrevented || event.isComposing || event.altKey || !(event.metaKey || event.ctrlKey)) return null;

    const key = event.key.toLowerCase();
    if (!event.shiftKey) {
        if (key === 'enter') return 'save-feedback';
        if (key === 'arrowleft') return 'previous-file';
        if (key === 'arrowright') return 'next-file';
    }
    if (!source || (event.target?.closest(editableSelector) && !event.target.closest('.callout-text'))) return null;

    if (key === 'z') return event.shiftKey ? 'redo' : 'undo';
    if (key === 'y' && event.ctrlKey && !event.metaKey) return 'redo';

    return null;
}
