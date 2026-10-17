import { useState } from 'react';
import { ConfirmActionDialog, Notice, StatusBadge, usePreview, usePt } from './ui';
import { findPlan, planChangeView } from './planChange';
import { durationLabel, durationSummary } from '../../i18n-durations';

export function UsageTable({ usage, over, t }) {
    const rows = Object.entries(usage ?? {});
    const overLimit = Array.isArray(over) ? over : [];

    return <table className="erp-table"><thead><tr><th>{t('pf_resource')}</th><th>{t('pf_usage')}</th><th>{t('pf_limit')}</th></tr></thead><tbody>
        {rows.map(([key, row]) => <tr key={key}><td>{t(`pf_res_${key}`)}</td><td>{row?.usage ?? 0}</td><td>{row?.limit ?? t('pf_unlimited')} {overLimit.includes(key) && <StatusBadge status="failed" label={t('pf_over_limit')} />}</td></tr>)}
    </tbody></table>;
}

function Impact({ view, preview, t, date, money, locale }) {
    if (view.kind === 'empty') return <Notice type="error">{t('pf_no_plans')}</Notice>;
    if (view.kind === 'choose') return <p>{t('pf_choose_plan')}</p>;
    if (view.kind === 'stale') return <Notice type="error">{t('pf_plan_stale')}</Notice>;
    if (view.kind === 'loading') return <p role="status">{t('pf_loading')}</p>;
    if (view.kind === 'error') return <Notice type="error">{view.status === 403 ? t('pf_err_403') : t('pf_preview_failed')}</Notice>;

    const { info, plan } = view;

    return <>
        <p>{info.from ?? '—'} → <strong>{info.to ?? plan.name}</strong>. {t('pf_plan_keeps_date')} ({info.ends_at ? date(info.ends_at) : t('pf_lifetime')})</p>
        <dl className="impact-list">
            <div><dt>{t('pf_price')}</dt><dd>{money(plan.price ?? 0)} {t('currency')}</dd></div>
            <div><dt>{t('pf_duration')}</dt><dd>{plan.duration_info ? `${durationLabel(locale, plan.duration_info)} · ${durationSummary(locale, plan.duration_info)}` : '—'}</dd></div>
        </dl>
        {info.same_plan && <Notice type="error">{t('pf_same_plan')}</Notice>}
        {info.inactive && <Notice type="error">{t('pf_plan_inactive')}</Notice>}
        <UsageTable usage={info.usage} over={info.over_limit} t={t} />
        {info.over_limit.length > 0 && <Notice type="error">{t('pf_downgrade_blocked')}</Notice>}
        <p className="permission-note">{t('pf_features_deprecated')}</p>
    </>;
}

/** Selecting a plan only updates local state and a read-only GET preview; the POST happens on explicit confirmation. */
export default function PlanChangeDialog({ subscription, plans, onClose, api }) {
    const { t, date, money, locale } = usePt();
    const [planId, setPlanId] = useState('');
    const availablePlans = Array.isArray(plans) ? plans : [];
    const selected = findPlan(availablePlans, planId);
    const lookupId = selected ? selected.id : '';
    const companyId = subscription?.company_id;
    const preview = usePreview({ company_id: companyId, action: 'plan', plan_id: lookupId }, lookupId !== '' && Boolean(companyId));
    const view = planChangeView({ planId, plans: availablePlans, preview });

    return <ConfirmActionDialog title={`${t('pf_change_plan')} — ${subscription?.company ?? ''}`} confirmLabel={t('pf_change_plan')} danger disabled={view.blocked} resetKey={planId} onClose={onClose}
        onConfirm={({ reason }) => (selected ? api.act('post', `/super-admin/companies/${companyId}/plan`, { plan_id: selected.id, reason }) : Promise.reject(new Error('cancelled')))}
        impact={<Impact view={view} preview={preview} t={t} date={date} money={money} locale={locale} />}>
        <label className="field"><span>{t('pf_plan')}</span>
            <select name="plan_id" required value={planId} disabled={availablePlans.length === 0} onChange={(event) => setPlanId(event.target.value)}>
                <option value="">—</option>
                {availablePlans.map((plan) => <option key={plan.id} value={String(plan.id)}>{plan.name} · {money(plan.price ?? 0)} {t('currency')}</option>)}
            </select>
        </label>
    </ConfirmActionDialog>;
}
