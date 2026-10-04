import { calloutContainsPoint, drawCallout } from './callouts.js';

export function positionOnCanvas(event, canvas) {
    const rect = canvas.getBoundingClientRect();
    return { x: Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width)), y: Math.max(0, Math.min(1, (event.clientY - rect.top) / rect.height)) };
}

function cloneStrokes(strokes) {
    return structuredClone(strokes);
}

export function normalizedStrokeWidth(thickness, canvasWidth) {
    return Math.max(.0001, Math.min(.1, Number(thickness) / canvasWidth));
}

export function resetDrawingHistory(strokes) {
    return { strokes: cloneStrokes(strokes), undo: [], redo: [] };
}

export function commitDrawingHistory({ strokes, undo }, next) {
    return { strokes: cloneStrokes(next), undo: [...undo, cloneStrokes(strokes)], redo: [] };
}

export function undoDrawingHistory({ strokes, undo, redo }) {
    if (!undo.length) return { strokes, undo, redo };
    return { strokes: cloneStrokes(undo.at(-1)), undo: undo.slice(0, -1), redo: [...redo, cloneStrokes(strokes)] };
}

export function redoDrawingHistory({ strokes, undo, redo }) {
    if (!redo.length) return { strokes, undo, redo };
    return { strokes: cloneStrokes(redo.at(-1)), undo: [...undo, cloneStrokes(strokes)], redo: redo.slice(0, -1) };
}

export function restoreCancelledErase(strokes) {
    return cloneStrokes(strokes);
}

export function drawAnnotation(ctx, stroke, width, height) {
    if (stroke.points.length < 2) return;
    const points = stroke.points.map(point => ({ x: point.x * width, y: point.y * height }));
    const first = points[0];
    const last = points.at(-1);
    ctx.save();
    ctx.strokeStyle = stroke.color;
    ctx.fillStyle = stroke.color;
    ctx.lineWidth = Math.max(1, stroke.width * width);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    if (stroke.tool === 'callout') drawCallout(ctx, stroke, width, height);
    else if (stroke.tool === 'rectangle') ctx.strokeRect(first.x, first.y, last.x - first.x, last.y - first.y);
    else if (stroke.tool === 'ellipse') {
        ctx.beginPath();
        ctx.ellipse((first.x + last.x) / 2, (first.y + last.y) / 2, Math.abs(last.x - first.x) / 2, Math.abs(last.y - first.y) / 2, 0, 0, Math.PI * 2);
        ctx.stroke();
    } else {
        ctx.beginPath(); ctx.moveTo(first.x, first.y);
        for (const point of points.slice(1)) ctx.lineTo(point.x, point.y);
        ctx.stroke();
        if (stroke.tool === 'arrow') {
            const angle = Math.atan2(last.y - first.y, last.x - first.x);
            const size = Math.max(12, ctx.lineWidth * 5);
            ctx.beginPath(); ctx.moveTo(last.x, last.y);
            ctx.lineTo(last.x - size * Math.cos(angle - Math.PI / 6), last.y - size * Math.sin(angle - Math.PI / 6));
            ctx.lineTo(last.x - size * Math.cos(angle + Math.PI / 6), last.y - size * Math.sin(angle + Math.PI / 6));
            ctx.closePath(); ctx.fill();
        }
    }
    ctx.restore();
}

function distanceToSegment(point, start, end) {
    const dx = end.x - start.x;
    const dy = end.y - start.y;
    const length = dx * dx + dy * dy;
    const t = length ? Math.max(0, Math.min(1, ((point.x - start.x) * dx + (point.y - start.y) * dy) / length)) : 0;
    return Math.hypot(point.x - start.x - t * dx, point.y - start.y - t * dy);
}

export function annotationContainsPoint(stroke, point, width, height, tolerance = 8) {
    if (stroke.tool === 'callout') return calloutContainsPoint(stroke, point);
    const points = stroke.points.map(entry => ({ x: entry.x * width, y: entry.y * height }));
    if (points.length < 2) return false;
    const first = points[0];
    const last = points.at(-1);
    const target = { x: point.x * width, y: point.y * height };
    let outline = points;
    if (stroke.tool === 'rectangle') outline = [first, { x: last.x, y: first.y }, last, { x: first.x, y: last.y }, first];
    if (stroke.tool === 'ellipse') {
        const center = { x: (first.x + last.x) / 2, y: (first.y + last.y) / 2 };
        outline = Array.from({ length: 65 }, (_, index) => {
            const angle = index / 64 * Math.PI * 2;
            return { x: center.x + Math.abs(last.x - first.x) / 2 * Math.cos(angle), y: center.y + Math.abs(last.y - first.y) / 2 * Math.sin(angle) };
        });
    }
    const radius = tolerance + Math.max(1, stroke.width * width) / 2;
    if (outline.slice(1).some((entry, index) => distanceToSegment(target, outline[index], entry) <= radius)) return true;
    if (stroke.tool !== 'arrow') return false;
    const angle = Math.atan2(last.y - first.y, last.x - first.x);
    const size = Math.max(12, stroke.width * width * 5);
    const left = { x: last.x - size * Math.cos(angle - Math.PI / 6), y: last.y - size * Math.sin(angle - Math.PI / 6) };
    const right = { x: last.x - size * Math.cos(angle + Math.PI / 6), y: last.y - size * Math.sin(angle + Math.PI / 6) };
    return distanceToSegment(target, last, left) <= radius || distanceToSegment(target, last, right) <= radius || distanceToSegment(target, left, right) <= radius;
}
