async function requestBody(url, options = {}, token = '', type = 'json') {
    const { signal, timeoutMs = 30000, ...settings } = options;
    const controller = new AbortController();
    const cancel = () => controller.abort();
    signal?.addEventListener('abort', cancel, { once: true });
    if (signal?.aborted) cancel();
    const deadline = setTimeout(cancel, timeoutMs);
    try {
        const response = await fetch(url, {
            ...settings,
            signal: controller.signal,
            headers: { Accept: type === 'json' ? 'application/json' : 'text/html', 'X-CSRF-TOKEN': token, ...(type === 'json' && !(settings.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}), ...settings.headers },
        });
        let payload;
        try { payload = await response[type](); }
        catch (error) {
            if (controller.signal.aborted) throw error;
            throw new Error('The server did not return a valid response. Reload and try again.');
        }
        if (!response.ok) {
            const error = new Error(type === 'json' ? Object.values(payload.errors || {}).flat()[0] || payload.message || 'The request failed. Try again.' : 'The request failed. Try again.');
            error.status = response.status;
            throw error;
        }
        return payload;
    } catch (error) {
        if (controller.signal.aborted) throw new Error('Request timed out or was cancelled. Check your project before retrying.');
        throw error;
    } finally {
        clearTimeout(deadline);
        signal?.removeEventListener('abort', cancel);
    }
}

export async function requestJson(url, options = {}, token = '') {
    return requestBody(url, options, token, 'json');
}

export async function requestText(url, options = {}, token = '') {
    return requestBody(url, options, token, 'text');
}
