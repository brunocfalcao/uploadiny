import './bootstrap';
import { enhanceProjectSelect } from './select';
enhanceProjectSelect(document.getElementById('move-project'));
import {
    annotationContainsPoint,
    commitDrawingHistory,
    drawAnnotation,
    normalizedStrokeWidth,
    positionOnCanvas,
    redoDrawingHistory,
    resetDrawingHistory,
    restoreCancelledErase,
    undoDrawingHistory,
} from './drawing';
import { drawingShortcutAction } from './drawing-shortcuts';

const workspace = document.querySelector('[data-workspace]');
if (workspace) {
    const config = JSON.parse(document.getElementById('workspace-config').textContent);
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const dialog = document.getElementById('project-dialog');
    const form = document.getElementById('project-form');
    const nameInput = document.getElementById('project-name');
    const slugInput = document.getElementById('project-slug');
    let slugEdited = false;
    let active = null;
    let source = null;
    let strokes = [];
    let undo = [];
    let redo = [];
    let draft = null;
    let tool = 'pen';
    let eraseStart = null;
    let drawingPointer = null;
    let zoom = 1;
    let fitView = true;
    let dirty = false;
    let saving = false;
    let loading = false;
    let uploadingChunk = null;
    let pollTimer = null;
    let loadGeneration = 0;
    const canvas = document.getElementById('annotation-canvas');
    const ctx = canvas.getContext('2d');
    const stage = document.getElementById('canvas-stage');
    const ink = document.getElementById('drawing-color');
    const thickness = document.getElementById('drawing-width');
    const message = document.getElementById('workspace-message');
    const comments = document.getElementById('image-comments');
    const saveState = document.getElementById('save-state');

    function notify(text, error = false) {
        message.textContent = text;
        message.classList.toggle('error-message', error);
        message.hidden = false;
    }
    async function request(url, options = {}) {
        const response = await fetch(url, {
            ...options,
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token, ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }), ...options.headers },
        });
        let payload;
        try { payload = await response.json(); } catch { throw new Error('The server did not return a valid response. Reload and try again.'); }
        if (!response.ok) {
            const error = new Error(Object.values(payload.errors || {}).flat()[0] || payload.message || 'The request failed. Try again.');
            error.status = response.status;
            throw error;
        }
        return payload;
    }
    function projectForm(editing) {
        form.action = editing ? config.project_url : config.create_url;
        document.getElementById('project-method').value = editing ? 'PATCH' : 'POST';
        document.getElementById('project-dialog-title').textContent = editing ? 'Project settings' : 'Create project';
        document.getElementById('project-submit').textContent = editing ? 'Save project' : 'Create project';
        nameInput.value = editing ? config.project.name : '';
        slugInput.value = editing ? config.project.slug : '';
        document.getElementById('project-description').value = editing ? config.project.description || '' : '';
        const deletion = document.getElementById('delete-project-form');
        if (deletion) deletion.hidden = !editing;
        slugEdited = editing;
        dialog.showModal();
        nameInput.focus();
    }
    document.querySelectorAll('[data-new-project]').forEach(button => button.addEventListener('click', () => projectForm(false)));
    document.querySelector('[data-edit-project]')?.addEventListener('click', () => projectForm(true));
    document.querySelector('[data-close-project]').addEventListener('click', () => dialog.close());
    slugInput.addEventListener('input', () => { slugEdited = true; });
    nameInput.addEventListener('input', () => {
        if (!slugEdited) slugInput.value = nameInput.value.normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    });
    document.getElementById('delete-project-form')?.addEventListener('submit', event => {
        if (!confirm('Permanently delete this project and all of its images and annotations?')) event.preventDefault();
    });

    const input = document.getElementById('image-input');
    const dropzone = document.getElementById('dropzone');
    document.querySelector('[data-upload-trigger]')?.addEventListener('click', () => input.click());
    dropzone?.addEventListener('click', () => input.click());
    dropzone?.addEventListener('keydown', event => { if (['Enter', ' '].includes(event.key)) { event.preventDefault(); input.click(); } });
    input?.addEventListener('change', () => { upload([...input.files]); input.value = ''; });
    for (const eventName of ['dragenter', 'dragover']) dropzone?.addEventListener(eventName, event => { event.preventDefault(); dropzone.classList.add('dragging'); });
    for (const eventName of ['dragleave', 'drop']) dropzone?.addEventListener(eventName, event => { event.preventDefault(); dropzone.classList.remove('dragging'); });
    dropzone?.addEventListener('drop', event => upload([...event.dataTransfer.files]));
    document.addEventListener('paste', event => {
        if (!config.project || active || event.target.closest('input,textarea,[contenteditable]')) return;
        const files = [...(event.clipboardData?.files || [])];
        if (files.length) { event.preventDefault(); upload(files); }
    });
    async function upload(files) {
        if (!files.length || loading) return;
        if (files.some(file => /\.(heic|heif|tiff?)$/i.test(file.name))) { notify('Use JPEG, PNG, WebP, GIF or BMP here. The iPhone share button converts HEIC photos automatically.', true); return; }
        if (active && !leaveEditor()) return;
        loading = true;
        let chunkId = null;
        const progress = document.getElementById('upload-progress');
        progress.hidden = false;
        const totalBytes = files.reduce((sum, file) => sum + file.size, 0);
        let sentBytes = 0;
        function showProgress(bytes, index) {
            const percent = totalBytes ? Math.min(100, Math.round(bytes / totalBytes * 100)) : 0;
            progress.querySelector('progress').value = percent;
            progress.querySelector('span').textContent = `${index + 1} / ${files.length} · ${percent}%`;
        }
        try {
            const draft = await request(config.upload_url, { method: 'POST', body: JSON.stringify({ image_count: files.length }) });
            chunkId = draft.id;
            uploadingChunk = chunkId;
            for (let index = 0; index < files.length; index++) {
                const file = files[index];
                await new Promise((resolve, reject) => {
                    const body = new FormData(); body.append('file', file);
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', `/chunks/${chunkId}/images`);
                    xhr.setRequestHeader('X-CSRF-TOKEN', token);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.upload.addEventListener('progress', event => { if (event.lengthComputable) showProgress(sentBytes + Math.min(file.size, event.loaded), index); });
                    xhr.addEventListener('load', () => {
                        if (xhr.status >= 200 && xhr.status < 300) resolve();
                        else {
                            let payload = {}; try { payload = JSON.parse(xhr.responseText); } catch { /* Proxy response. */ }
                            reject(new Error(Object.values(payload.errors || {}).flat()[0] || payload.message || `Image upload failed (${xhr.status}).`));
                        }
                    });
                    xhr.addEventListener('error', () => reject(new Error('Connection interrupted. Check your project before trying again.')));
                    xhr.addEventListener('abort', () => reject(new Error('Upload cancelled.')));
                    xhr.send(body);
                });
                sentBytes += file.size; showProgress(sentBytes, index);
            }
            await request(`/chunks/${chunkId}/complete`, { method: 'POST' });
            uploadingChunk = null; loading = false;
            location.reload();
        } catch (error) {
            if (chunkId) { try { await request(`/chunks/${chunkId}`, { method: 'DELETE' }); } catch { /* Never delete a completed group after a lost response. */ } }
            uploadingChunk = null; loading = false; progress.hidden = true;
            notify(`${error.message} Incomplete groups are not published.`, true);
        }
    }

    function setDirty() { dirty = true; saveState.textContent = 'Unsaved changes'; }
    function repaint() {
        if (!source) return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
        [...strokes, ...(draft ? [draft] : [])].forEach(stroke => drawAnnotation(ctx, stroke, canvas.width, canvas.height));
        document.getElementById('undo-drawing').disabled = saving || !undo.length;
        document.getElementById('redo-drawing').disabled = saving || !redo.length;
        document.getElementById('clear-drawing').disabled = saving || !strokes.length;
    }
    function leaveEditor() {
        if (saving) { notify('Wait for your feedback to finish saving.'); return false; }
        if (dirty && !confirm('Leave without saving your feedback?')) return false;
        clearTimeout(pollTimer);
        loadGeneration++;
        active = null; source = null; dirty = false;
        document.getElementById('editor').hidden = true;
        document.getElementById('gallery').hidden = false;
        return true;
    }
    document.getElementById('close-editor').addEventListener('click', () => { if (leaveEditor()) location.reload(); });
    document.querySelectorAll('[data-open-image]').forEach(button => button.addEventListener('click', () => openImage(button.dataset.openImage)));
    async function openImage(id) {
        if (active && !leaveEditor()) return;
        const generation = ++loadGeneration;
        try {
            const data = await request(`/images/${id}`);
            if (generation !== loadGeneration) return;
            active = data; dirty = false; source = null;
            ({ strokes, undo, redo } = resetDrawingHistory(data.annotations)); draft = null; eraseStart = null; zoom = 1; fitView = true;
            comments.value = data.comments;
            saveState.textContent = '';
            document.getElementById('editor-name').textContent = data.name;
            document.getElementById('download-original').href = `/images/${id}/download`;
            document.getElementById('move-project').value = config.project.id;
            document.getElementById('move-project').dispatchEvent(new Event('change'));
            document.getElementById('gallery').hidden = true;
            document.getElementById('editor').hidden = false;
            document.getElementById('save-feedback').disabled = true;
            const imageError = document.getElementById('image-load-error');
            imageError.hidden = true;
            canvas.hidden = true;
            showDescription(data);
            const image = new Image();
            image.onload = () => {
                if (generation !== loadGeneration) return;
                source = image;
                const scale = Math.min(1, 2048 / Math.max(image.naturalWidth, image.naturalHeight));
                canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
                canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
                canvas.hidden = false;
                document.getElementById('save-feedback').disabled = false;
                document.getElementById('canvas-dimensions').textContent = `${image.naturalWidth} × ${image.naturalHeight}`;
                sizeCanvas();
                repaint();
            };
            image.onerror = () => {
                if (generation !== loadGeneration) return;
                imageError.textContent = 'This browser cannot preview this format. Download the original to inspect it. Written feedback can still be saved.';
                imageError.hidden = false;
                document.getElementById('save-feedback').disabled = false;
            };
            image.src = data.preview_url;
            document.getElementById('close-editor').focus();
        } catch (error) { notify(error.message, true); }
    }
    function showDescription(data) {
        clearTimeout(pollTimer);
        const text = document.getElementById('vision-description');
        const retry = document.getElementById('retry-description');
        const status = document.getElementById('vision-status');
        status.textContent = data.description_status === 'ready' ? 'Ready' : data.description_status === 'failed' ? 'Unavailable' : 'Generating';
        status.dataset.state = data.description_status;
        text.parentElement.scrollTop = 0;
        if (data.description_status === 'ready') text.textContent = data.description;
        else if (data.description_status === 'failed') text.textContent = data.description_error || 'Description unavailable.';
        else text.textContent = 'Describing this image… You can add feedback now.';
        retry.hidden = data.description_status !== 'failed';
        if (['pending', 'processing'].includes(data.description_status) && active) {
            const id = active.id;
            pollTimer = setTimeout(async () => {
                try { const updated = await request(`/images/${id}`); if (active?.id === id) showDescription(updated); }
                catch (error) { if (active?.id === id) notify(error.message, true); }
            }, 3000);
        }
    }
    const toolHints = { pen: 'Pen: draw freely on the image.', arrow: 'Arrow: drag to point at a detail.', line: 'Line: drag to draw a straight line.', rectangle: 'Rectangle: drag around an area.', ellipse: 'Ellipse: drag to circle an area.', eraser: 'Eraser: drag over a mark to remove it. Undo restores it.' };
    document.querySelectorAll('[data-tool]').forEach(button => button.addEventListener('click', () => {
        if (draft || eraseStart) return;
        tool = button.dataset.tool;
        canvas.dataset.tool = tool;
        document.getElementById('canvas-tool-hint').textContent = toolHints[tool];
        document.querySelectorAll('[data-tool]').forEach(entry => { entry.classList.toggle('active', entry === button); entry.setAttribute('aria-pressed', entry === button ? 'true' : 'false'); });
    }));
    function updateInkControls() {
        const preview = document.getElementById('stroke-preview');
        preview.style.width = `${thickness.value}px`;
        preview.style.height = `${thickness.value}px`;
        preview.style.backgroundColor = ink.value;
        document.getElementById('drawing-width-value').textContent = `${thickness.value} px`;
        document.querySelectorAll('[data-color]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.color === ink.value)));
    }
    ink.addEventListener('input', updateInkControls);
    thickness.addEventListener('input', updateInkControls);
    document.querySelectorAll('[data-color]').forEach(button => button.addEventListener('click', () => { ink.value = button.dataset.color; updateInkControls(); }));
    updateInkControls();

    function sizeCanvas() {
        if (!source) return;
        const padding = window.innerWidth <= 760 ? 48 : 72;
        const fit = Math.min(1, Math.max(1, stage.clientWidth - padding) / canvas.width, Math.max(1, stage.clientHeight - padding) / canvas.height);
        if (fitView) zoom = fit;
        canvas.style.width = `${canvas.width * zoom}px`;
        document.getElementById('canvas-zoom').textContent = `${Math.round(zoom * 100)}%`;
        document.getElementById('zoom-out').disabled = zoom <= .1;
        document.getElementById('zoom-in').disabled = zoom >= 4;
    }
    function changeZoom(next) {
        if (!source || draft || eraseStart) return;
        const oldWidth = canvas.getBoundingClientRect().width;
        const centerX = stage.scrollLeft + stage.clientWidth / 2;
        const centerY = stage.scrollTop + stage.clientHeight / 2;
        fitView = false;
        zoom = Math.max(.1, Math.min(4, next));
        sizeCanvas();
        const ratio = canvas.getBoundingClientRect().width / oldWidth;
        stage.scrollLeft = centerX * ratio - stage.clientWidth / 2;
        stage.scrollTop = centerY * ratio - stage.clientHeight / 2;
    }
    document.getElementById('zoom-out').addEventListener('click', () => changeZoom(zoom / 1.25));
    document.getElementById('zoom-in').addEventListener('click', () => changeZoom(zoom * 1.25));
    document.getElementById('zoom-fit').addEventListener('click', () => { if (!source || draft || eraseStart) return; fitView = true; sizeCanvas(); stage.scrollTop = 0; stage.scrollLeft = 0; });
    new ResizeObserver(sizeCanvas).observe(stage);

    function commitStrokes(next) {
        ({ strokes, undo, redo } = commitDrawingHistory({ strokes, undo, redo }, next));
        setDirty();
        repaint();
    }
    function eraseAt(event) {
        const point = positionOnCanvas(event, canvas);
        const tolerance = 10 * canvas.width / canvas.getBoundingClientRect().width;
        strokes = strokes.filter(stroke => !annotationContainsPoint(stroke, point, canvas.width, canvas.height, tolerance));
        repaint();
    }
    canvas.addEventListener('pointerdown', event => {
        if (!source || saving || draft || eraseStart || event.button !== 0) return;
        event.preventDefault(); canvas.setPointerCapture(event.pointerId);
        drawingPointer = event.pointerId;
        if (tool === 'eraser') { eraseStart = restoreCancelledErase(strokes); eraseAt(event); return; }
        const point = positionOnCanvas(event, canvas);
        draft = { tool, color: ink.value, width: normalizedStrokeWidth(thickness.value, canvas.width), points: [point, { ...point }] };
        repaint();
    });
    canvas.addEventListener('pointermove', event => {
        if (event.pointerId !== drawingPointer) return;
        if (eraseStart) { eraseAt(event); return; }
        if (!draft) return;
        const point = positionOnCanvas(event, canvas);
        if (draft.tool === 'pen') draft.points.push(point); else draft.points[1] = point;
        repaint();
    });
    canvas.addEventListener('pointerup', event => {
        if (event.pointerId !== drawingPointer) return;
        drawingPointer = null;
        if (eraseStart) {
            const before = eraseStart; eraseStart = null;
            if (before.length !== strokes.length) {
                ({ strokes, undo, redo } = commitDrawingHistory({ strokes: before, undo, redo }, strokes));
                setDirty();
            }
            repaint();
        } else if (draft) {
            const stroke = draft; draft = null;
            commitStrokes([...strokes, stroke]);
        }
    });
    canvas.addEventListener('pointercancel', event => { if (event.pointerId !== drawingPointer) return; drawingPointer = null; if (eraseStart) strokes = restoreCancelledErase(eraseStart); eraseStart = null; draft = null; repaint(); });
    document.getElementById('undo-drawing').addEventListener('click', () => { if (!saving && !draft && !eraseStart && undo.length) { ({ strokes, undo, redo } = undoDrawingHistory({ strokes, undo, redo })); setDirty(); repaint(); } });
    document.getElementById('redo-drawing').addEventListener('click', () => { if (!saving && !draft && !eraseStart && redo.length) { ({ strokes, undo, redo } = redoDrawingHistory({ strokes, undo, redo })); setDirty(); repaint(); } });
    document.getElementById('clear-drawing').addEventListener('click', () => { if (!saving && !draft && !eraseStart && strokes.length) commitStrokes([]); });
    document.addEventListener('keydown', event => {
        const action = drawingShortcutAction(event, { active, source, saving, draft: draft || eraseStart, modalOpen: dialog.open });
        if (!action) return;
        event.preventDefault();
        document.getElementById(`${action}-drawing`).click();
    });
    comments.addEventListener('input', setDirty);
    document.getElementById('save-feedback').addEventListener('click', async () => {
        if (!active || saving || draft || eraseStart) return;
        saving = true;
        repaint();
        const button = document.getElementById('save-feedback'); button.disabled = true;
        comments.disabled = true; saveState.textContent = 'Saving…';
        try {
            const data = await request(`/images/${active.id}`, { method: 'PATCH', body: JSON.stringify({ comments: comments.value, annotations: strokes, revision: active.revision, annotated_image: strokes.length && source ? canvas.toDataURL('image/png') : null }) });
            active.revision = data.revision; dirty = false; saveState.textContent = 'Saved';
        } catch (error) { saveState.textContent = 'Not saved'; notify(error.message, true); }
        finally { saving = false; button.disabled = false; comments.disabled = false; repaint(); }
    });
    document.getElementById('retry-description').addEventListener('click', async event => {
        if (!active) return;
        const id = active.id;
        event.currentTarget.disabled = true;
        try { const result = await request(`/images/${id}/description`, { method: 'POST' }); if (active?.id === id) showDescription(result); }
        catch (error) { notify(error.message, true); }
        finally { document.getElementById('retry-description').disabled = false; }
    });
    document.getElementById('move-image').addEventListener('click', async event => {
        if (!active || saving || (dirty && !confirm('Move this image without saving your current edits?'))) return;
        event.currentTarget.disabled = true;
        try { const result = await request(`/images/${active.id}/project`, { method: 'PATCH', body: JSON.stringify({ project_id: Number(document.getElementById('move-project').value) }) }); dirty = false; location.href = result.project_url; }
        catch (error) { notify(error.message, true); event.target.disabled = false; }
    });
    document.getElementById('delete-image').addEventListener('click', async event => {
        if (!active || saving || !confirm('Permanently delete this image and all of its feedback?')) return;
        event.currentTarget.disabled = true;
        try { await request(`/images/${active.id}`, { method: 'DELETE' }); dirty = false; location.reload(); }
        catch (error) { notify(error.message, true); event.target.disabled = false; }
    });
    window.addEventListener('pagehide', () => {
        if (uploadingChunk) fetch(`/chunks/${uploadingChunk}`, { method: 'DELETE', keepalive: true, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token } }).catch(() => {});
    });
    window.addEventListener('beforeunload', event => { if (dirty || saving || loading) { event.preventDefault(); event.returnValue = ''; } });
    if (config.latest_url) setInterval(async () => {
        if (active || loading || dialog.open || document.hidden) return;
        try { const result = await request(config.latest_url); if ((result.chunk?.id || '') !== workspace.dataset.latest) location.reload(); }
        catch { /* Keep the existing workspace visible during connectivity interruptions. */ }
    }, 8000);
}
