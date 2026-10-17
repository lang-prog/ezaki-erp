import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import ModalShell from '../ModalShell';
import ErrorBoundary from '../ErrorBoundary';
import { failureKey } from './planChange';
import { translate } from '../../i18n';
import { formatDate, formatDateTime, formatMoney } from '../../format';

export function usePt() {
    const { locale, capabilities = {} } = usePage().props;
    return { locale, can: (permission) => Boolean(capabilities[`platform.${permission}`]), t: (key) => translate(locale, key), date: (value) => formatDate(value, locale), dateTime: (value) => formatDateTime(value, locale), money: (value) => formatMoney(value, locale) };
}

export const newKey = () => (window.crypto?.randomUUID ? window.crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}-${Math.random().toString(16).slice(2)}`);

export function errorText(error, fallback) {
    const data = error?.response?.data;
    if (data?.errors) return Object.values(data.errors).flat().join(' ');
    return data?.message || fallback;
}

/** Localized message for any failed platform request: validation detail for 422, never raw server errors for 5xx. */
export function failureMessage(error, t) {
    const status = error?.response?.status;
    const data = error?.response?.data;
    if (status === 422 && data?.errors) return Object.values(data.errors).flat().join(' ');
    const base = t(failureKey(status));
    if (status === 409 && typeof data?.message === 'string' && data.message) return `${base} ${data.message}`;

    return base;
}

/**
 * GET preview of an action's impact. `loading` and `data` are derived from the query the result belongs to,
 * so the render that follows a changed selection can never see data from another query (or none at all).
 */
export function usePreview(params, enabled = true) {
    const query = JSON.stringify(params);
    const [state, setState] = useState({ query: null, data: null, error: '', status: null });
    useEffect(() => {
        if (!enabled) return undefined;
        let cancelled = false;
        window.axios.get('/super-admin/subscriptions/preview', { params: JSON.parse(query) })
            .then((response) => { if (!cancelled) setState({ query, data: response.data, error: '', status: null }); })
            .catch((error) => { if (!cancelled) setState({ query, data: null, error: 'failed', status: error?.response?.status ?? null }); });
        return () => { cancelled = true; };
    }, [query, enabled]);
    const settled = enabled && state.query === query;

    return { loading: enabled && !settled, data: settled ? state.data : null, error: settled ? state.error : '', status: settled ? state.status : null };
}

const TONES = {
    active: ['success', '●'], paid: ['success', '✓'], enabled: ['success', '●'], handled: ['success', '✓'], approved: ['success', '✓'], info: ['muted-pill', 'i'],
    grace: ['warning', '▲'], read_only: ['warning', '▲'], pending: ['warning', '…'], open: ['warning', '…'], expiring: ['warning', '▲'], warning: ['warning', '▲'],
    suspended: ['danger', '⏸'], archived: ['muted-pill', '▣'], failed: ['danger', '✕'], revoked: ['danger', '✕'], disabled: ['muted-pill', '○'], rejected: ['danger', '✕'], cancelled: ['danger', '✕'], critical: ['danger', '✕'], lapsed: ['danger', '▲'],
};

export function StatusBadge({ status, label }) {
    const [tone, icon] = TONES[status] ?? ['muted-pill', '•'];
    return <span className={`status-pill ${tone}`}><span aria-hidden="true">{icon} </span>{label ?? status}</span>;
}

export function PageHeader({ title, intro, actions }) {
    return <div className="erp-page-header"><div><h1>{title}</h1>{intro && <p>{intro}</p>}</div>{actions && <div className="erp-toolbar no-print">{actions}</div>}</div>;
}

export function Notice({ type = 'info', children }) {
    return <div role={type === 'error' ? 'alert' : 'status'} className={`notice-card ${type}`}>{children}</div>;
}

export function Pagination({ links }) {
    const { t } = usePt();
    if (!links || links.length <= 3) return null;
    const clean = (label) => label.replace(/<[^>]*>/g, '').replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/&hellip;/g, '…');
    return <nav className="pagination no-print" aria-label={t('pf_pagination')}>{links.map((link, index) => link.url
        ? <Link key={index} href={link.url} preserveScroll className={`erp-button ${link.active ? 'erp-button--primary' : 'erp-button--secondary'}`}>{clean(link.label)}</Link>
        : <span key={index} className="erp-button erp-button--secondary is-disabled">{clean(link.label)}</span>)}</nav>;
}

export function EmptyRow({ colSpan }) {
    const { t } = usePt();
    return <tr><td colSpan={colSpan} className="empty-cell">{t('pf_empty')}</td></tr>;
}

function Toast({ text, onDismiss }) {
    const { t } = usePt();
    useEffect(() => {
        const timer = window.setTimeout(onDismiss, 6000);
        return () => window.clearTimeout(timer);
    }, [text, onDismiss]);

    return <div role="status" className="notice-card success platform-toast no-print"><span>{text}</span><button type="button" className="erp-link-button" onClick={onDismiss} aria-label={t('close')}>×</button></div>;
}

/** Runs platform requests; a 423 step-up response opens a password/TOTP dialog and then retries the request once. */
export function usePlatformApi() {
    const { t } = usePt();
    const [pending, setPending] = useState(null);
    const [notice, setNotice] = useState('');

    const askStepUp = useCallback((requiresMfa) => new Promise((resolve, reject) => setPending({ requiresMfa, resolve, reject })), []);
    const call = useCallback(async (method, url, data) => {
        try {
            return await window.axios({ method, url, data });
        } catch (error) {
            if (error.response?.status === 423 && error.response.data?.code === 'step_up_required') {
                await askStepUp(Boolean(error.response.data.requires_mfa));
                return window.axios({ method, url, data });
            }
            if (error.response?.status === 423 && error.response.data?.code) {
                router.visit(error.response.data.code === 'mfa_required' ? '/super-admin/mfa/challenge' : '/super-admin/security');
                throw new Error('cancelled');
            }
            throw error;
        }
    }, [askStepUp]);
    const act = useCallback(async (method, url, data) => {
        const result = await call(method, url, data);
        router.reload({ preserveScroll: true });
        setNotice(t('pf_saved'));
        return result;
    }, [call, t]);
    const dismiss = useCallback(() => setNotice(''), []);

    return {
        call,
        act,
        stepUpDialog: <>
            {pending && <StepUpDialog requiresMfa={pending.requiresMfa} onDone={() => { pending.resolve(); setPending(null); }} onCancel={() => { pending.reject(new Error('cancelled')); setPending(null); }} />}
            {notice && <Toast text={notice} onDismiss={dismiss} />}
        </>,
    };
}

function StepUpDialog({ requiresMfa, onDone, onCancel }) {
    const { t } = usePt();
    const [form, setForm] = useState({ password: '', code: '' });
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    async function submit(event) {
        event.preventDefault();
        event.stopPropagation();
        setBusy(true);
        setError('');
        try {
            await window.axios.post('/super-admin/step-up', form);
            onDone();
        } catch (failure) {
            setError(failureMessage(failure, t));
        } finally {
            setBusy(false);
        }
    }
    return <ModalShell titleId="stepup-title" eyebrow={t('pf_security')} title={t('pf_stepup_title')} subtitle={t('pf_stepup_copy')} closeLabel={t('close')} onClose={onCancel} footer={<>
        <button type="button" className="erp-button erp-button--secondary" onClick={onCancel}>{t('cancel')}</button>
        <button type="submit" form="stepup-form" disabled={busy} className="erp-button erp-button--primary">{t('pf_confirm')}</button>
    </>}>
        <form id="stepup-form" className="account-edit-form" onSubmit={submit}>
            {error && <Notice type="error">{error}</Notice>}
            <label className="field"><span>{t('password')}</span><input type="password" autoComplete="current-password" required value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} autoFocus /></label>
            {requiresMfa && <label className="field"><span>{t('pf_mfa_code')}</span><input inputMode="numeric" autoComplete="one-time-code" required value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} /><small>{t('pf_mfa_code_hint')}</small></label>}
        </form>
    </ModalShell>;
}

function ContainedFailure() {
    const { t } = usePt();

    return <Notice type="error">{t('pf_render_failed')}</Notice>;
}

/** Accessible confirmation: shows the impact first, requires a reason, and surfaces server errors inline. */
export function ConfirmActionDialog({ title, eyebrow, impact, confirmLabel, danger = false, withReason = true, onConfirm, onClose, disabled = false, resetKey = '', children }) {
    const { t } = usePt();
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const mounted = useRef(true);
    const inFlight = useRef(false);
    useEffect(() => () => { mounted.current = false; }, []);
    async function submit(event) {
        event.preventDefault();
        event.stopPropagation();
        if (inFlight.current || disabled) return;
        inFlight.current = true;
        setBusy(true);
        setError(null);
        try {
            await onConfirm({ reason });
            onClose();
        } catch (failure) {
            if (failure?.message !== 'cancelled' && mounted.current) setError({ text: failureMessage(failure, t), status: failure?.response?.status ?? null });
        } finally {
            inFlight.current = false;
            if (mounted.current) setBusy(false);
        }
    }
    return <ModalShell titleId="confirm-title" eyebrow={eyebrow ?? t('pf_confirm_eyebrow')} title={title} subtitle={danger ? t('pf_danger_hint') : t('pf_review_hint')} closeLabel={t('close')} onClose={onClose} footer={<>
        <button type="button" className="erp-button erp-button--secondary" onClick={onClose}>{t('cancel')}</button>
        <button type="submit" form="confirm-form" disabled={busy || disabled} className={`erp-button ${danger ? 'erp-button--danger' : 'erp-button--primary'}`}>{danger && <span aria-hidden="true">⚠ </span>}{confirmLabel}</button>
    </>}>
        <form id="confirm-form" className="account-edit-form" onSubmit={submit}>
            {error && <Notice type="error"><span>{error.text}</span>{error.status === 419 && <> <button type="button" className="erp-link-button" onClick={() => router.reload({ preserveScroll: true })}>{t('pf_reload_data')}</button></>}</Notice>}
            <ErrorBoundary resetKey={resetKey} fallback={<ContainedFailure />}>
                {impact && <div className="impact-box" role="group" aria-label={t('pf_impact')}><strong>{t('pf_impact')}</strong>{impact}</div>}
                {children}
            </ErrorBoundary>
            {withReason && <label className="field"><span>{t('pf_reason')}</span><textarea required minLength={3} maxLength={1000} rows={3} value={reason} onChange={(event) => setReason(event.target.value)} /><small>{t('pf_reason_hint')}</small></label>}
        </form>
    </ModalShell>;
}
