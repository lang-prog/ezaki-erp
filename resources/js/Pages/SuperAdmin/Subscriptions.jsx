import { useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import SuperAdminLayout from '../../Layouts/SuperAdminLayout';
import { ConfirmActionDialog, EmptyRow, Notice, PageHeader, Pagination, StatusBadge, errorText, newKey, usePlatformApi, usePt } from '../../Components/Platform/ui';

function usePreview(params, enabled = true) {
    const [state, setState] = useState({ loading: enabled, data: null, error: '' });
    const query = JSON.stringify(params);
    useEffect(() => {
        if (!enabled) return undefined;
        let cancelled = false;
        setState({ loading: true, data: null, error: '' });
        window.axios.get('/super-admin/subscriptions/preview', { params: JSON.parse(query) })
            .then((response) => !cancelled && setState({ loading: false, data: response.data, error: '' }))
            .catch((error) => !cancelled && setState({ loading: false, data: null, error: errorText(error, '') || 'error' }));
        return () => { cancelled = true; };
    }, [query, enabled]);
    return state;
}

function UsageTable({ usage, over, t }) {
    return <table className="erp-table"><thead><tr><th>{t('pf_resource')}</th><th>{t('pf_usage')}</th><th>{t('pf_limit')}</th></tr></thead><tbody>
        {Object.entries(usage).map(([key, row]) => <tr key={key}><td>{t(`pf_res_${key}`)}</td><td>{row.usage}</td><td>{row.limit ?? t('pf_unlimited')} {over.includes(key) && <StatusBadge status="failed" label={t('pf_over_limit')} />}</td></tr>)}
    </tbody></table>;
}

function PaymentDialog({ subscription, onClose, api }) {
    const { t, date, money } = usePt();
    const key = useMemo(() => newKey(), []);
    const [form, setForm] = useState({ amount: '', status: 'paid', reference: '', notes: '' });
    const preview = usePreview({ company_id: subscription.company_id, action: 'payment' });
    const info = preview.data;
    return <ConfirmActionDialog title={`${t('pf_record_payment')} — ${subscription.company}`} confirmLabel={t('pf_record_payment')} disabled={preview.loading || Boolean(preview.error)} onClose={onClose}
        onConfirm={({ reason }) => api.act('post', `/super-admin/subscriptions/${subscription.id}/payments`, { ...form, reason, idempotency_key: key })}
        impact={preview.loading ? <p>{t('pf_loading')}</p> : preview.error ? <Notice type="error">{t('pf_preview_failed')}</Notice> : <dl className="impact-list">
            <div><dt>{t('pf_current_ends')}</dt><dd>{date(info.from)}</dd></div>
            <div><dt>{t('pf_amount_due')}</dt><dd>{money(info.due)} {t('currency')}</dd></div>
            <div><dt>{t('pf_amount_entered')}</dt><dd>{form.amount === '' ? '—' : `${money(form.amount)} ${t('currency')}`}</dd></div>
            <div><dt>{t('pf_new_ends')}</dt><dd>{form.status === 'paid' ? (info.to ? date(info.to) : t('pf_lifetime')) : t('pf_no_change')}</dd></div>
            {info.company_status !== 'active' && <div><dt>{t('pf_note')}</dt><dd>{t('pf_payment_no_reactivate')}</dd></div>}
        </dl>}>
        <div className="posting-form__grid">
            <label className="field"><span>{t('amount')}</span><input type="number" min="0" step="0.01" required value={form.amount} onChange={(event) => setForm({ ...form, amount: event.target.value })} /></label>
            <label className="field"><span>{t('status')}</span><select value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })}>{['paid', 'pending', 'failed'].map((s) => <option key={s} value={s}>{t(`pf_pay_${s}`)}</option>)}</select></label>
            <label className="field"><span>{t('reference')}</span><input maxLength={255} value={form.reference} onChange={(event) => setForm({ ...form, reference: event.target.value })} /></label>
        </div>
        <p className="permission-note">{t('pf_payment_policy')}</p>
    </ConfirmActionDialog>;
}

function PlanDialog({ subscription, plans, onClose, api }) {
    const { t, date } = usePt();
    const [planId, setPlanId] = useState('');
    const preview = usePreview({ company_id: subscription.company_id, action: 'plan', plan_id: planId }, planId !== '');
    const info = preview.data;
    const blocked = !planId || preview.loading || Boolean(preview.error) || info?.same_plan || (info?.over_limit?.length ?? 0) > 0;
    return <ConfirmActionDialog title={`${t('pf_change_plan')} — ${subscription.company}`} confirmLabel={t('pf_change_plan')} danger disabled={blocked} onClose={onClose}
        onConfirm={({ reason }) => api.act('post', `/super-admin/companies/${subscription.company_id}/plan`, { plan_id: planId, reason })}
        impact={planId === '' ? <p>{t('pf_choose_plan')}</p> : preview.loading ? <p>{t('pf_loading')}</p> : preview.error ? <Notice type="error">{t('pf_preview_failed')}</Notice> : <>
            <p>{info.from} → <strong>{info.to}</strong>. {t('pf_plan_keeps_date')} ({info.ends_at ? date(info.ends_at) : t('pf_lifetime')})</p>
            {info.same_plan && <Notice type="error">{t('pf_same_plan')}</Notice>}
            <UsageTable usage={info.usage} over={info.over_limit} t={t} />
            {info.over_limit.length > 0 && <Notice type="error">{t('pf_downgrade_blocked')}</Notice>}
        </>}>
        <label className="field"><span>{t('pf_plan')}</span><select required value={planId} onChange={(event) => setPlanId(event.target.value)}><option value="">—</option>{plans.map((plan) => <option key={plan.id} value={plan.id}>{plan.name} · {plan.price} {t('currency')}</option>)}</select></label>
    </ConfirmActionDialog>;
}

function LimitsDialog({ subscription, onClose, api }) {
    const { t } = usePt();
    const [limits, setLimits] = useState({ users: 0, branches: 0, warehouses: 0 });
    const preview = usePreview({ company_id: subscription.company_id, action: 'limits', ...limits });
    const info = preview.data;
    return <ConfirmActionDialog title={`${t('pf_limits')} — ${subscription.company}`} confirmLabel={t('save')} disabled={preview.loading || Boolean(preview.error) || (info?.over_limit?.length ?? 0) > 0} onClose={onClose}
        onConfirm={({ reason }) => api.act('put', `/super-admin/companies/${subscription.company_id}/limit-addons`, { ...limits, reason })}
        impact={preview.loading ? <p>{t('pf_loading')}</p> : preview.error ? <Notice type="error">{t('pf_preview_failed')}</Notice> : <UsageTable usage={info.usage} over={info.over_limit} t={t} />}>
        <div className="posting-form__grid">{['users', 'branches', 'warehouses'].map((key) => <label key={key} className="field"><span>{t(`pf_extra_${key}`)}</span><input type="number" min="0" value={limits[key]} onChange={(event) => setLimits({ ...limits, [key]: event.target.value === '' ? 0 : Number(event.target.value) })} /></label>)}</div>
        <p className="permission-note">{t('pf_limits_note')}</p>
    </ConfirmActionDialog>;
}

export default function Subscriptions({ subscriptions, filters, payments = [], plans = [] }) {
    const { t, can, date, dateTime, money } = usePt();
    const api = usePlatformApi();
    const [dialog, setDialog] = useState(null);
    const [values, setValues] = useState(filters);
    const apply = (event) => { event.preventDefault(); router.get('/super-admin/subscriptions', Object.fromEntries(Object.entries(values).filter(([k, v]) => v && !(k === 'state' && v === 'all'))), { preserveState: true }); };
    const [resolve, setResolve] = useState(null);

    return <SuperAdminLayout title={t('pf_nav_subscriptions')}>
        <PageHeader title={t('pf_nav_subscriptions')} intro={t('pf_subscriptions_intro')} />
        <section className="erp-section">
            <form className="report-filter-bar no-print" onSubmit={apply}>
                <label className="field"><span>{t('pf_search')}</span><input type="search" value={values.q} onChange={(event) => setValues({ ...values, q: event.target.value })} /></label>
                <label className="field"><span>{t('pf_state_filter')}</span><select value={values.state} onChange={(event) => setValues({ ...values, state: event.target.value })}>{['all', 'expiring', 'lapsed', 'suspended'].map((s) => <option key={s} value={s}>{t(`pf_filter_${s}`)}</option>)}</select></label>
                <div className="erp-toolbar"><button className="erp-button erp-button--primary">{t('apply')}</button></div>
            </form>
            <div className="erp-table-wrap"><table className="erp-table">
                <thead><tr><th>{t('pf_company')}</th><th>{t('pf_plan')}</th><th>{t('pf_access_state')}</th><th>{t('pf_starts')}</th><th>{t('pf_ends')}</th><th className="no-print">{t('actions')}</th></tr></thead>
                <tbody>
                    {subscriptions.data.map((row) => <tr key={row.id}>
                        <td><strong>{row.company}</strong></td><td>{row.plan} <small className="table-subline">{money(row.price)} {t('currency')}</small></td>
                        <td><StatusBadge status={row.access_state} label={t(`pf_state_${row.access_state}`)} /></td><td>{date(row.starts_at)}</td><td>{row.ends_at ? date(row.ends_at) : t('pf_lifetime')}</td>
                        <td className="no-print"><div className="row-actions">
                            {can('subscriptions.payments') && <button type="button" className="table-action" onClick={() => setDialog({ type: 'payment', row })}>{t('pf_record_payment')}</button>}
                            {can('subscriptions.plan_change') && <button type="button" className="table-action table-action--danger" onClick={() => setDialog({ type: 'plan', row })}><span aria-hidden="true">⚠ </span>{t('pf_change_plan')}</button>}
                            {can('subscriptions.limits') && <button type="button" className="table-action" onClick={() => setDialog({ type: 'limits', row })}>{t('pf_limits')}</button>}
                        </div></td>
                    </tr>)}
                    {subscriptions.data.length === 0 && <EmptyRow colSpan={6} />}
                </tbody>
            </table></div>
            <Pagination links={subscriptions.links} />
        </section>
        <section className="erp-section" aria-labelledby="payments-title">
            <div className="erp-section__header"><div><h2 id="payments-title">{t('pf_recent_payments')}</h2><p>{t('pf_payments_hint')}</p></div></div>
            <div className="erp-table-wrap"><table className="erp-table">
                <thead><tr><th>{t('pf_company')}</th><th>{t('amount')}</th><th>{t('status')}</th><th>{t('reference')}</th><th>{t('pf_when')}</th><th>{t('pf_new_ends')}</th><th className="no-print">{t('actions')}</th></tr></thead>
                <tbody>
                    {payments.map((payment) => <tr key={payment.id}><td>{payment.company}</td><td>{money(payment.amount)} {t('currency')}</td><td><StatusBadge status={payment.status} label={t(`pf_pay_${payment.status}`)} /></td><td>{payment.reference ?? '—'}</td><td>{dateTime(payment.paid_at ?? payment.created_at)}</td><td>{date(payment.new_ends_at)}</td>
                        <td className="no-print">{payment.status === 'pending' && can('subscriptions.payments') && <div className="row-actions"><button type="button" className="table-action" onClick={() => setResolve({ payment, status: 'paid' })}>{t('pf_mark_paid')}</button><button type="button" className="table-action table-action--danger" onClick={() => setResolve({ payment, status: 'failed' })}>{t('pf_mark_failed')}</button></div>}</td></tr>)}
                    {payments.length === 0 && <EmptyRow colSpan={7} />}
                </tbody>
            </table></div>
        </section>
        {dialog?.type === 'payment' && <PaymentDialog subscription={dialog.row} api={api} onClose={() => setDialog(null)} />}
        {dialog?.type === 'plan' && <PlanDialog subscription={dialog.row} plans={plans} api={api} onClose={() => setDialog(null)} />}
        {dialog?.type === 'limits' && <LimitsDialog subscription={dialog.row} api={api} onClose={() => setDialog(null)} />}
        {resolve && <ConfirmActionDialog title={t(resolve.status === 'paid' ? 'pf_mark_paid' : 'pf_mark_failed')} danger={resolve.status === 'failed'} confirmLabel={t(resolve.status === 'paid' ? 'pf_mark_paid' : 'pf_mark_failed')} impact={<p>{t(resolve.status === 'paid' ? 'pf_impact_pay_pending' : 'pf_impact_fail_pending')}</p>} onClose={() => setResolve(null)} onConfirm={({ reason }) => api.act('post', `/super-admin/payments/${resolve.payment.id}/resolve`, { status: resolve.status, reason })} />}
        {api.stepUpDialog}
    </SuperAdminLayout>;
}
