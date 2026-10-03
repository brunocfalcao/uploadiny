export function positionOnCanvas(event, canvas) {
    const rect = canvas.getBoundingClientRect();
    return { x: Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width)), y: Math.max(0, Math.min(1, (event.clientY - rect.top) / rect.height)) };
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
    if (stroke.tool === 'rectangle') ctx.strokeRect(first.x, first.y, last.x - first.x, last.y - first.y);
    else {
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
