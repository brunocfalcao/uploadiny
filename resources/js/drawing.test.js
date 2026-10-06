import test from 'node:test';
import assert from 'node:assert/strict';
import { annotationContainsPoint, commitDrawingHistory, drawAnnotation, normalizedStrokeWidth, positionOnCanvas, redoDrawingHistory, resetDrawingHistory, restoreCancelledErase, undoDrawingHistory } from './drawing.js';

const stroke = (tool, points = [{ x: .2, y: .3 }, { x: .8, y: .7 }]) => ({ tool, points, color: '#3b82f6', width: .004 });

test('eraser hits rectangle and ellipse outlines without erasing their empty centers', () => {
    for (const tool of ['rectangle', 'ellipse']) {
        assert.equal(annotationContainsPoint(stroke(tool), { x: .5, y: .5 }, 1000, 2000), false);
        assert.equal(annotationContainsPoint(stroke(tool), { x: .8, y: .5 }, 1000, 2000), true);
        assert.equal(annotationContainsPoint(stroke(tool), { x: .95, y: .5 }, 1000, 2000), false);
        assert.equal(annotationContainsPoint(stroke(tool, [{ x: .8, y: .7 }, { x: .2, y: .3 }]), { x: .2, y: .5 }, 1000, 2000), true);
    }
});

test('eraser accounts for image aspect ratio, freehand segments and single-point marks', () => {
    const pen = stroke('pen', [{ x: .1, y: .1 }, { x: .3, y: .5 }, { x: .7, y: .5 }]);
    assert.equal(annotationContainsPoint(pen, { x: .5, y: .5 }, 1000, 2000), true);
    assert.equal(annotationContainsPoint(pen, { x: .5, y: .6 }, 1000, 2000), false);
    assert.equal(annotationContainsPoint(stroke('pen', [{ x: .4, y: .4 }, { x: .4, y: .4 }]), { x: .4, y: .4 }, 1000, 2000), true);
});

test('ellipse rendering handles backwards drags and restores drawing context', () => {
    const ellipses = []; let saved = 0; let restored = 0;
    const context = { save() { saved++; }, restore() { restored++; }, beginPath() {}, stroke() {}, ellipse(...args) { ellipses.push(args); } };
    drawAnnotation(context, stroke('ellipse', [{ x: .8, y: .7 }, { x: .2, y: .3 }]), 1000, 2000);
    assert.deepEqual(ellipses, [[500, 1000, 300, 400, 0, 0, Math.PI * 2]]);
    assert.equal(context.lineWidth, 4);
    assert.equal(context.strokeStyle, '#3b82f6');
    assert.equal(saved, 1); assert.equal(restored, 1);
});

test('erase and clear snapshots undo and redo complete drawing states', () => {
    const original = [stroke('pen'), stroke('ellipse')];
    let history = resetDrawingHistory(original);
    history = commitDrawingHistory(history, [original[1]]);
    assert.deepEqual(history.strokes.map(mark => mark.tool), ['ellipse']);
    history = undoDrawingHistory(history);
    assert.deepEqual(history.strokes.map(mark => mark.tool), ['pen', 'ellipse']);
    history = redoDrawingHistory(history);
    assert.deepEqual(history.strokes.map(mark => mark.tool), ['ellipse']);

    history = commitDrawingHistory(history, []);
    assert.deepEqual(history.strokes, []);
    history = undoDrawingHistory(history);
    assert.deepEqual(history.strokes.map(mark => mark.tool), ['ellipse']);
});

test('cancelled erase restores an independent snapshot and switching images clears history', () => {
    const original = [stroke('line')];
    const restored = restoreCancelledErase(original);
    restored[0].points[0].x = .9;
    assert.equal(original[0].points[0].x, .2);

    const history = resetDrawingHistory([stroke('rectangle')]);
    assert.deepEqual(history.strokes.map(mark => mark.tool), ['rectangle']);
    assert.deepEqual(history.undo, []);
    assert.deepEqual(history.redo, []);
});

test('normalized geometry and thickness stay stable across canvas zoom and resize', () => {
    const canvas = { getBoundingClientRect: () => ({ left: 100, top: 50, width: 500, height: 1000 }) };
    const atHalfScale = { clientX: 350, clientY: 550 };
    assert.deepEqual(positionOnCanvas(atHalfScale, canvas), { x: .5, y: .5 });
    canvas.getBoundingClientRect = () => ({ left: 100, top: 50, width: 1000, height: 2000 });
    assert.deepEqual(positionOnCanvas({ clientX: 600, clientY: 1050 }, canvas), { x: .5, y: .5 });
    assert.equal(normalizedStrokeWidth(12, 500), .024);
});


test('callout rectangles resize independently and remain within image edges', async () => {
    const { createCallout, changeCalloutBox, calloutBox } = await import('./callouts.js');
    for (const point of [{ x: 0, y: 0 }, { x: 1, y: 1 }]) {
        const mark = createCallout(point, '#ef4444', .004);
        for (const part of ['target', 'note']) {
            const box = calloutBox(mark, part);
            assert.ok(box.x >= 0 && box.y >= 0 && box.x + box.width <= 1 && box.y + box.height <= 1);
        }
    }
    const mark = createCallout({ x: .5, y: .5 }, '#ef4444', .004);
    const resized = changeCalloutBox(mark, 'target', 'se', { x: .1, y: .2 });
    assert.ok(Math.abs(resized.points[1].x - .7) < 1e-12);
    assert.ok(Math.abs(resized.points[1].y - .73) < 1e-12);
    assert.deepEqual(resized.points.slice(2), mark.points.slice(2));
    const moved = changeCalloutBox(mark, 'note', 'move', { x: -2, y: -2 });
    assert.deepEqual(moved.points[2], { x: 0, y: 0 });
    assert.deepEqual(moved.points.slice(0, 2), mark.points.slice(0, 2));
    assert.ok(Math.abs(mark.points[2].x - .36) < 1e-12);
});

test('callout text color and geometry undo and redo together without sharing mutable snapshots', async () => {
    const { createCallout, changeCalloutBox } = await import('./callouts.js');
    const mark = createCallout({ x: .5, y: .5 }, '#ef4444', .004);
    const changed = changeCalloutBox(mark, 'note', 'e', { x: .1, y: 0 });
    changed.text = '<script>literal feedback</script>'; changed.color = '#16a34a';
    let history = commitDrawingHistory(resetDrawingHistory([mark]), [changed]);
    history = undoDrawingHistory(history);
    assert.deepEqual(history.strokes, [mark]);
    history = redoDrawingHistory(history);
    assert.deepEqual(history.strokes, [changed]);
    changed.text = 'Mutated outside history';
    assert.equal(history.strokes[0].text, '<script>literal feedback</script>');
    assert.equal(annotationContainsPoint(history.strokes[0], { x: .5, y: .5 }, 500, 900), true);
});
