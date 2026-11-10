/**
 * Fetch wrapper that automatically includes CSRF token and credentials.
 */
export async function apiFetch(url, options = {}) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    const headers = {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
        ...(options.headers || {}),
    };

    // Let the browser set the multipart boundary for file uploads.
    if (! (options.body instanceof FormData) && ! headers['Content-Type']) {
        headers['Content-Type'] = 'application/json';
    }

    return fetch(url, {
        ...options,
        headers,
        credentials: 'same-origin',
    });
}
