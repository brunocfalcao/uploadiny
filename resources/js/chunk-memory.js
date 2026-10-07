export const LAST_VIEWED_KEY = 'uploadiny.lastViewedFile';
const LIMIT = 500;

function readMap(storage) {
    try {
        const map = JSON.parse(storage?.getItem(LAST_VIEWED_KEY) ?? '{}');
        return map && typeof map === 'object' && !Array.isArray(map) ? map : {};
    } catch { return {}; }
}

export function chunkStartFile(storage, chunk, files) {
    const remembered = readMap(storage)[chunk];
    return files.includes(remembered) ? remembered : files[0];
}

export function rememberChunkFile(storage, chunk, file) {
    if (!chunk || !file) return;
    const map = readMap(storage);
    delete map[chunk];
    map[chunk] = file;
    const keys = Object.keys(map);
    for (const old of keys.slice(0, Math.max(0, keys.length - LIMIT))) delete map[old];
    try { storage?.setItem(LAST_VIEWED_KEY, JSON.stringify(map)); } catch { /* Private mode: the position just is not remembered. */ }
}
