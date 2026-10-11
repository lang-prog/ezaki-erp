function readCookie(name) {
    if (typeof document === 'undefined') return '';

    const prefix = `${name}=`;
    const item = document.cookie.split('; ').find((value) => value.startsWith(prefix));

    return item ? decodeURIComponent(item.slice(prefix.length)) : '';
}

export function csrfHeaders() {
    const cookieToken = readCookie('XSRF-TOKEN');
    const metaToken = typeof document === 'undefined' ? '' : document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const token = cookieToken || metaToken;

    return token ? { 'X-CSRF-TOKEN': token } : {};
}

export function csrfFetch(url, options = {}) {
    const headers = new Headers(options.headers ?? {});

    Object.entries(csrfHeaders()).forEach(([name, value]) => headers.set(name, value));

    return fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers,
    });
}
