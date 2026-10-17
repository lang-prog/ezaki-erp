import { useState } from 'react';
import { router } from '@inertiajs/react';
import SuperAdminLayout from '../../Layouts/SuperAdminLayout';
import { ConfirmActionDialog, EmptyRow, Notice, PageHeader, Pagination, StatusBadge, usePlatformApi, usePt } from '../../Components/Platform/ui';

const IMPACT = { suspend: 'pf_impact_suspend', activate: 'pf_impact_activate', archive: 'pf_impact_archive', restore: 'pf_impact_restore' };

function CreateCompany({ plans, onSaved }) {
    const { t } = usePt();
    const api = usePlatformApi();
    const empty = { name: '', trade_name: '', tax_number: '', phone: '', country: '', city: '', address: '', owner_name: '', owner_email: '', owner_password: '', owner_password_confirmation: '', plan_id: '', coupon_code: '', notes: '' };
    const [form, setForm] = useState(empty);
    const [state, setState] = useState({ busy: false, error: '', ok: false });
    const set = (key) => (event) => setForm({ ...form, [key]: event.target.value });
    async function submit(event) {
        event.preventDefault();
        setState({ busy: true, error: '', ok: false });
        try {
            await api.call('post', '/super-admin/companies', form);
            setForm(empty);
            setState({ busy: false, error: '', ok: true });
            onSaved();
        } catch (error) {
            const data = error.response?.data;
            setState({ busy: false, error: data?.errors ? Object.values(data.errors).flat().join(' ') : (data?.message ?? t('pf_error_generic')), ok: false });
        }
    }
    const text = (key, label, props = {}) => <label className="field"><span>{t(label)}</span><input value={form[key]} onChange={set(key)} {...props} /></label>;
    return <>
    <form className="erp-section posting-form" onSubmit={submit} aria-labelledby="create-company-title">
        <h2 id="create-company-title">{t('pf_create_company')}</h2>
        {state.error && <Notice type="error">{state.error}</Notice>}
        {state.ok && <Notice type="success">{t('pf_company_created')}</Notice>}
        <div className="posting-form__grid">
            {text('name', 'companyName', { required: true })}{text('trade_name', 'tradeName')}{text('tax_number', 'taxNumber')}{text('phone', 'phone')}{text('country', 'country')}{text('city', 'city')}
            {text('owner_name', 'ownerName', { required: true })}{text('owner_email', 'ownerEmail', { required: true, type: 'email' })}
            {text('owner_password', 'password', { required: true, type: 'password', minLength: 8, autoComplete: 'new-password' })}{text('owner_password_confirmation', 'confirmPassword', { required: true, type: 'password', minLength: 8, autoComplete: 'new-password' })}
            <label className="field"><span>{t('requestedPlan')}</span><select required value={form.plan_id} onChange={set('plan_id')}><option value="">—</option>{plans.map((plan) => <option key={plan.id} value={plan.id}>{plan.name} · {t(`pf_duration_${plan.duration}`)} · {plan.price} {t('currency')}</option>)}</select></label>
            {text('coupon_code', 'couponCode')}
            <label className="field field--wide"><span>{t('address')}</span><input value={form.address} onChange={set('address')} /></label>
            <label className="field field--wide"><span>{t('pf_admin_notes')}</span><input value={form.notes} onChange={set('notes')} /></label>
        </div>
        <button type="submit" disabled={state.busy} className="erp-button erp-button--primary">{t('pf_create_company_submit')}</button>
    </form>
    {api.stepUpDialog}
    </>;
}

export default function Companies({ companies, filters = {}, plans = [] }) {
    const { t, can, date } = usePt();
    const api = usePlatformApi();
    const [dialog, setDialog] = useState(null);
    const [values, setValues] = useState({ q: filters.q ?? '', status: filters.status ?? '' });
    const [showCreate, setShowCreate] = useState(false);
    const apply = (event) => { event.preventDefault(); router.get('/super-admin/companies', Object.fromEntries(Object.entries(values).filter(([, v]) => v)), { preserveState: true }); };
    const actions = (company) => {
        if (!can('companies.lifecycle')) return [];
        if (company.status === 'archived') return [['restore', false]];
        return [...(company.status !== 'active' ? [['activate', false]] : []), ...(company.status === 'active' ? [['suspend', true]] : []), ['archive', true]];
    };

    return <SuperAdminLayout title={t('pf_nav_companies')}>
        <PageHeader title={t('pf_nav_companies')} intro={t('pf_companies_intro')} actions={can('companies.create') && <button type="button" className="erp-button erp-button--primary" aria-expanded={showCreate} onClick={() => setShowCreate((v) => !v)}>{t('pf_create_company')}</button>} />
        {showCreate && <CreateCompany plans={plans} onSaved={() => router.reload({ preserveScroll: true })} />}
        <section className="erp-section">
            <form className="report-filter-bar no-print" onSubmit={apply}>
                <label className="field"><span>{t('pf_search')}</span><input type="search" value={values.q} onChange={(event) => setValues({ ...values, q: event.target.value })} /></label>
                <label className="field"><span>{t('status')}</span><select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })}><option value="">{t('allStatuses')}</option>{['active', 'suspended', 'archived'].map((s) => <option key={s} value={s}>{t(`pf_status_${s}`)}</option>)}</select></label>
                <div className="erp-toolbar"><button type="submit" className="erp-button erp-button--primary">{t('apply')}</button></div>
            </form>
            <div className="erp-table-wrap"><table className="erp-table">
                <thead><tr><th>{t('pf_company')}</th><th>{t('status')}</th><th>{t('pf_plan')}</th><th>{t('pf_access_state')}</th><th>{t('pf_ends')}</th><th className="no-print">{t('actions')}</th></tr></thead>
                <tbody>
                    {companies.data.map((company) => <tr key={company.id}>
                        <td><strong>{company.name}</strong></td>
                        <td><StatusBadge status={company.status} label={t(`pf_status_${company.status}`)} /></td>
                        <td>{company.subscription?.plan?.name ?? '—'}</td>
                        <td>{company.subscription ? <StatusBadge status={company.subscription.access_state} label={t(`pf_state_${company.subscription.access_state}`)} /> : t('pf_no_subscription')}</td>
                        <td>{company.subscription?.plan?.duration === 'lifetime' ? t('pf_lifetime') : date(company.subscription?.ends_at)}</td>
                        <td className="no-print"><div className="row-actions">{actions(company).map(([action, danger]) => <button key={action} type="button" className={`table-action ${danger ? 'table-action--danger' : ''}`} onClick={() => setDialog({ action, danger, company })}>{danger && <span aria-hidden="true">⚠ </span>}{t(`pf_action_${action}`)}</button>)}</div></td>
                    </tr>)}
                    {companies.data.length === 0 && <EmptyRow colSpan={6} />}
                </tbody>
            </table></div>
            <Pagination links={companies.links} />
        </section>
        {dialog && <ConfirmActionDialog title={`${t(`pf_action_${dialog.action}`)} — ${dialog.company.name}`} danger={dialog.danger} confirmLabel={t(`pf_action_${dialog.action}`)} impact={<p>{t(IMPACT[dialog.action])}</p>} onClose={() => setDialog(null)} onConfirm={({ reason }) => api.act('post', `/super-admin/companies/${dialog.company.id}/${dialog.action}`, { reason })} />}
        {api.stepUpDialog}
    </SuperAdminLayout>;
}
