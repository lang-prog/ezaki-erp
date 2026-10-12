function rawCookie(name) {
    if (typeof document === 'undefined') return '';

    const prefix = `${name}=`;
    const item = document.cookie.split('; ').find((value) => value.startsWith(prefix));

    return item ? item.slice(prefix.length) : '';
}

function decoded(value) {
    try {
        return decodeURIComponent(value);
    } catch {
        return value;
    }
}

export function csrfHeaders() {
    const rawCookieToken = rawCookie('XSRF-TOKEN');
    const cookieToken = decoded(rawCookieToken);
    const metaToken = typeof document === 'undefined' ? '' : document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    // Laravel may encrypt the XSRF-TOKEN cookie. In that case the server
    // expects the original encoded value in X-XSRF-TOKEN, not the decrypted
    // value in X-CSRF-TOKEN.
    if (rawCookieToken && cookieToken.startsWith('eyJpdiI6')) {
        return { 'X-XSRF-TOKEN': rawCookieToken };
    }

    return cookieToken || metaToken ? { 'X-CSRF-TOKEN': cookieToken || metaToken } : {};
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
