import assert from 'node:assert/strict';
import test from 'node:test';
import { enhanceRecordingPreviews, recordingFirstFrame, setRecordingPoster } from './recording-previews.js';

async function withFakeMedia(options, callback) {
    const originalDocument = globalThis.document;
    const harness = { canvases: [], videos: [] };

    class FakeVideo {
        constructor() {
            this.videoWidth = options.width ?? 1920;
            this.videoHeight = options.height ?? 1080;
            this.playCalls = 0;
            harness.videos.push(this);
        }

        load() {
            if (!this.src || options.defer) return;
            if (options.error) this.onerror();
            else this.onloadeddata();
        }

        play() {
            this.playCalls++;
        }

        removeAttribute(name) {
            if (name === 'src') this.src = '';
        }
    }

    globalThis.document = {
        createElement(tag) {
            if (tag === 'video') return new FakeVideo();
            const canvas = {
                width: 0,
                height: 0,
                getContext: () => ({ drawImage: () => {} }),
                toDataURL: () => options.dataURL ?? 'data:image/jpeg;base64,first-frame',
            };
            harness.canvases.push(canvas);
            return canvas;
        },
    };

    try {
        return await callback(harness);
    } finally {
        globalThis.document = originalDocument;
    }
}

test('extracts and caches a scaled JPEG first frame without playing the recording', async () => {
    await withFakeMedia({}, async harness => {
        const first = recordingFirstFrame('/private/recording-preview-cache.mov');
        const second = recordingFirstFrame('/private/recording-preview-cache.mov');

        assert.strictEqual(first, second);
        assert.equal(await first, 'data:image/jpeg;base64,first-frame');
        assert.equal(harness.videos.length, 1);
        assert.equal(harness.canvases[0].width, 960);
        assert.equal(harness.canvases[0].height, 540);
        assert.equal(harness.videos[0].playCalls, 0);
    });
});

test('keeps the recording fallback when first-frame extraction fails', async () => {
    await withFakeMedia({ error: true }, async harness => {
        assert.equal(await recordingFirstFrame('/private/unsupported-recording.mov'), null);
        assert.equal(harness.canvases.length, 0);
    });
});

test('does not apply a late first frame after the active recording changes', async () => {
    await withFakeMedia({ defer: true }, async harness => {
        const player = {};
        let stillCurrent = true;
        const applying = setRecordingPoster(player, '/private/stale-recording.mov', () => stillCurrent);

        stillCurrent = false;
        harness.videos[0].onloadeddata();
        await applying;

        assert.equal(player.poster, undefined);
    });
});


test('a long session evicts old frames by retained bytes while keeping recent frames cached', async () => {
    await withFakeMedia({ dataURL: 'data:image/jpeg;base64,' + 'x'.repeat(1024 * 1024) }, async harness => {
        for (let index = 0; index < 8; index++) await recordingFirstFrame(`/budget/${index}.mov`);
        const before = harness.videos.length;
        await recordingFirstFrame('/budget/7.mov');
        assert.equal(harness.videos.length, before);
        await recordingFirstFrame('/budget/0.mov');
        assert.equal(harness.videos.length, before + 1);
    });
});

test('disconnects the previous gallery observer before rebinding previews', () => {
    const originalWindow = globalThis.window;
    const originalIntersectionObserver = globalThis.IntersectionObserver;
    const observers = [];
    class FakeObserver {
        constructor(callback) { this.callback = callback; this.targets = new Set(); this.disconnected = false; observers.push(this); }
        observe(element) { this.targets.add(element); }
        unobserve(element) { this.targets.delete(element); }
        disconnect() { this.disconnected = true; this.targets.clear(); }
    }
    globalThis.window = { IntersectionObserver: FakeObserver };
    globalThis.IntersectionObserver = FakeObserver;
    const first = { dataset: { recordingPreview: '/first.mov' } };
    const second = { dataset: { recordingPreview: '/second.mov' } };
    const firstRoot = { querySelectorAll: () => [first] };
    const secondRoot = { querySelectorAll: () => [second] };

    try {
        enhanceRecordingPreviews(firstRoot);
        enhanceRecordingPreviews(secondRoot);
        assert.equal(observers.length, 2);
        assert.equal(observers[0].disconnected, true);
        assert.equal(observers[0].targets.size, 0);
        assert.deepEqual([...observers[1].targets], [second]);
    } finally {
        globalThis.window = originalWindow;
        globalThis.IntersectionObserver = originalIntersectionObserver;
    }
});
