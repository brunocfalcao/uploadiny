const previews = new Map();

export function recordingFirstFrame(source) {
    if (previews.has(source)) return previews.get(source);

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
