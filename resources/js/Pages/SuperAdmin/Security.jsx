import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import SuperAdminLayout from '../../Layouts/SuperAdminLayout';
import { ConfirmActionDialog, Notice, PageHeader, StatusBadge, usePlatformApi, usePt } from '../../Components/Platform/ui';

export default function Security({ mfa, pending, recoveryCodes }) {
    const { t, date } = usePt();
    const api = usePlatformApi();
    const form = useForm({ code: '' });
    const [dialog, setDialog] = useState(null);
    return <SuperAdminLayout title={t('pf_security')}>
        <PageHeader title={t('pf_security')} intro={t('pf_security_intro')} />
        {mfa.enforced && !mfa.enabled && <Notice type="error">{t('pf_mfa_must_enroll')}</Notice>}
        {!mfa.enforced && !mfa.enabled && <Notice type="info">{mfa.enforce_from ? `${t('pf_mfa_grace')} ${date(mfa.enforce_from)}` : t('pf_mfa_voluntary')}</Notice>}
        {recoveryCodes && <section className="erp-section" aria-labelledby="codes-title"><h2 id="codes-title">{t('pf_recovery_codes')}</h2><Notice type="error">{t('pf_recovery_codes_once')}</Notice><ul className="recovery-codes">{recoveryCodes.map((code) => <li key={code}><code>{code}</code></li>)}</ul></section>}
        <section className="erp-section">
            <p><StatusBadge status={mfa.enabled ? 'enabled' : 'pending'} label={t(mfa.enabled ? 'pf_mfa_on' : 'pf_mfa_off')} /></p>
            {mfa.enabled ? <>
                <p>{t('pf_remaining_codes')}: <strong>{mfa.remaining_codes}</strong></p>
                <div className="erp-toolbar">
                    <button type="button" className="erp-button erp-button--secondary" onClick={() => setDialog('codes')}>{t('pf_regenerate_codes')}</button>
                    {!mfa.enforced && <button type="button" className="erp-button erp-button--danger" onClick={() => setDialog('disable')}><span aria-hidden="true">⚠ </span>{t('pf_disable_mfa')}</button>}
                </div>
            </> : pending && <form onSubmit={(event) => { event.preventDefault(); form.post('/super-admin/security/enroll', { preserveScroll: true }); }} className="account-edit-form">
                <h2>{t('pf_enroll_title')}</h2><p>{t('pf_enroll_steps')}</p>
                <label className="field"><span>{t('pf_secret_key')}</span><input readOnly dir="ltr" value={pending.secret} onFocus={(event) => event.target.select()} /></label>
                <label className="field"><span>{t('pf_otpauth')}</span><input readOnly dir="ltr" value={pending.uri} onFocus={(event) => event.target.select()} /></label>
                <label className="field"><span>{t('pf_mfa_code')}</span><input inputMode="numeric" autoComplete="one-time-code" required value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} aria-invalid={Boolean(form.errors.code)} />{form.errors.code && <small role="alert">{form.errors.code}</small>}</label>
                <button disabled={form.processing} className="erp-button erp-button--primary">{t('pf_confirm_enroll')}</button>
            </form>}
        </section>
        {dialog === 'codes' && <ConfirmActionDialog title={t('pf_regenerate_codes')} withReason={false} confirmLabel={t('pf_regenerate_codes')} impact={<p>{t('pf_impact_codes')}</p>} onClose={() => setDialog(null)} onConfirm={() => api.act('post', '/super-admin/security/recovery-codes', {})} />}
        {dialog === 'disable' && <ConfirmActionDialog title={t('pf_disable_mfa')} danger confirmLabel={t('pf_disable_mfa')} impact={<p>{t('pf_impact_disable_mfa')}</p>} onClose={() => setDialog(null)} onConfirm={({ reason }) => api.act('post', '/super-admin/security/disable', { reason })} />}
        {api.stepUpDialog}
    </SuperAdminLayout>;
}
