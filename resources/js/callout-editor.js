import { calloutBox, calloutContainsPoint, changeCalloutBox, createCallout } from './callouts.js';

export function createCalloutEditor(api) {
    const { canvas, overlay } = api;
    let selected = null; let gesture = null; let textBefore = null;
    const target = document.createElement('div'); target.className = 'callout-target'; target.title = 'Drag to move the target rectangle';
    const note = document.createElement('div'); note.className = 'callout-note';
    const grip = document.createElement('button'); grip.type = 'button'; grip.className = 'callout-grip'; grip.textContent = 'Move note'; grip.title = 'Drag to move the text box';
    const text = document.createElement('textarea'); text.className = 'callout-text'; text.placeholder = 'Write your annotation…'; text.setAttribute('aria-label', 'Annotation text');
    note.append(grip, text); overlay.append(target, note);
    const handles = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
    for (const [element, part] of [[target, 'target'], [note, 'note']]) {
        const moveElement = part === 'target' ? element : grip;
        moveElement.addEventListener('pointerdown', event => startDrag(event, part, 'move'));
        moveElement.tabIndex = 0; moveElement.setAttribute('aria-label', `Move ${part === 'target' ? 'rectangle' : 'text box'}`);
        moveElement.addEventListener('keydown', event => resizeWithKeyboard(event, part, 'move'));
        for (const handle of handles) {
            const button = document.createElement('button'); button.type = 'button'; button.className = `callout-handle handle-${handle}`;
            button.setAttribute('aria-label', `Resize ${part === 'target' ? 'rectangle' : 'text box'} ${handle}`);
            button.addEventListener('pointerdown', event => startDrag(event, part, handle));
            button.addEventListener('keydown', event => resizeWithKeyboard(event, part, handle));
            element.append(button);
        }
    }
    function resizeWithKeyboard(event, part, handle) {
        const directions = { ArrowLeft: { x: -.01, y: 0 }, ArrowRight: { x: .01, y: 0 }, ArrowUp: { x: 0, y: -.01 }, ArrowDown: { x: 0, y: .01 } };
        if (!directions[event.key] || !api.editable() || !current() || event.target !== event.currentTarget) return;
        event.preventDefault(); finishText();
        const before = api.getStrokes(); const next = structuredClone(before);
        next[selected] = changeCalloutBox(before[selected], part, handle, directions[event.key]);
        api.commit(before, next);
    }
    function current() { return selected === null ? null : api.getStrokes()[selected]; }
    function finishText() {
        if (!textBefore) return;
        const before = textBefore; textBefore = null;
        if (JSON.stringify(before) !== JSON.stringify(api.getStrokes())) api.commit(before, api.getStrokes());
    }
    text.addEventListener('focus', () => { if (api.editable()) textBefore = structuredClone(api.getStrokes()); });
    text.addEventListener('input', () => {
        if (!api.editable() || !current()) return;
        if (!textBefore) textBefore = structuredClone(api.getStrokes());
        const next = structuredClone(api.getStrokes()); next[selected].text = text.value;
        api.replace(next, true);
    });
    text.addEventListener('blur', finishText);
    function position(element, box) {
        Object.assign(element.style, { left: `${box.x * 100}%`, top: `${box.y * 100}%`, width: `${box.width * 100}%`, height: `${box.height * 100}%` });
    }
    function render() {
        const stroke = current();
        overlay.hidden = !stroke || stroke.tool !== 'callout' || !api.editable();
        if (overlay.hidden) return;
        overlay.style.setProperty('--callout-color', stroke.color);
        position(target, calloutBox(stroke)); position(note, calloutBox(stroke, 'note'));
        const displayWidth = canvas.getBoundingClientRect().width;
        text.style.fontSize = `${displayWidth * .035}px`; text.style.padding = `${displayWidth * .025}px`;
        if (document.activeElement !== text) text.value = stroke.text || '';
    }
    function startDrag(event, part, handle) {
        if (!api.editable() || event.button !== 0 || (event.target !== event.currentTarget && handle === 'move')) return;
        event.preventDefault(); event.stopPropagation(); finishText();
        gesture = { before: structuredClone(api.getStrokes()), part, handle, start: api.point(event), pointer: event.pointerId, element: event.currentTarget };
        gesture.element.setPointerCapture(event.pointerId);
    }
    overlay.addEventListener('pointermove', event => {
        if (!gesture || event.pointerId !== gesture.pointer) return;
        const point = api.point(event); const next = structuredClone(gesture.before);
        next[selected] = changeCalloutBox(gesture.before[selected], gesture.part, gesture.handle, { x: point.x - gesture.start.x, y: point.y - gesture.start.y });
        api.replace(next);
    });
    overlay.addEventListener('pointerup', event => {
        if (!gesture || event.pointerId !== gesture.pointer) return;
        const before = gesture.before; gesture = null;
        if (JSON.stringify(before) !== JSON.stringify(api.getStrokes())) api.commit(before, api.getStrokes());
        render();
    });
    overlay.addEventListener('pointercancel', event => {
        if (!gesture || event.pointerId !== gesture.pointer) return;
        const before = gesture.before; gesture = null; api.replace(before); render();
    });
    function reset() { finishText(); selected = null; gesture = null; overlay.hidden = true; }
    function place(event) {
        finishText(); const point = api.point(event); const strokes = api.getStrokes();
        selected = strokes.findLastIndex(stroke => stroke.tool === 'callout' && calloutContainsPoint(stroke, point));
        if (selected < 0) {
            const callout = createCallout(point, api.color(), api.width()); selected = strokes.length;
            api.commit(strokes, [...strokes, callout]); render(); text.focus();
        } else { render(); text.focus(); }
        api.onSelect(current());
    }
    function changeStyle(color, width) {
        if (!current() || !api.editable()) return;
        finishText(); const before = api.getStrokes(); const next = structuredClone(before);
        next[selected].color = color; next[selected].width = width;
        if (JSON.stringify(before) !== JSON.stringify(next)) api.commit(before, next);
        render();
    }
    return { render, reset, place, finishText, changeStyle, busy: () => Boolean(gesture) };
}
