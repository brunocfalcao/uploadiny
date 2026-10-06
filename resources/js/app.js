import './bootstrap';
import { enhanceAgentAccess } from './agent-access';
import { clipboardFiles } from './clipboard-files';
enhanceAgentAccess();
import { enhanceProjectSelect } from './select';
enhanceProjectSelect(document.getElementById('move-project'));
import {
    annotationContainsPoint,
    commitDrawingHistory,
    drawAnnotation,
    drawSelection,
    normalizedStrokeWidth,
    positionOnCanvas,
    redoDrawingHistory,
    resetDrawingHistory,
    restoreCancelledErase,
    undoDrawingHistory,
} from './drawing';
import { createCalloutEditor } from './callout-editor';
import { drawingShortcutAction } from './drawing-shortcuts';
import { enhanceRecordingPreviews, setRecordingPoster } from './recording-previews';

const workspace = document.querySelector('[data-workspace]');
if (workspace) {
    enhanceRecordingPreviews(workspace);
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
    let selectedIndex = null;
    let eraseStart = null;
    let drawingPointer = null;
    let zoom = 1;
    let fitView = true;
    let dirty = false;
    let dirtyVersion = 0;
    let autosaveTimer = null;
    let savePromise = null;
    let saving = false;
    let navigating = false;
    let transferring = false;
    let chunkGroups = readChunkGroups();
    function readChunkGroups() { return [...document.querySelectorAll('[data-chunk-images]')].map(button => JSON.parse(button.dataset.chunkImages)); }
    let loading = false;
    let uploadingChunk = null;
    let pollTimer = null;
    let loadGeneration = 0;
    const canvas = document.getElementById('annotation-canvas');
    const ctx = canvas.getContext('2d');
    const video = document.getElementById('recording-player');
    const playRecordingButton = document.getElementById('recording-play');
    function syncRecordingControls() {
        const playing = !video.paused && !video.ended;
        document.getElementById('recording-play-label').textContent = playing ? 'Pause recording' : video.ended ? 'Replay recording' : 'Play recording';
        document.getElementById('recording-play-icon').toggleAttribute('hidden', playing);
        document.getElementById('recording-pause-icon').toggleAttribute('hidden', !playing);
        playRecordingButton.disabled = Boolean(video.error);
    }
    for (const event of ['play', 'pause', 'ended', 'emptied', 'loadedmetadata']) video.addEventListener(event, syncRecordingControls);
    video.addEventListener('error', () => { if (active?.media_type === 'video') document.getElementById('video-load-error').hidden = false; syncRecordingControls(); });
    playRecordingButton.addEventListener('click', async () => {
        if (active?.media_type !== 'video') return;
        if (!video.paused && !video.ended) { video.pause(); return; }
        try { await video.play(); }
        catch { notify('Playback could not start. Try the video controls or download the original.', true); }
    });
    const stage = document.getElementById('canvas-stage');
    const ink = document.getElementById('drawing-color');
    const hexField = document.getElementById('drawing-color-hex');
    const gradient = document.getElementById('color-gradient');
    const gradientThumb = document.getElementById('color-gradient-thumb');
    let gradientPick = null;
    const thickness = document.getElementById('drawing-width');
    const message = document.getElementById('workspace-message');
    const comments = document.getElementById('image-comments');
    const saveState = document.getElementById('save-state');
    const calloutEditor = createCalloutEditor({
        canvas, overlay: document.getElementById('callout-overlay'),
        point: event => positionOnCanvas(event, canvas),
        getStrokes: () => strokes, editable: () => Boolean(source) && !saving && !navigating && !transferring,
        color: () => ink.value, width: () => normalizedStrokeWidth(thickness.value, canvas.width),
        onSelect: stroke => { ink.value = stroke.color; thickness.value = Math.round(stroke.width * canvas.width); updateInkControls(); },
        replace: (next, changed = false) => { strokes = next; if (changed) setDirty(); repaint(); },
        commit: (before, next) => { ({ strokes, undo, redo } = commitDrawingHistory({ strokes: before, undo, redo }, next)); setDirty(); repaint(); },
    });

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
        if (!config.project || active || event.target?.closest?.('input,textarea,[contenteditable]')) return;
        const files = clipboardFiles(event.clipboardData);
        if (!files.length) return;
        event.preventDefault();
        upload(files);
    });
    async function upload(files) {
        if (!files.length || loading) return;
        if (files.some(file => /\.(heic|heif|tiff?)$/i.test(file.name))) { notify('Use JPEG, PNG, WebP, GIF or BMP here. The iPhone share button converts HEIC photos automatically.', true); return; }
        const oversized = files.find(file => file.size > 95 * 1024 * 1024);
        if (oversized) { notify(`${oversized.name} is larger than 95 MB. Trim or compress it before uploading.`, true); return; }
        if (active) { calloutEditor.finishText(); if (dirty && !await saveFeedback()) return; if (!leaveEditor()) return; }
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
                            reject(new Error(Object.values(payload.errors || {}).flat()[0] || payload.message || `File upload failed (${xhr.status}).`));
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

    function currentChunk() { return chunkGroups.find(files => files.includes(active?.id)) || (active ? [active.id] : []); }
    function updateChunkNavigation() {
        const files = currentChunk(); const index = files.indexOf(active?.id); const busy = saving || navigating || transferring;
        document.getElementById('chunk-position').textContent = files.length ? `${index + 1} of ${files.length}` : '';
        document.getElementById('first-file').disabled = busy || index <= 0;
        document.getElementById('previous-file').disabled = busy || index <= 0;
        document.getElementById('next-file').disabled = busy || index < 0 || index >= files.length - 1;
        document.getElementById('last-file').disabled = busy || index < 0 || index >= files.length - 1;
        document.getElementById('duplicate-image').disabled = busy || !active || active.media_type === 'video';
    }
    for (const [id, offset] of [['first-file', 'first'], ['previous-file', -1], ['next-file', 1], ['last-file', 'last']]) {
        document.getElementById(id).addEventListener('click', () => {
            const files = currentChunk(); const index = files.indexOf(active?.id);
            const next = offset === 'first' ? 0 : offset === 'last' ? files.length - 1 : index + offset;
            if (next >= 0 && next < files.length) openImage(files[next]);
        });
    }
    document.getElementById('duplicate-image').addEventListener('click', async () => {
        if (!active || active.media_type === 'video' || saving || navigating || transferring || draft || eraseStart || calloutEditor.busy()) return;
        calloutEditor.finishText();
        if (dirty && !await saveFeedback()) return;
        const id = active.id;
        transferring = true; comments.disabled = true; updateChunkNavigation();
        let copy;
        try {
            copy = await request(`/images/${id}/duplicate`, { method: 'POST' });
            const card = [...document.querySelectorAll('[data-chunk-images]')].find(button => JSON.parse(button.dataset.chunkImages).includes(id));
            if (card) card.dataset.chunkImages = JSON.stringify([...JSON.parse(card.dataset.chunkImages), copy.id]);
            chunkGroups = readChunkGroups();
        } catch (error) { notify(error.message, true); }
        finally { transferring = false; comments.disabled = false; updateChunkNavigation(); }
        if (!copy) return;
        await openImage(copy.id);
        if (active?.id === copy.id) notify("Duplicated — you're editing the copy.");
    });
    function finishNavigation() { navigating = false; comments.disabled = false; updateChunkNavigation(); }
    function scheduleAutosave() {
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(() => {
            if (!active || !dirty) return;
            if (draft || eraseStart || calloutEditor.busy() || saving || navigating || transferring) { scheduleAutosave(); return; }
            saveFeedback(true);
        }, 700);
    }
    function setDirty() { dirty = true; dirtyVersion++; saveState.textContent = 'Unsaved changes'; scheduleAutosave(); }
    function repaint(showSelection = true) {
        if (!source) return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
        [...strokes, ...(draft ? [draft] : [])].forEach(stroke => drawAnnotation(ctx, stroke, canvas.width, canvas.height));
        if (showSelection && strokes[selectedIndex]) drawSelection(ctx, strokes[selectedIndex], canvas.width, canvas.height);
        document.getElementById('delete-mark').disabled = saving || !strokes[selectedIndex];
        document.getElementById('undo-drawing').disabled = saving || !undo.length;
        document.getElementById('redo-drawing').disabled = saving || !redo.length;
        document.getElementById('clear-drawing').disabled = saving || !strokes.length;
        calloutEditor.render();
    }
    function syncImageUrl(id, mode = 'replace') {
        const params = new URLSearchParams(location.search);
        if (id) params.set('image', id); else params.delete('image');
        const query = params.toString();
        const url = `${location.pathname}${query ? `?${query}` : ''}${location.hash}`;
        if (url === `${location.pathname}${location.search}${location.hash}`) return;
        history[mode === 'push' ? 'pushState' : 'replaceState'](null, '', url);
    }
    function leaveEditor(invalidate = true) {
        if (saving) { notify('Wait for your feedback to finish saving.'); return false; }
        calloutEditor.finishText();
        if (dirty && !confirm('Leave without saving your feedback?')) return false;
        clearTimeout(autosaveTimer);
        clearTimeout(pollTimer);
        if (invalidate) loadGeneration++;
        calloutEditor.reset(); selectedIndex = null;
        video.pause(); video.removeAttribute('src'); video.removeAttribute('poster'); video.load();
        active = null; source = null; dirty = false;
        document.getElementById('editor').hidden = true;
        document.getElementById('gallery').hidden = false;
        if (invalidate) syncImageUrl(null);
        return true;
    }
    async function closeEditor() {
        if (saving || navigating || transferring || draft || eraseStart || calloutEditor.busy()) return;
        calloutEditor.finishText();
        if (dirty && !await saveFeedback()) return;
        if (!leaveEditor()) return;
        const announced = arrivalAnnounced; arrivalAnnounced = null;
        const outcome = await refreshGallery(true);
        if (outcome === 'failed' || outcome === 'stale') location.reload();
        else if (announced) notifyTransient('New upload arrived.');
    }
    document.getElementById('close-editor').addEventListener('click', closeEditor);
    window.addEventListener('popstate', async () => {
        const id = new URLSearchParams(location.search).get('image');
        if (!id) {
            if (!active) return;
            await closeEditor();
            if (active) syncImageUrl(active.id);
            return;
        }
        if (active?.id === id || !chunkGroups.some(files => files.includes(id))) return;
        await openImage(id, false, true);
        if (active && active.id !== id) syncImageUrl(active.id);
    });
    const boundTiles = new WeakSet();
    function bindGallery() {
        document.querySelectorAll('[data-open-image]').forEach(button => {
            if (boundTiles.has(button)) return;
            boundTiles.add(button);
            button.addEventListener('click', () => openImage(button.dataset.openImage, button.dataset.playRecording === 'true'));
        });
    }
    bindGallery();
    const initialImage = new URLSearchParams(location.search).get('image');
    if (initialImage) {
        if (chunkGroups.some(files => files.includes(initialImage))) openImage(initialImage, false, true).then(() => { if (active?.id !== initialImage) leaveEditor(); });
        else leaveEditor();
    }
    async function openImage(id, playRecording = false, fromUrl = false) {
        if (saving || navigating || transferring || active?.id === id) return;
        if (draft || eraseStart || calloutEditor.busy()) { notify('Finish your current drawing before switching files.'); return; }
        calloutEditor.finishText();
        if (dirty && !await saveFeedback()) return;
        navigating = true; comments.disabled = true; updateChunkNavigation();
        document.getElementById('save-feedback').disabled = true;
        const generation = ++loadGeneration;
        try {
            const data = await request(`/images/${id}`);
            if (generation !== loadGeneration) return;
            const fromGallery = !active;
            if (active && !leaveEditor(false)) { finishNavigation(); return; }
            calloutEditor.reset(); selectedIndex = null;
            active = data; dirty = false; source = null;
            syncImageUrl(data.id, fromGallery && !fromUrl ? 'push' : 'replace');
            loadChunkDestinations(data.id);
            ({ strokes, undo, redo } = resetDrawingHistory(data.annotations)); draft = null; eraseStart = null; zoom = 1; fitView = true;
            comments.value = data.comments;
            saveState.textContent = '';
            document.getElementById('editor-name').textContent = data.name;
            document.getElementById('download-original').href = `/images/${id}/download`;
            document.getElementById('duplicate-image').hidden = data.media_type === 'video';
            document.getElementById('move-project').value = config.project.id;
            document.getElementById('move-project').dispatchEvent(new Event('change'));
            document.getElementById('gallery').hidden = true;
            document.getElementById('editor').hidden = false;
            document.getElementById('save-feedback').disabled = true;
            const imageError = document.getElementById('image-load-error');
            imageError.hidden = true;
            canvas.hidden = true;
            const recording = data.media_type === 'video';
            document.getElementById('drawing-workspace').hidden = recording;
            document.getElementById('video-workspace').hidden = !recording;
            document.getElementById('vision-section').hidden = recording;
            document.getElementById('video-load-error').hidden = true;
            if (recording) {
                video.removeAttribute('poster');
                setRecordingPoster(video, data.preview_url, () => generation === loadGeneration && active?.id === id);
                video.src = data.preview_url; video.load(); syncRecordingControls();
                if (playRecording) video.play().catch(() => { /* Browsers may require another tap on Play. */ });
                document.getElementById('save-feedback').disabled = false;
                finishNavigation(); document.getElementById('editor-name').focus({ preventScroll: true });
                return;
            }
            updateChunkNavigation(); showDescription(data);
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
                finishNavigation(); sizeCanvas();
                repaint();
            };
            image.onerror = () => {
                if (generation !== loadGeneration) return;
                imageError.textContent = 'This browser cannot preview this format. Download the original to inspect it. Written feedback can still be saved.';
                imageError.hidden = false;
                finishNavigation(); document.getElementById('save-feedback').disabled = false;
            };
            image.src = data.preview_url;
            document.getElementById('editor-name').focus({ preventScroll: true });
        } catch (error) { finishNavigation(); document.getElementById('save-feedback').disabled = !active; notify(error.message, true); }
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
    const toolHints = { select: 'Select: click a mark, then press Delete to remove it.', callout: 'Annotation: click to add a callout. Drag its handles to resize; drag the rectangle or Move note to reposition.', pen: 'Pen: draw freely on the image.', arrow: 'Arrow: drag to point at a detail.', line: 'Line: drag to draw a straight line.', rectangle: 'Rectangle: drag around an area.', ellipse: 'Ellipse: drag to circle an area.', eraser: 'Eraser: drag over a mark to remove it. Undo restores it.' };
    document.querySelectorAll('[data-tool]').forEach(button => button.addEventListener('click', () => {
        if (draft || eraseStart || calloutEditor.busy()) return;
        calloutEditor.reset(); selectedIndex = null;
        tool = button.dataset.tool;
        canvas.dataset.tool = tool;
        document.getElementById('canvas-tool-hint').textContent = toolHints[tool];
        document.querySelectorAll('[data-tool]').forEach(entry => { entry.classList.toggle('active', entry === button); entry.setAttribute('aria-pressed', entry === button ? 'true' : 'false'); });
        repaint();
    }));
    function updateInkControls() {
        const preview = document.getElementById('stroke-preview');
        preview.style.width = `${thickness.value}px`;
        preview.style.height = `${thickness.value}px`;
        preview.style.backgroundColor = ink.value;
        document.getElementById('drawing-width-value').textContent = `${thickness.value} px`;
        const swatches = [...document.querySelectorAll('[data-color]')];
        const preset = swatches.some(button => button.dataset.color === ink.value);
        swatches.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.color === ink.value)));
        const custom = document.querySelector('.custom-color');
        custom.style.setProperty('--custom', ink.value);
        custom.classList.toggle('selected', !preset);
        if (document.activeElement !== hexField) hexField.value = ink.value;
        const picked = gradientPick && gradientPick.color === ink.value ? gradientPick : null;
        gradientThumb.hidden = !picked;
        if (picked) { gradientThumb.style.left = `${picked.x * 100}%`; gradientThumb.style.top = `${picked.y * 100}%`; }
        gradient.setAttribute('aria-valuenow', String(Math.round((picked?.x ?? 0) * 360)));
        gradient.setAttribute('aria-valuetext', picked ? ink.value : 'No colour picked from the gradient');
    }
    function gradientColor(x, y) {
        const lightness = 1 - y; const chroma = 1 - Math.abs(2 * lightness - 1); const hue = x * 6 % 6; const second = chroma * (1 - Math.abs(hue % 2 - 1)); const base = lightness - chroma / 2;
        const [r, g, b] = [[chroma, second, 0], [second, chroma, 0], [0, chroma, second], [0, second, chroma], [second, 0, chroma], [chroma, 0, second]][Math.floor(hue) % 6];
        return `#${[r, g, b].map(channel => Math.round((channel + base) * 255).toString(16).padStart(2, '0')).join('')}`;
    }
    function inkGradientPosition() {
        if (gradientPick && gradientPick.color === ink.value) return gradientPick;
        const [r, g, b] = [1, 3, 5].map(index => parseInt(ink.value.slice(index, index + 2), 16) / 255);
        const high = Math.max(r, g, b); const low = Math.min(r, g, b); const delta = high - low;
        const hue = delta === 0 ? 0 : high === r ? ((g - b) / delta + 6) % 6 : high === g ? (b - r) / delta + 2 : (r - g) / delta + 4;
        return { x: hue / 6, y: 1 - (high + low) / 2 };
    }
    function pickGradient(x, y, commit = true) {
        x = Math.min(1, Math.max(0, x)); y = Math.min(1, Math.max(0, y));
        const color = gradientColor(x, y); gradientPick = { x, y, color }; applyInk(color, commit);
    }
    function applyInk(color, commit = true) {
        ink.value = color;
        updateInkControls();
        if (commit) calloutEditor.changeStyle(ink.value, normalizedStrokeWidth(thickness.value, canvas.width));
    }
    function parseHex(text) {
        const match = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(text.trim());
        if (!match) return null;
        const digits = match[1].length === 3 ? [...match[1]].map(digit => digit + digit).join('') : match[1];
        return `#${digits.toLowerCase()}`;
    }
    ink.addEventListener('input', () => applyInk(ink.value, false));
    ink.addEventListener('change', () => applyInk(ink.value));
    hexField.addEventListener('change', () => { const color = parseHex(hexField.value); if (color) applyInk(color); });
    hexField.addEventListener('keydown', event => { if (event.key !== 'Enter' || event.metaKey || event.ctrlKey) return; event.preventDefault(); const color = parseHex(hexField.value); if (color) applyInk(color); });
    let gradientPointer = null;
    function pickFromPointer(event, commit = false) { const box = gradient.getBoundingClientRect(); pickGradient((event.clientX - box.left) / box.width, (event.clientY - box.top) / box.height, commit); }
    gradient.addEventListener('pointerdown', event => { if (event.button !== 0) return; event.preventDefault(); gradientPointer = event.pointerId; gradient.setPointerCapture(event.pointerId); gradient.focus(); pickFromPointer(event); });
    gradient.addEventListener('pointermove', event => { if (event.pointerId === gradientPointer) pickFromPointer(event); });
    for (const type of ['pointerup', 'pointercancel']) gradient.addEventListener(type, event => { if (event.pointerId !== gradientPointer) return; gradientPointer = null; applyInk(ink.value); });
    gradient.addEventListener('keydown', event => {
        const moves = { ArrowLeft: [-1 / 72, 0], ArrowRight: [1 / 72, 0], ArrowUp: [0, -0.04], ArrowDown: [0, 0.04] };
        if (!moves[event.key]) return;
        event.preventDefault(); event.stopPropagation();
        if (event.metaKey || event.ctrlKey || event.altKey) return;
        const start = inkGradientPosition(); const [dx, dy] = moves[event.key];
        pickGradient(((start.x + dx) % 1 + 1) % 1, start.y + dy);
    });
    hexField.addEventListener('blur', () => { hexField.value = ink.value; });
    thickness.addEventListener('input', () => { updateInkControls(); calloutEditor.changeStyle(ink.value, normalizedStrokeWidth(thickness.value, canvas.width)); });
    document.querySelectorAll('[data-color]').forEach(button => button.addEventListener('click', () => applyInk(button.dataset.color)));
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
        calloutEditor.render();
    }
    function changeZoom(next, anchor = null) {
        if (!source || draft || eraseStart || calloutEditor.busy()) return;
        const before = canvas.getBoundingClientRect();
        const centerX = stage.scrollLeft + stage.clientWidth / 2;
        const centerY = stage.scrollTop + stage.clientHeight / 2;
        fitView = false;
        zoom = Math.max(.1, Math.min(4, next));
        sizeCanvas();
        const after = canvas.getBoundingClientRect();
        if (anchor && before.width > 0 && before.height > 0) {
            const fractionX = (anchor.x - before.left) / before.width;
            const fractionY = (anchor.y - before.top) / before.height;
            stage.scrollLeft = Math.max(0, stage.scrollLeft + after.left + fractionX * after.width - anchor.x);
            stage.scrollTop = Math.max(0, stage.scrollTop + after.top + fractionY * after.height - anchor.y);
            return;
        }
        const ratio = after.width / before.width;
        stage.scrollLeft = centerX * ratio - stage.clientWidth / 2;
        stage.scrollTop = centerY * ratio - stage.clientHeight / 2;
    }
    document.getElementById('zoom-out').addEventListener('click', () => changeZoom(zoom / 1.25));
    document.getElementById('zoom-in').addEventListener('click', () => changeZoom(zoom * 1.25));
    document.getElementById('zoom-fit').addEventListener('click', () => { if (!source || draft || eraseStart || calloutEditor.busy()) return; fitView = true; sizeCanvas(); stage.scrollTop = 0; stage.scrollLeft = 0; });
    new ResizeObserver(sizeCanvas).observe(stage);

    stage.addEventListener('wheel', event => {
        if (!source || !event.deltaY) return;
        if (saving || navigating || transferring || draft || eraseStart || calloutEditor.busy() || panning) { if (event.ctrlKey) event.preventDefault(); return; }
        event.preventDefault();
        const lines = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? 400 : 1;
        const delta = Math.max(-100, Math.min(100, event.deltaY * lines));
        changeZoom(zoom * Math.exp(-delta * (event.ctrlKey ? .01 : .0025)), { x: event.clientX, y: event.clientY });
    }, { passive: false });

    const panHint = 'Hold Space and drag to move · scroll to zoom';
    const hintElement = document.getElementById('canvas-tool-hint');
    let panMode = false; let panning = null; let hintBeforePan = '';
    function setPanMode(on) {
        if (panMode === on) return;
        panMode = on;
        stage.classList.toggle('pan-mode', on);
        if (on) { hintBeforePan = hintElement.textContent; hintElement.textContent = panHint; }
        else { panning = null; stage.classList.toggle('panning', false); if (hintElement.textContent === panHint) hintElement.textContent = hintBeforePan; }
    }
    function startPan(event) {
        if (panning || event.button !== 0 || saving || navigating || transferring) return;
        event.preventDefault(); event.stopPropagation();
        try { stage.setPointerCapture(event.pointerId); } catch { return; }
        panning = { pointerId: event.pointerId, x: event.clientX, y: event.clientY, left: stage.scrollLeft || 0, top: stage.scrollTop || 0 };
        stage.classList.toggle('panning', true);
    }
    function endPan(event) { if (!panning || event.pointerId !== panning.pointerId) return; panning = null; stage.classList.toggle('panning', false); }
    stage.addEventListener('pointerdown', event => { if (panMode && source) startPan(event); }, true);
    stage.addEventListener('pointermove', event => {
        if (!panning || event.pointerId !== panning.pointerId) return;
        stage.scrollLeft = Math.max(0, panning.left - (event.clientX - panning.x));
        stage.scrollTop = Math.max(0, panning.top - (event.clientY - panning.y));
    });
    stage.addEventListener('pointerup', endPan);
    stage.addEventListener('pointercancel', endPan);
    document.addEventListener('keydown', event => {
        if (event.key !== ' ' || event.metaKey || event.ctrlKey || event.altKey || event.isComposing) return;
        if (!active || !source || dialog.open || draft || eraseStart || calloutEditor.busy()) return;
        if (event.target?.closest?.('input,textarea,select,[contenteditable],[role=combobox],.callout-text')) return;
        event.preventDefault();
        setPanMode(true);
    });
    document.addEventListener('keyup', event => { if (event.key !== ' ' || !panMode) return; event.preventDefault(); setPanMode(false); });
    window.addEventListener('blur', () => setPanMode(false));

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
        if (!source || saving || navigating || transferring || draft || eraseStart || calloutEditor.busy() || event.button !== 0) return;
        event.preventDefault();
        if (panMode) return;
        if (tool === 'callout') { calloutEditor.place(event); return; }
        if (tool === 'select') {
            const point = positionOnCanvas(event, canvas);
            const tolerance = 10 * canvas.width / canvas.getBoundingClientRect().width;
            const index = strokes.findLastIndex(stroke => annotationContainsPoint(stroke, point, canvas.width, canvas.height, tolerance));
            selectedIndex = index < 0 ? null : index; repaint(); return;
        }
        canvas.setPointerCapture(event.pointerId);
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
    document.getElementById('undo-drawing').addEventListener('click', () => { if (calloutEditor.busy()) return; calloutEditor.reset(); selectedIndex = null; if (!saving && !navigating && !transferring && !draft && !eraseStart && undo.length) { ({ strokes, undo, redo } = undoDrawingHistory({ strokes, undo, redo })); setDirty(); repaint(); } });
    document.getElementById('redo-drawing').addEventListener('click', () => { if (calloutEditor.busy()) return; calloutEditor.reset(); selectedIndex = null; if (!saving && !navigating && !transferring && !draft && !eraseStart && redo.length) { ({ strokes, undo, redo } = redoDrawingHistory({ strokes, undo, redo })); setDirty(); repaint(); } });
    document.getElementById('clear-drawing').addEventListener('click', () => { if (calloutEditor.busy()) return; calloutEditor.reset(); selectedIndex = null; if (!saving && !navigating && !transferring && !draft && !eraseStart && strokes.length) commitStrokes([]); else repaint(); });
    document.getElementById('delete-mark').addEventListener('click', () => {
        if (calloutEditor.busy()) return; calloutEditor.reset();
        if (saving || navigating || transferring || draft || eraseStart || !strokes[selectedIndex]) return;
        const index = selectedIndex; selectedIndex = null;
        commitStrokes(strokes.filter((_, position) => position !== index));
    });
    document.addEventListener('keydown', event => {
        const action = drawingShortcutAction(event, { active, source, saving: saving || navigating || transferring, draft: draft || eraseStart || calloutEditor.busy(), modalOpen: dialog.open, selected: selectedIndex !== null });
        if (!action) { if (event.key === 'Escape' && selectedIndex !== null && !dialog.open) { selectedIndex = null; repaint(); } return; }
        event.preventDefault();
        if (action === 'undo' || action === 'redo') calloutEditor.finishText();
        document.getElementById(action === 'undo' || action === 'redo' ? `${action}-drawing` : action).click();
    });
    comments.addEventListener('input', setDirty);
    async function saveFeedback(automatic = false) {
        if (savePromise) {
            const saved = await savePromise;
            if (!saved || automatic || !dirty) return saved;
        }
        if (!active || saving || navigating || transferring || draft || eraseStart || calloutEditor.busy()) return false;
        calloutEditor.finishText();
        clearTimeout(autosaveTimer);
        const image = active; const version = dirtyVersion;
        if (strokes.length && source) repaint(false);
        const payload = { comments: comments.value, annotations: strokes, revision: image.revision, annotated_image: strokes.length && source ? canvas.toDataURL('image/png') : null };
        saving = !automatic; updateChunkNavigation();
        repaint();
        const button = document.getElementById('save-feedback'); button.disabled = !automatic;
        comments.disabled = !automatic; saveState.textContent = 'Saving…';
        savePromise = (async () => {
            let saved = false;
            try {
                const data = await request(`/images/${image.id}`, { method: 'PATCH', body: JSON.stringify(payload) });
                image.revision = data.revision;
                dirty = dirtyVersion !== version;
                saveState.textContent = dirty ? 'Unsaved changes' : 'Saved'; saved = true; return true;
            } catch (error) { clearTimeout(autosaveTimer); saveState.textContent = 'Not saved'; notify(error.message, true); return false; }
            finally {
                savePromise = null; saving = false; button.disabled = navigating || transferring; comments.disabled = navigating || transferring; updateChunkNavigation(); repaint();
                if (saved && dirty) scheduleAutosave();
            }
        })();
        return savePromise;
    }
    document.getElementById('save-feedback').addEventListener('click', () => saveFeedback());
    document.getElementById('retry-description').addEventListener('click', async event => {
        if (!active) return;
        const id = active.id;
        event.currentTarget.disabled = true;
        try { const result = await request(`/images/${id}/description`, { method: 'POST' }); if (active?.id === id) showDescription(result); }
        catch (error) { notify(error.message, true); }
        finally { document.getElementById('retry-description').disabled = false; }
    });
    const targetChunk = document.getElementById('target-chunk');
    async function loadChunkDestinations(id) {
        targetChunk.replaceChildren(new Option('Loading upload chunks…', ''));
        document.getElementById('copy-to-chunk').disabled = true;
        document.getElementById('move-to-chunk').disabled = true;
        try {
            const data = await request('/chunks');
            if (active?.id !== id) return;
            const currentCard = [...document.querySelectorAll('[data-chunk-images]')].find(button => JSON.parse(button.dataset.chunkImages).includes(id));
            const choices = data.destinations.filter(chunk => !(chunk.chunk_id === currentCard?.dataset.chunk && chunk.project_id === config.project.id));
            targetChunk.replaceChildren(new Option(choices.length ? 'Choose an upload chunk…' : 'No other chunks yet', ''));
            for (const chunk of choices) {
                targetChunk.append(new Option(`${chunk.project_name} · ${new Date(chunk.uploaded_at).toLocaleString()} · ${chunk.file_count} files`, JSON.stringify({ chunk_id: chunk.chunk_id, project_id: chunk.project_id })));
            }
        } catch { if (active?.id === id) targetChunk.replaceChildren(new Option('Could not load chunks. Reopen the file to retry.', '')); }
    }
    targetChunk.addEventListener('change', () => {
        document.getElementById('copy-to-chunk').disabled = !targetChunk.value;
        document.getElementById('move-to-chunk').disabled = !targetChunk.value;
    });
    for (const action of ['copy', 'move']) document.getElementById(`${action}-to-chunk`).addEventListener('click', async () => {
        if (!active || saving || navigating || transferring || !targetChunk.value || draft || eraseStart || calloutEditor.busy()) return;
        calloutEditor.finishText();
        if (dirty && !await saveFeedback()) return;
        transferring = true; updateChunkNavigation();
        document.getElementById('copy-to-chunk').disabled = true;
        document.getElementById('move-to-chunk').disabled = true;
        comments.disabled = true;
        try {
            const result = await request(`/images/${active.id}/chunk`, { method: 'POST', body: JSON.stringify({ ...JSON.parse(targetChunk.value), action }) });
            dirty = false; transferring = false; syncImageUrl(null); location.href = result.project_url;
        } catch (error) {
            transferring = false; comments.disabled = false; updateChunkNavigation();
            document.getElementById('copy-to-chunk').disabled = !targetChunk.value;
            document.getElementById('move-to-chunk').disabled = !targetChunk.value;
            notify(error.message, true);
        }
    });
    document.getElementById('move-image').addEventListener('click', async event => {
        if (!active || saving || navigating || transferring || draft || eraseStart || calloutEditor.busy()) return;
        calloutEditor.finishText();
        if (dirty && !await saveFeedback()) return;
        transferring = true; comments.disabled = true; updateChunkNavigation();
        event.currentTarget.disabled = true;
        try { const result = await request(`/images/${active.id}/project`, { method: 'PATCH', body: JSON.stringify({ project_id: Number(document.getElementById('move-project').value) }) }); dirty = false; syncImageUrl(null); location.href = result.project_url; }
        catch (error) { notify(error.message, true); event.target.disabled = false; }
        finally { transferring = false; comments.disabled = false; updateChunkNavigation(); }
    });
    document.getElementById('delete-image').addEventListener('click', async event => {
        if (!active || saving || navigating || transferring || !confirm('Permanently delete this file and all of its feedback?')) return;
        transferring = true; comments.disabled = true; clearTimeout(autosaveTimer); updateChunkNavigation();
        event.currentTarget.disabled = true;
        try { if (savePromise) await savePromise; await request(`/images/${active.id}`, { method: 'DELETE' }); dirty = false; clearTimeout(autosaveTimer); syncImageUrl(null); location.reload(); }
        catch (error) { notify(error.message, true); event.target.disabled = false; }
        finally { transferring = false; comments.disabled = false; updateChunkNavigation(); }
    });
    window.addEventListener('pagehide', () => {
        if (uploadingChunk) fetch(`/chunks/${uploadingChunk}`, { method: 'DELETE', keepalive: true, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token } }).catch(() => {});
    });
    window.addEventListener('beforeunload', event => { if (dirty || saving || loading || navigating || transferring) { event.preventDefault(); event.returnValue = ''; } });
    const lastChunkInterval = 8000;
    const arrivalNotice = 'New upload arrived — it will appear when you go back to the project.';
    let refreshSequence = 0;
    let pageVersion = 0;
    let polling = false;
    let arrivalAnnounced = null;
    let noticeTimer = null;
    function notifyTransient(text) {
        notify(text);
        clearTimeout(noticeTimer);
        noticeTimer = setTimeout(() => { if (message.textContent === text) message.hidden = true; }, 6000);
    }
    function galleryBusy() { return Boolean(active) || loading || dialog.open || navigating || transferring || saving; }
    function pageState(chunk) { return [chunk?.id ?? '', chunk?.completed_at ?? '', String(chunk?.file_count ?? '')].join('|'); }
    function shownState() { return [workspace.dataset.latest ?? '', workspace.dataset.latestCompleted ?? '', workspace.dataset.latestCount ?? ''].join('|'); }
    function applyPage(page) {
        const list = page.getElementById('chunk-list'); const next = page.querySelector('[data-workspace]'); const current = document.getElementById('chunk-list');
        if (!list || !next || !current) return false;
        const scroll = window.scrollY;
        current.replaceChildren(...list.childNodes);
        const nav = document.querySelector('.project-nav'); const nextNav = page.querySelector('.project-nav');
        if (nav && nextNav) nav.replaceChildren(...nextNav.childNodes);
        for (const key of ['latest', 'latestCompleted', 'latestCount']) workspace.dataset[key] = next.dataset[key] ?? '';
        chunkGroups = readChunkGroups();
        bindGallery();
        enhanceRecordingPreviews(current);
        if (window.scrollY !== scroll) window.scrollTo?.(0, scroll);
        pageVersion++;
        if (message.textContent === arrivalNotice) message.hidden = true;
        return true;
    }
    async function refreshGallery(force = false) {
        const sequence = ++refreshSequence;
        try {
            const response = await fetch(config.project_url, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
            if (!response.ok) return 'failed';
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (sequence !== refreshSequence) return 'stale';
            if (!force && galleryBusy()) return 'busy';
            return applyPage(page) ? 'applied' : 'failed';
        } catch { return 'failed'; }
    }
    async function pollLastChunk() {
        if (!config.last_chunk_url || polling || document.visibilityState !== 'visible') return;
        polling = true;
        const version = pageVersion;
        try {
            const result = await request(config.last_chunk_url);
            if (version !== pageVersion) return;
            const seen = pageState(result.chunk);
            if (seen === shownState()) return;
            const outcome = galleryBusy() ? 'busy' : await refreshGallery();
            if (outcome === 'applied') { arrivalAnnounced = null; notifyTransient('New upload arrived.'); }
            else if (outcome === 'busy' && arrivalAnnounced !== seen) { arrivalAnnounced = seen; notify(arrivalNotice); }
        } catch { /* Keep the existing workspace visible during connectivity interruptions. */ }
        finally { polling = false; }
    }
    if (config.last_chunk_url) {
        let pollInterval = setInterval(pollLastChunk, lastChunkInterval);
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') pollLastChunk(); });
        window.addEventListener('pagehide', () => clearInterval(pollInterval));
        window.addEventListener('pageshow', event => { if (!event.persisted) return; clearInterval(pollInterval); pollInterval = setInterval(pollLastChunk, lastChunkInterval); pollLastChunk(); });
    }
}
