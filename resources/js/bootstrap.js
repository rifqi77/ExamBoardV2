import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// ── CSRF: attach the double-submit token to same-origin mutating requests ──
// Auth is a cookie, so every POST/PUT/PATCH/DELETE must echo the readable
// `eb_csrf` cookie back in a header. Patching window.fetch once covers every
// call site without touching them individually (mirrors Axios's interceptor).
const CSRF_COOKIE = 'eb_csrf';
const CSRF_HEADER = 'X-EB-Csrf';
const SAFE = ['GET', 'HEAD', 'OPTIONS'];

function readCookie(name) {
    const escaped = name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1');
    const match = document.cookie.match(new RegExp('(?:^|;\\s*)' + escaped + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
}

function isSameOrigin(url) {
    try {
        const u = new URL(url, window.location.origin);
        return u.origin === window.location.origin;
    } catch {
        return true; // relative URLs resolve to same origin
    }
}

// Tell Axios which cookie/header to use for its own requests too.
window.axios.defaults.xsrfCookieName = CSRF_COOKIE;
window.axios.defaults.xsrfHeaderName = CSRF_HEADER;

const nativeFetch = window.fetch.bind(window);
window.fetch = (input, init = {}) => {
    const url = typeof input === 'string' ? input : (input && input.url) || '';
    const method = (init.method || (typeof input === 'object' && input && input.method) || 'GET').toUpperCase();

    if (!SAFE.includes(method) && isSameOrigin(url)) {
        const token = readCookie(CSRF_COOKIE);
        const headers = new Headers(init.headers || (typeof input === 'object' && input ? input.headers : undefined) || {});
        if (token && !headers.has(CSRF_HEADER)) {
            headers.set(CSRF_HEADER, token);
        }
        init = { credentials: 'same-origin', ...init, headers };
    }

    return nativeFetch(input, init);
};
