// Previews are served with no-store, so a fresh <img> would download again.
// Moving the already-decoded elements into the refreshed markup shows them instantly.
export function keepLoadedImages(current, next) {
    const loaded = new Map();
    for (const image of current.querySelectorAll('img[src]')) {
        if (image.complete && image.naturalWidth && !loaded.has(image.getAttribute('src'))) loaded.set(image.getAttribute('src'), image);
    }
    for (const image of next.querySelectorAll('img[src]')) {
        const kept = loaded.get(image.getAttribute('src'));
        if (!kept) continue;
        loaded.delete(image.getAttribute('src'));
        for (const { name, value } of image.attributes) kept.setAttribute(name, value);
        image.replaceWith(kept);
    }
}
