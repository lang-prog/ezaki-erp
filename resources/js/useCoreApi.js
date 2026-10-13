import { useState } from 'react';
import { router } from '@inertiajs/react';
import { csrfFetch } from './csrf';

export function useCoreApi() {
    const [notice, setNotice] = useState({ type: '', message: '' });
    const [processing, setProcessing] = useState(false);

    async function send(path, method, body) {
        setProcessing(true);
        setNotice({ type: '', message: '' });
        try {
            const response = await csrfFetch(`/api/v1/${path}`, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: body === undefined ? undefined : JSON.stringify(body),
            });
            const responseBody = await response.text();
            let result = {};
            try {
                result = responseBody ? JSON.parse(responseBody) : {};
            } catch {
                result = {};
            }
            if (!response.ok) {
                const validation = result.errors ? Object.values(result.errors).flat().join(' ') : '';
                window.dispatchEvent(new CustomEvent('ezaki:request-error', { detail: { status: response.status, message: validation || result.message } }));
                throw new Error(validation || result.message || 'Request failed.');
            }
            setNotice({ type: 'success', message: result.message ?? 'Saved.' });
            router.reload({ preserveScroll: true });
            return result;
        } catch (error) {
            if (!error.response) window.dispatchEvent(new CustomEvent('ezaki:request-error', { detail: { message: error.message } }));
            setNotice({ type: 'error', message: error.message || 'Request failed.' });
            return null;
        } finally {
            setProcessing(false);
        }
    }

    return { notice, processing, send };
}
