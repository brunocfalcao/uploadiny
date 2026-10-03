const editableSelector = 'input,textarea,select,[contenteditable],[role=combobox]';

export function drawingShortcutAction(event, { active, source, saving, draft, modalOpen }) {
    if (!active || !source || saving || draft || event.defaultPrevented || event.isComposing || event.altKey || !(event.metaKey || event.ctrlKey)) return null;
    if (event.target?.closest(editableSelector) || modalOpen) return null;

    const key = event.key.toLowerCase();
    if (key === 'z') return event.shiftKey ? 'redo' : 'undo';
    if (key === 'y' && event.ctrlKey && !event.metaKey) return 'redo';

    return null;
}
