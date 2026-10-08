const previews = new Map();
const previewByteBudget = 8 * 1024 * 1024;
const previewSizes = new Map();
let previewBytes = 0;

export function recordingFirstFrame(source) {
    if (previews.has(source)) { const cached = previews.get(source); previews.delete(source); previews.set(source, cached); return cached; }

    const preview = new Promise(resolve => {
        const video = document.createElement('video');
        let finished = false;
        const finish = image => {
            if (finished) return;
            finished = true;
            clearTimeout(timeout);
            video.onloadeddata = null;
            video.onerror = null;
            video.removeAttribute('src');
            video.load();
            resolve(image);
        };
        const timeout = setTimeout(() => finish(null), 15000);
        video.muted = true;
        video.playsInline = true;
        video.preload = 'auto';
        video.onloadeddata = () => {
            try {
                if (!video.videoWidth || !video.videoHeight) { finish(null); return; }
                const canvas = document.createElement('canvas');
                const scale = Math.min(1, 960 / Math.max(video.videoWidth, video.videoHeight));
                canvas.width = Math.max(1, Math.round(video.videoWidth * scale));
                canvas.height = Math.max(1, Math.round(video.videoHeight * scale));
                canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                finish(canvas.toDataURL('image/jpeg', .85));
            } catch { finish(null); }
        };
        video.onerror = () => finish(null);
        video.src = source;
        video.load();
    });
    previews.set(source, preview);
    preview.then(image => {
        if (previews.get(source) !== preview) return;
        if (!image) { previews.delete(source); return; }
        const bytes = image.length * 2;
        previewSizes.set(source, bytes); previewBytes += bytes;
        for (const key of previews.keys()) {
            if (previewBytes <= previewByteBudget) break;
            const size = previewSizes.get(key);
            if (size === undefined) continue;
            previews.delete(key); previewSizes.delete(key); previewBytes -= size;
        }
    });
    return preview;
}

export async function setRecordingPoster(video, source, isCurrent) {
    const poster = await recordingFirstFrame(source);
    if (poster && isCurrent()) video.poster = poster;
}

export function enhanceRecordingPreviews(root = document) {
    const showPreview = async element => {
        const image = await recordingFirstFrame(element.dataset.recordingPreview);
        if (!image || !element.isConnected) return;
        const frame = new Image();
        frame.alt = element.dataset.recordingFrameAlt ?? '';
        frame.src = image;
        element.prepend(frame);
        element.classList.add('has-recording-frame');
    };
    const elements = root.querySelectorAll('[data-recording-preview]');
    if (!('IntersectionObserver' in window)) {
        elements.forEach(showPreview);
        return;
    }
    const observer = new IntersectionObserver(entries => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            observer.unobserve(entry.target);
            showPreview(entry.target);
        }
    }, { rootMargin: '160px' });
    elements.forEach(element => observer.observe(element));
}
