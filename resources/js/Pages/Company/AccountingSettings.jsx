import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';
import { csrfFetch } from '../../csrf';

const OPTIONS = [
    { value: 'factory_weight', title: 'policyFactoryTitle', effect: 'policyFactoryEffect', recommended: true },
    { value: 'actual_weight', title: 'policyActualTitle', effect: 'policyActualEffect', recommended: false },
];

export default function AccountingSettings({ policy }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const [form, setForm] = useState(policy);
    const [notice, setNotice] = useState({ type: '', message: '' });
    const [processing, setProcessing] = useState(false);
    const locked = Boolean(policy.weight_policy_locked);

    async function submit(event) {
        event.preventDefault();
        setProcessing(true);
        setNotice({ type: '', message: '' });
        try {
            const response = await csrfFetch('/api/v1/accounting-settings', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(form),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.message || Object.values(payload.errors ?? {}).flat().join(' ') || t('requestFailed'));
            setForm(payload.data);
            setNotice({ type: 'success', message: t('policySaved') });
        } catch (error) {
            setNotice({ type: 'error', message: error.message });
        } finally {
            setProcessing(false);
        }
    }

    return <CompanyLayout title={t('accountingPolicies')}>
        <div className="page-header"><div><p className="eyebrow">{t('ownerSettings')}</p><h1>{t('weightVatPolicy')}</h1><p>{t('policyIntro')}</p></div></div>
        <form onSubmit={submit} className="panel policy-form">
            {notice.message && <div role="status" aria-live="polite" className={`notice-card ${notice.type}`}>{notice.message}</div>}
            <fieldset disabled={locked} className="policy-options">
                <legend>{t('inventoryBasis')}</legend>
                {OPTIONS.map((option) => <label key={option.value} className={`policy-option ${form.purchase_inventory_basis === option.value ? 'selected' : ''}`}>
                    <input type="radio" name="basis" value={option.value} checked={form.purchase_inventory_basis === option.value} onChange={(event) => setForm({ ...form, purchase_inventory_basis: event.target.value })} />
                    <span><strong>{t(option.title)}{option.recommended && <em className="policy-badge"> · {t('recommendedDefault')}</em>}</strong><small>{t(option.effect)}</small></span>
                </label>)}
            </fieldset>
            <p className="policy-note" role="note">{t('policySalesNote')}</p>
            {locked && <p className="policy-lock" role="note">{t('policyLockedNotice')}</p>}
            <div className="section-divider"><label className="toggle-field"><input type="checkbox" checked={Boolean(form.vat_enabled)} onChange={(event) => setForm({ ...form, vat_enabled: event.target.checked })} /><span>{t('enableVat')}</span></label>{form.vat_enabled && <label className="field"><span>{t('vatRatePercent')}</span><input type="number" min="0" max="100" step="0.001" value={form.vat_rate} onChange={(event) => setForm({ ...form, vat_rate: event.target.value })} /></label>}</div>
            <button className="primary-button" disabled={processing}>{processing ? t('saving') : t('savePolicy')}</button>
        </form>
    </CompanyLayout>;
}
