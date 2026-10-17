import { useForm } from '@inertiajs/react';
import SuperAdminLayout from '../../Layouts/SuperAdminLayout';
import { PageHeader, usePt } from '../../Components/Platform/ui';

export default function MfaChallenge() {
    const { t } = usePt();
    const form = useForm({ code: '' });
    return <SuperAdminLayout title={t('pf_mfa_challenge')}>
        <PageHeader title={t('pf_mfa_challenge')} intro={t('pf_mfa_challenge_copy')} />
        <form className="erp-section account-edit-form" onSubmit={(event) => { event.preventDefault(); form.post('/super-admin/mfa/challenge'); }}>
            <label className="field"><span>{t('pf_mfa_code')}</span><input inputMode="numeric" autoComplete="one-time-code" required autoFocus value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} aria-invalid={Boolean(form.errors.code)} />{form.errors.code && <small role="alert">{form.errors.code}</small>}<small>{t('pf_mfa_code_hint')}</small></label>
            <button type="submit" disabled={form.processing} className="erp-button erp-button--primary">{t('pf_confirm')}</button>
        </form>
    </SuperAdminLayout>;
}
