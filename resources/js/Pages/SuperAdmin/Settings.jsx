import { useState } from 'react';
import SuperAdminLayout from '../../Layouts/SuperAdminLayout';
import { ConfirmActionDialog, PageHeader, StatusBadge, usePlatformApi, usePt } from '../../Components/Platform/ui';

export default function Settings({ registrationOpen, security }) {
    const { t, can, date } = usePt();
    const api = usePlatformApi();
    const [dialog, setDialog] = useState(false);
    return <SuperAdminLayout title={t('pf_nav_settings')}>
        <PageHeader title={t('pf_nav_settings')} intro={t('pf_settings_intro')} />
        <section className="erp-section" aria-labelledby="reg-title">
            <div className="erp-section__header"><div><h2 id="reg-title">{t('selfRegistration')}</h2><p>{t('pf_registration_hint')}</p></div></div>
            <p><StatusBadge status={registrationOpen ? 'active' : 'disabled'} label={t(registrationOpen ? 'pf_registration_open' : 'pf_registration_closed')} /></p>
            {can('settings.manage') && <button type="button" className="erp-button erp-button--secondary" onClick={() => setDialog(true)}>{t(registrationOpen ? 'pf_close_registration' : 'pf_open_registration')}</button>}
        </section>
        <section className="erp-section" aria-labelledby="sec-title">
            <div className="erp-section__header"><div><h2 id="sec-title">{t('pf_security_policy')}</h2><p>{t('pf_security_policy_hint')}</p></div></div>
            <dl className="impact-list">
                <div><dt>{t('pf_mfa_policy')}</dt><dd>{security.mfa_required ? t('pf_mfa_required') : security.mfa_enforce_from ? `${t('pf_mfa_from')} ${date(security.mfa_enforce_from)}` : t('pf_mfa_optional')}</dd></div>
                <div><dt>{t('pf_stepup_window')}</dt><dd>{security.step_up_minutes} {t('pf_minutes')}</dd></div>
                <div><dt>{t('pf_audit_retention')}</dt><dd>{security.audit_retention_days} {t('days')}</dd></div>
                <div><dt>{t('pf_login_retention')}</dt><dd>{security.login_log_retention_days} {t('days')}</dd></div>
            </dl>
        </section>
        {dialog && <ConfirmActionDialog title={t(registrationOpen ? 'pf_close_registration' : 'pf_open_registration')} danger={!registrationOpen} confirmLabel={t('save')} impact={<p>{t(registrationOpen ? 'pf_impact_close_registration' : 'pf_impact_open_registration')}</p>} onClose={() => setDialog(false)} onConfirm={({ reason }) => api.act('post', '/super-admin/registration-setting', { enabled: !registrationOpen, reason })} />}
        {api.stepUpDialog}
    </SuperAdminLayout>;
}
