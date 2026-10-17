import { useState } from 'react';
import SuperAdminLayout from '../../Layouts/SuperAdminLayout';
import { ConfirmActionDialog, EmptyRow, Notice, PageHeader, StatusBadge, errorText, usePlatformApi, usePt } from '../../Components/Platform/ui';
import { Link, router } from '@inertiajs/react';
import { durationLabel, durationSummary } from '../../i18n-durations';

function DurationPreview({ duration, t, locale }) {
    if (!duration) return <p className="permission-note">{t('dur_no_durations')}</p>;
    const summary = durationSummary(locale, duration);

    return <div className="impact-box" aria-live="polite">
        <strong>{t('dur_preview')}</strong>
        <p>{durationLabel(locale, duration)} · {summary} · {t(`dur_category_${duration.category}`)}</p>
        <p>{duration.is_lifetime ? t('dur_no_expiry') : t('dur_expiry_rule').replace('{summary}', summary)}</p>
        <p className="permission-note">{t(duration.is_lifetime ? 'dur_effect_lifetime' : `dur_effect_${duration.category}`)}</p>
    </div>;
}

function DurationSelect({ durations, value, onChange, current = null, disabled = false, t, locale }) {
    // An archived duration a plan already uses stays visible (read-only context) but is never offered to new plans.
    const options = current && !durations.some((duration) => duration.id === current.id) ? [current, ...durations] : durations;

    return <label className="field"><span>{t('dur_select')}</span>
        <select name="subscription_duration_id" required value={value} disabled={disabled || options.length === 0} onChange={(event) => onChange(event.target.value)}>
            <option value="">—</option>
            {options.map((duration) => <option key={duration.id} value={String(duration.id)}>{durationLabel(locale, duration)} — {durationSummary(locale, duration)} — {t(`dur_category_${duration.category}`)}{duration.is_active === false ? ` ${t('dur_current_archived')}` : ''}</option>)}
        </select>
    </label>;
}

function PlanEditDialog({ row, durations, api, t, locale, onClose }) {
    const [name, setName] = useState(row.name);
    const [durationId, setDurationId] = useState(row.duration_info?.id ? String(row.duration_info.id) : '');
    const locked = row.subscriptions_count > 0;
    const selected = [row.duration_info, ...durations].find((duration) => duration && String(duration.id) === durationId) ?? null;

    return <ConfirmActionDialog title={`${t('dur_plan_edit')} — ${row.name}`} confirmLabel={t('dur_plan_save')} onClose={onClose} impact={<p>{t('dur_plan_impact')}</p>}
        onConfirm={({ reason }) => api.act('put', `/super-admin/plans/${row.id}`, { name, ...(locked || !durationId ? {} : { subscription_duration_id: Number(durationId) }), reason })}>
        <label className="field"><span>{t('planName')}</span><input required maxLength={255} value={name} onChange={(event) => setName(event.target.value)} /></label>
        <DurationSelect durations={durations} current={row.duration_info?.id ? row.duration_info : null} value={durationId} onChange={setDurationId} disabled={locked} t={t} locale={locale} />
        <p className="permission-note" role="note">{t(locked ? 'dur_plan_locked' : 'dur_plan_unlocked')}</p>
        <DurationPreview duration={selected} t={t} locale={locale} />
    </ConfirmActionDialog>;
}

export default function Plans({ plans = [], coupons = [], durations = [] }) {
    const { t, can, date, money, locale } = usePt();
    const api = usePlatformApi();
    const [dialog, setDialog] = useState(null);
    const [plan, setPlan] = useState({ code: '', name: '', subscription_duration_id: '', price: '0', limits: { users: '', branches: '', warehouses: '' } });
    const selectedDuration = durations.find((duration) => String(duration.id) === plan.subscription_duration_id) ?? null;
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
        <PageHeader title={t('pf_nav_plans')} intro={t('pf_plans_intro')} actions={can('plans.manage') && <Link href="/super-admin/durations" className="erp-button erp-button--secondary">{t('dur_manage_link')}</Link>} />
        {message.text && <Notice type={message.type}>{message.text}</Notice>}
        <section className="erp-section" aria-labelledby="plans-title">
            <div className="erp-section__header"><div><h2 id="plans-title">{t('plans')}</h2><p>{t('pf_features_deprecated')}</p></div></div>
            <div className="erp-table-wrap"><table className="erp-table">
                <thead><tr><th>{t('planName')}</th><th>{t('duration')}</th><th>{t('price')}</th><th>{t('pf_limits')}</th><th>{t('pf_subscriptions_count')}</th><th>{t('status')}</th><th className="no-print">{t('actions')}</th></tr></thead>
                <tbody>
                    {plans.map((row) => <tr key={row.id}><td><strong>{row.name}</strong><small className="table-subline">{row.code}</small></td><td><strong>{durationLabel(locale, row.duration_info)}</strong><small className="table-subline">{durationSummary(locale, row.duration_info)} · {t(`dur_category_${row.duration_info?.category ?? 'paid'}`)}</small></td><td>{money(row.price)} {t('currency')}</td>
                        <td>{['users', 'branches', 'warehouses'].map((key) => `${t(`pf_res_${key}`)}: ${row.limits?.[key] ?? t('pf_unlimited')}`).join(' · ')}</td><td>{row.subscriptions_count}</td><td><StatusBadge status={row.is_active ? 'active' : 'disabled'} label={t(row.is_active ? 'active' : 'disabled')} /></td>
                        <td className="no-print">{can('plans.manage') && <div className="row-actions"><button type="button" className="table-action" onClick={() => setDialog({ kind: 'plan-edit', row })}>{t('dur_edit')}</button><button type="button" className="table-action" onClick={() => setDialog({ kind: 'plans', row })}>{t(row.is_active ? 'pf_deactivate' : 'pf_activate')}</button></div>}</td></tr>)}
                    {plans.length === 0 && <EmptyRow colSpan={7} />}
                </tbody>
            </table></div>
            {can('plans.manage') && <form className="posting-form" onSubmit={(event) => create(event, '/super-admin/plans', { ...clean({ ...plan, limits: undefined }), limits: clean(plan.limits) }, () => setPlan({ ...plan, code: '', name: '' }))}>
                <h3>{t('createPlan')}</h3>
                <div className="posting-form__grid">
                    <label className="field"><span>{t('planCode')}</span><input required value={plan.code} onChange={(e) => setPlan({ ...plan, code: e.target.value })} /></label>
                    <label className="field"><span>{t('planName')}</span><input required value={plan.name} onChange={(e) => setPlan({ ...plan, name: e.target.value })} /></label>
                    <DurationSelect durations={durations} value={plan.subscription_duration_id} onChange={(value) => setPlan({ ...plan, subscription_duration_id: value })} t={t} locale={locale} />
                    <label className="field"><span>{t('price')}</span><input type="number" min="0" step="0.01" required value={plan.price} onChange={(e) => setPlan({ ...plan, price: e.target.value })} /></label>
                    {['users', 'branches', 'warehouses'].map((key) => <label key={key} className="field"><span>{t(`pf_res_${key}`)} <small>({t('pf_empty_unlimited')})</small></span><input type="number" min="1" value={plan.limits[key]} onChange={(e) => setPlan({ ...plan, limits: { ...plan.limits, [key]: e.target.value } })} /></label>)}
                </div>
                <DurationPreview duration={selectedDuration} t={t} locale={locale} />
                <button type="submit" className="erp-button erp-button--primary" disabled={durations.length === 0}>{t('createPlan')}</button>
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
                <button type="submit" className="erp-button erp-button--primary">{t('createCoupon')}</button>
            </form>}
        </section>
        {dialog?.kind === 'plan-edit' && <PlanEditDialog row={dialog.row} durations={durations} api={api} t={t} locale={locale} onClose={() => setDialog(null)} />}
        {dialog && dialog.kind !== 'plan-edit' && <ConfirmActionDialog title={`${t(dialog.row.is_active ? 'pf_deactivate' : 'pf_activate')} — ${dialog.row.name ?? dialog.row.code}`} danger={dialog.row.is_active} confirmLabel={t(dialog.row.is_active ? 'pf_deactivate' : 'pf_activate')} impact={<p>{t(dialog.kind === 'plans' ? 'pf_impact_toggle_plan' : 'pf_impact_toggle_coupon')}</p>} onClose={() => setDialog(null)} onConfirm={({ reason }) => api.act('post', `/super-admin/${dialog.kind}/${dialog.row.id}/toggle`, { is_active: !dialog.row.is_active, reason })} />}
        {api.stepUpDialog}
    </SuperAdminLayout>;
}
