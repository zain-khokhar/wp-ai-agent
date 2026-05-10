const R = window.bosscodeAI?.restUrl || '';
const N = window.bosscodeAI?.nonce || '';

export async function fetchApi(endpoint, options = {}) {
    const url = `${R}${endpoint}`;
    const headers = {
        'X-WP-Nonce': N,
        ...options.headers
    };
    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
        options.body = JSON.stringify(options.body);
        headers['Content-Type'] = 'application/json';
    }

    const res = await fetch(url, { ...options, headers });
    if (!res.ok) {
        let errStr = `Error ${res.status}`;
        try {
            const errData = await res.json();
            errStr = errData.message || errStr;
        } catch (e) {}
        throw new Error(errStr);
    }
    return res.json();
}
