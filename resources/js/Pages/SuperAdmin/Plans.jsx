import { useState } from 'react';
import SuperAdminLayout from '../../Layouts/SuperAdminLayout';
import { ConfirmActionDialog, EmptyRow, Notice, PageHeader, StatusBadge, errorText, usePlatformApi, usePt } from '../../Components/Platform/ui';
import { router } from '@inertiajs/react';

const DURATIONS = ['monthly', 'six_months', 'yearly', 'trial', 'lifetime'];

export default function Plans({ plans = [], coupons = [] }) {
    const { t, can, date, money } = usePt();
    const api = usePlatformApi();
    const [dialog, setDialog] = useState(null);
    const [plan, setPlan] = useState({ code: '', name: '', duration: 'monthly', duration_days: '', price: '0', limits: { users: '', branches: '', warehouses: '' } });
    const [coupon, setCoupon] = useState({ code: '', discount_type: 'percent', discount_value: '', max_redemptions: '', starts_at: '', ends_at: '' });
    const [message, setMessage] = useState({ type: '', text: '' });

    async function create(event, url, data, reset) {
        event.preventDefault();
        setMessage({ type: '', text: '' });
        try {
            await api.call('post', url, data);
            reset();
            setMessage({ type: 'success', text: t('pf_saved') });
            router.reload({ preserveScroll: true });
        } catch (error) {
            setMessage({ type: 'error', text: errorText(error, t('pf_error_generic')) });
        }
    }
    const clean = (object) => Object.fromEntries(Object.entries(object).filter(([, v]) => v !== ''));

    return <SuperAdminLayout title={t('pf_nav_plans')}>
        <PageHeader title={t('pf_nav_plans')} intro={t('pf_plans_intro')} />
        {message.text && <Notice type={message.type}>{message.text}</Notice>}
        <section className="erp-section" aria-labelledby="plans-title">
            <div className="erp-section__header"><div><h2 id="plans-title">{t('plans')}</h2><p>{t('pf_features_deprecated')}</p></div></div>
            <div className="erp-table-wrap"><table className="erp-table">
                <thead><tr><th>{t('planName')}</th><th>{t('duration')}</th><th>{t('price')}</th><th>{t('pf_limits')}</th><th>{t('pf_subscriptions_count')}</th><th>{t('status')}</th><th className="no-print">{t('actions')}</th></tr></thead>
                <tbody>
                    {plans.map((row) => <tr key={row.id}><td><strong>{row.name}</strong><small className="table-subline">{row.code}</small></td><td>{t(`pf_duration_${row.duration}`)}{row.duration === 'trial' && ` (${row.duration_days})`}</td><td>{money(row.price)} {t('currency')}</td>
                        <td>{['users', 'branches', 'warehouses'].map((key) => `${t(`pf_res_${key}`)}: ${row.limits?.[key] ?? t('pf_unlimited')}`).join(' · ')}</td><td>{row.subscriptions_count}</td><td><StatusBadge status={row.is_active ? 'active' : 'disabled'} label={t(row.is_active ? 'active' : 'disabled')} /></td>
                        <td className="no-print">{can('plans.manage') && <button type="button" className="table-action" onClick={() => setDialog({ kind: 'plans', row })}>{t(row.is_active ? 'pf_deactivate' : 'pf_activate')}</button>}</td></tr>)}
                    {plans.length === 0 && <EmptyRow colSpan={7} />}
                </tbody>
            </table></div>
            {can('plans.manage') && <form className="posting-form" onSubmit={(event) => create(event, '/super-admin/plans', { ...clean({ ...plan, limits: undefined }), limits: clean(plan.limits) }, () => setPlan({ ...plan, code: '', name: '' }))}>
                <h3>{t('createPlan')}</h3>
                <div className="posting-form__grid">
                    <label className="field"><span>{t('planCode')}</span><input required value={plan.code} onChange={(e) => setPlan({ ...plan, code: e.target.value })} /></label>
                    <label className="field"><span>{t('planName')}</span><input required value={plan.name} onChange={(e) => setPlan({ ...plan, name: e.target.value })} /></label>
                    <label className="field"><span>{t('duration')}</span><select value={plan.duration} onChange={(e) => setPlan({ ...plan, duration: e.target.value })}>{DURATIONS.map((d) => <option key={d} value={d}>{t(`pf_duration_${d}`)}</option>)}</select></label>
                    {plan.duration === 'trial' && <label className="field"><span>{t('days')}</span><input type="number" min="1" required value={plan.duration_days} onChange={(e) => setPlan({ ...plan, duration_days: e.target.value })} /></label>}
                    <label className="field"><span>{t('price')}</span><input type="number" min="0" step="0.01" required value={plan.price} onChange={(e) => setPlan({ ...plan, price: e.target.value })} /></label>
                    {['users', 'branches', 'warehouses'].map((key) => <label key={key} className="field"><span>{t(`pf_res_${key}`)} <small>({t('pf_empty_unlimited')})</small></span><input type="number" min="1" value={plan.limits[key]} onChange={(e) => setPlan({ ...plan, limits: { ...plan.limits, [key]: e.target.value } })} /></label>)}
                </div>
                <button className="erp-button erp-button--primary">{t('createPlan')}</button>
            </form>}
        </section>
        <section className="erp-section" aria-labelledby="coupons-title">
            <div className="erp-section__header"><div><h2 id="coupons-title">{t('coupons')}</h2><p>{t('pf_coupons_hint')}</p></div></div>
            <div className="erp-table-wrap"><table className="erp-table">
                <thead><tr><th>{t('couponCode')}</th><th>{t('discount')}</th><th>{t('pf_validity')}</th><th>{t('pf_redemptions')}</th><th>{t('status')}</th><th className="no-print">{t('actions')}</th></tr></thead>
                <tbody>
                    {coupons.map((row) => <tr key={row.id}><td><strong>{row.code}</strong></td><td>{row.discount_type === 'percent' ? `${row.discount_value}%` : `${money(row.discount_value)} ${t('currency')}`}</td><td>{row.starts_at ? date(row.starts_at) : '—'} → {row.ends_at ? date(row.ends_at) : '—'}</td><td>{row.redemptions_count}{row.max_redemptions ? ` / ${row.max_redemptions}` : ''}</td><td><StatusBadge status={row.is_active ? 'active' : 'disabled'} label={t(row.is_active ? 'active' : 'disabled')} /></td>
                        <td className="no-print">{can('coupons.manage') && <button type="button" className="table-action" onClick={() => setDialog({ kind: 'coupons', row })}>{t(row.is_active ? 'pf_deactivate' : 'pf_activate')}</button>}</td></tr>)}
                    {coupons.length === 0 && <EmptyRow colSpan={6} />}
                </tbody>
            </table></div>
            {can('coupons.manage') && <form className="posting-form" onSubmit={(event) => create(event, '/super-admin/coupons', clean(coupon), () => setCoupon({ ...coupon, code: '', discount_value: '' }))}>
                <h3>{t('createCoupon')}</h3>
                <div className="posting-form__grid">
                    <label className="field"><span>{t('couponCode')}</span><input required value={coupon.code} onChange={(e) => setCoupon({ ...coupon, code: e.target.value })} /></label>
                    <label className="field"><span>{t('pf_discount_type')}</span><select value={coupon.discount_type} onChange={(e) => setCoupon({ ...coupon, discount_type: e.target.value })}><option value="percent">{t('pf_percent')}</option><option value="fixed">{t('pf_fixed')}</option></select></label>
                    <label className="field"><span>{t('discount')}</span><input type="number" min="0.01" step="0.01" required value={coupon.discount_value} onChange={(e) => setCoupon({ ...coupon, discount_value: e.target.value })} /></label>
                    <label className="field"><span>{t('maxUses')}</span><input type="number" min="1" value={coupon.max_redemptions} onChange={(e) => setCoupon({ ...coupon, max_redemptions: e.target.value })} /></label>
                    <label className="field"><span>{t('pf_valid_from')}</span><input type="datetime-local" value={coupon.starts_at} onChange={(e) => setCoupon({ ...coupon, starts_at: e.target.value })} /></label>
                    <label className="field"><span>{t('pf_valid_to')}</span><input type="datetime-local" value={coupon.ends_at} onChange={(e) => setCoupon({ ...coupon, ends_at: e.target.value })} /></label>
                </div>
                <button className="erp-button erp-button--primary">{t('createCoupon')}</button>
            </form>}
        </section>
        {dialog && <ConfirmActionDialog title={`${t(dialog.row.is_active ? 'pf_deactivate' : 'pf_activate')} — ${dialog.row.name ?? dialog.row.code}`} danger={dialog.row.is_active} confirmLabel={t(dialog.row.is_active ? 'pf_deactivate' : 'pf_activate')} impact={<p>{t(dialog.kind === 'plans' ? 'pf_impact_toggle_plan' : 'pf_impact_toggle_coupon')}</p>} onClose={() => setDialog(null)} onConfirm={({ reason }) => api.act('post', `/super-admin/${dialog.kind}/${dialog.row.id}/toggle`, { is_active: !dialog.row.is_active, reason })} />}
        {api.stepUpDialog}
    </SuperAdminLayout>;
}
