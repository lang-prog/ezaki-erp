import assert from 'node:assert/strict';
import test from 'node:test';
import { csrfFetch, csrfHeaders } from '../resources/js/csrf.js';

test('sends the decoded encrypted XSRF cookie in the Laravel header', () => {
    const encryptedCookie = 'eyJpdiI6+/ciphertext==';
    globalThis.document = {
        cookie: `XSRF-TOKEN=${encodeURIComponent(encryptedCookie)}`,
        querySelector: () => null,
    };
    try {
        assert.deepEqual(csrfHeaders(), { 'X-XSRF-TOKEN': encryptedCookie });
    } finally {
        delete globalThis.document;
    }
});

test('csrfFetch preserves FormData and sends same-origin credentials and CSRF header', async () => {
    const encryptedCookie = 'eyJpdiI6+/ciphertext==';
    const originalFetch = globalThis.fetch;
    globalThis.document = {
        cookie: `XSRF-TOKEN=${encodeURIComponent(encryptedCookie)}`,
        querySelector: () => null,
    };
    let request;
    globalThis.fetch = async (url, options) => {
        request = { url, ...options };
        return new Response(null, { status: 204 });
    };

    try {
        const body = new FormData();
        body.append('attachment', new Blob(['test']), 'test.txt');
        await csrfFetch('/api/v1/upload', { method: 'POST', body });

        assert.equal(request.credentials, 'same-origin');
        assert.equal(request.headers.get('X-XSRF-TOKEN'), encryptedCookie);
        assert.equal(request.body, body);
        assert.equal(request.headers.has('Content-Type'), false);
    } finally {
        globalThis.fetch = originalFetch;
        delete globalThis.document;
    }
});