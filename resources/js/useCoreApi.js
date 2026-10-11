import { useState } from 'react';
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
            const result = await response.json();
            if (!response.ok) {
                const validation = result.errors ? Object.values(result.errors).flat().join(' ') : '';
                throw new Error(validation || result.message || 'Request failed.');
            }
            setNotice({ type: 'success', message: result.message ?? 'Saved.' });
            window.location.reload();
            return result;
        } catch (error) {
            setNotice({ type: 'error', message: error.message });
            return null;
        } finally {
            setProcessing(false);
        }
    }

    return { notice, processing, send };
}
