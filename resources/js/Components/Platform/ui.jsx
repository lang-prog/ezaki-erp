import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import ModalShell from '../ModalShell';
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

/** Runs platform requests; a 423 step-up response opens a password/TOTP dialog and then retries the request once. */
export function usePlatformApi() {
    const [pending, setPending] = useState(null);

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
                window.location.assign(error.response.data.code === 'mfa_required' ? '/super-admin/mfa/challenge' : '/super-admin/security');
            }
            throw error;
        }
    }, [askStepUp]);
    const act = useCallback(async (method, url, data) => {
        const result = await call(method, url, data);
        router.reload({ preserveScroll: true });
        return result;
    }, [call]);

    return { call, act, stepUpDialog: pending ? <StepUpDialog requiresMfa={pending.requiresMfa} onDone={() => { pending.resolve(); setPending(null); }} onCancel={() => { pending.reject(new Error('cancelled')); setPending(null); }} /> : null };
}

function StepUpDialog({ requiresMfa, onDone, onCancel }) {
    const { t } = usePt();
    const [form, setForm] = useState({ password: '', code: '' });
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError('');
        try {
            await window.axios.post('/super-admin/step-up', form);
            onDone();
        } catch (failure) {
            setError(errorText(failure, t('pf_error_generic')));
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

/** Accessible confirmation: shows the impact first, requires a reason, and surfaces server errors inline. */
export function ConfirmActionDialog({ title, eyebrow, impact, confirmLabel, danger = false, withReason = true, onConfirm, onClose, disabled = false, children }) {
    const { t } = usePt();
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const mounted = useRef(true);
    useEffect(() => () => { mounted.current = false; }, []);
    async function submit(event) {
        event.preventDefault();
        setBusy(true);
        setError('');
        try {
            await onConfirm({ reason });
            onClose();
        } catch (failure) {
            if (failure?.message !== 'cancelled' && mounted.current) setError(errorText(failure, t('pf_error_generic')));
        } finally {
            if (mounted.current) setBusy(false);
        }
    }
    return <ModalShell titleId="confirm-title" eyebrow={eyebrow ?? t('pf_confirm_eyebrow')} title={title} subtitle={danger ? t('pf_danger_hint') : t('pf_review_hint')} closeLabel={t('close')} onClose={onClose} footer={<>
        <button type="button" className="erp-button erp-button--secondary" onClick={onClose}>{t('cancel')}</button>
        <button type="submit" form="confirm-form" disabled={busy || disabled} className={`erp-button ${danger ? 'erp-button--danger' : 'erp-button--primary'}`}>{danger && <span aria-hidden="true">⚠ </span>}{confirmLabel}</button>
    </>}>
        <form id="confirm-form" className="account-edit-form" onSubmit={submit}>
            {error && <Notice type="error">{error}</Notice>}
            {impact && <div className="impact-box" role="group" aria-label={t('pf_impact')}><strong>{t('pf_impact')}</strong>{impact}</div>}
            {children}
            {withReason && <label className="field"><span>{t('pf_reason')}</span><textarea required minLength={3} maxLength={1000} rows={3} value={reason} onChange={(event) => setReason(event.target.value)} /><small>{t('pf_reason_hint')}</small></label>}
        </form>
    </ModalShell>;
}
