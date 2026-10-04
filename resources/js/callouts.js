const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

export function createCallout(point, color, width) {
    const left = clamp(point.x - .15, 0, .7);
    const top = clamp(point.y - .04, 0, .92);
    const noteTop = top >= .25 ? top - .2 : Math.min(.82, top + .14);
    return { tool: 'callout', color, width, text: '', points: [{ x: left, y: top }, { x: left + .3, y: top + .08 }, { x: clamp(point.x - .23, 0, .54), y: noteTop }, { x: clamp(point.x - .23, 0, .54) + .46, y: noteTop + .16 }] };
}

export function calloutBox(stroke, part = 'target') {
    const offset = part === 'note' ? 2 : 0;
    const [first, last] = stroke.points.slice(offset, offset + 2);
    return { x: first.x, y: first.y, width: last.x - first.x, height: last.y - first.y };
}

export function changeCalloutBox(stroke, part, handle, delta) {
    const result = structuredClone(stroke);
    const offset = part === 'note' ? 2 : 0;
    const first = result.points[offset]; const last = result.points[offset + 1];
    if (handle === 'move') {
        const dx = clamp(delta.x, -first.x, 1 - last.x);
        const dy = clamp(delta.y, -first.y, 1 - last.y);
        first.x += dx; last.x += dx; first.y += dy; last.y += dy;
    } else {
        if (handle.includes('w')) first.x = clamp(first.x + delta.x, 0, last.x - .02);
        if (handle.includes('e')) last.x = clamp(last.x + delta.x, first.x + .02, 1);
        if (handle.includes('n')) first.y = clamp(first.y + delta.y, 0, last.y - .02);
        if (handle.includes('s')) last.y = clamp(last.y + delta.y, first.y + .02, 1);
    }
    return result;
}

function edgePoint(box, toward) {
    const center = { x: box.x + box.width / 2, y: box.y + box.height / 2 };
    const dx = toward.x - center.x; const dy = toward.y - center.y;
    const scale = Math.max(Math.abs(dx) / (box.width / 2), Math.abs(dy) / (box.height / 2));
    return scale ? { x: center.x + dx / scale, y: center.y + dy / scale } : center;
}

export function calloutArrow(stroke) {
    const target = calloutBox(stroke); const note = calloutBox(stroke, 'note');
    return { start: edgePoint(note, { x: target.x + target.width / 2, y: target.y + target.height / 2 }), end: edgePoint(target, { x: note.x + note.width / 2, y: note.y + note.height / 2 }) };
}

export function calloutContainsPoint(stroke, point) {
    return ['target', 'note'].some(part => { const box = calloutBox(stroke, part); return point.x >= box.x && point.x <= box.x + box.width && point.y >= box.y && point.y <= box.y + box.height; });
}

export function drawCallout(ctx, stroke, width, height) {
    const target = calloutBox(stroke); const note = calloutBox(stroke, 'note');
    const arrow = calloutArrow(stroke);
    ctx.strokeRect(target.x * width, target.y * height, target.width * width, target.height * height);
    const start = { x: arrow.start.x * width, y: arrow.start.y * height };
    const end = { x: arrow.end.x * width, y: arrow.end.y * height };
    ctx.beginPath(); ctx.moveTo(start.x, start.y); ctx.lineTo(end.x, end.y); ctx.stroke();
    const angle = Math.atan2(end.y - start.y, end.x - start.x); const size = Math.max(width * .025, ctx.lineWidth * 3);
    ctx.beginPath(); ctx.moveTo(end.x, end.y); ctx.lineTo(end.x - size * Math.cos(angle - Math.PI / 6), end.y - size * Math.sin(angle - Math.PI / 6)); ctx.lineTo(end.x - size * Math.cos(angle + Math.PI / 6), end.y - size * Math.sin(angle + Math.PI / 6)); ctx.closePath(); ctx.fill();
    const x = note.x * width; const y = note.y * height; const w = note.width * width; const h = note.height * height;
    ctx.fillStyle = '#ffffff'; ctx.fillRect(x, y, w, h);
    ctx.lineWidth = Math.max(1, width * .002); ctx.strokeRect(x, y, w, h);
    const fontSize = width * .035; const padding = width * .025; const lineHeight = fontSize * 1.4;
    ctx.font = `500 ${fontSize}px Inter, system-ui, sans-serif`; ctx.textBaseline = 'top'; ctx.fillStyle = '#182337';
    ctx.beginPath(); ctx.rect(x + padding, y + padding, Math.max(0, w - padding * 2), Math.max(0, h - padding * 2)); ctx.clip();
    let line = ''; let row = 0;
    for (const character of Array.from(stroke.text || 'Add a note…')) {
        if (character === '\n' || (line && ctx.measureText(line + character).width > w - padding * 2)) {
            ctx.fillText(line, x + padding, y + padding + row++ * lineHeight); line = character === '\n' ? '' : character;
        } else line += character;
    }
    ctx.fillText(line, x + padding, y + padding + row * lineHeight);
}
