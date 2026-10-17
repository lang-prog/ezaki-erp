import { useState } from 'react';
import { router } from '@inertiajs/react';
import SuperAdminLayout from '../../Layouts/SuperAdminLayout';
import { EmptyRow, PageHeader, Pagination, StatusBadge, usePt } from '../../Components/Platform/ui';

function Diff({ before, after, t }) {
    if (!before && !after) return null;
    return <details><summary>{t('pf_details')}</summary><dl className="impact-list">
        {before && <div><dt>{t('pf_before')}</dt><dd>{Object.entries(before).map(([k, v]) => `${k}: ${v ?? '—'}`).join(' · ')}</dd></div>}
        {after && <div><dt>{t('pf_after')}</dt><dd>{Object.entries(after).map(([k, v]) => `${k}: ${v ?? '—'}`).join(' · ')}</dd></div>}
    </dl></details>;
}

export default function Audit({ logs, filters = {} }) {
    const { t, dateTime } = usePt();
    const [values, setValues] = useState({ event: '', company_id: '', user_id: '', subject: '', severity: '', from: '', to: '', ...filters });
    const apply = (event) => { event.preventDefault(); router.get('/super-admin/audit', Object.fromEntries(Object.entries(values).filter(([, v]) => v)), { preserveState: true }); };
    const field = (key, label, props = {}) => <label className="field"><span>{t(label)}</span><input value={values[key]} onChange={(event) => setValues({ ...values, [key]: event.target.value })} {...props} /></label>;
    return <SuperAdminLayout title={t('pf_nav_audit')}>
        <PageHeader title={t('pf_nav_audit')} intro={t('pf_audit_intro')} />
        <section className="erp-section">
            <form className="report-filter-bar no-print" onSubmit={apply}>
                {field('event', 'pf_event')}{field('subject', 'pf_subject')}{field('company_id', 'pf_company_id', { type: 'number', min: 1 })}{field('user_id', 'pf_actor_id', { type: 'number', min: 1 })}
                <label className="field"><span>{t('pf_severity')}</span><select value={values.severity} onChange={(event) => setValues({ ...values, severity: event.target.value })}><option value="">{t('allStatuses')}</option>{['info', 'warning', 'critical'].map((s) => <option key={s} value={s}>{t(`pf_sev_${s}`)}</option>)}</select></label>
                {field('from', 'fromDate', { type: 'date' })}{field('to', 'toDate', { type: 'date' })}
                <div className="erp-toolbar"><button type="submit" className="erp-button erp-button--primary">{t('apply')}</button></div>
            </form>
            <div className="erp-table-wrap"><table className="erp-table">
                <thead><tr><th>{t('pf_when')}</th><th>{t('pf_event')}</th><th>{t('pf_severity')}</th><th>{t('pf_actor')}</th><th>{t('pf_company')}</th><th>{t('pf_subject')}</th><th>{t('pf_reason')}</th></tr></thead>
                <tbody>
                    {logs.data.map((log) => <tr key={log.id}><td>{dateTime(log.created_at)}</td><td><code>{log.event}</code><Diff before={log.before} after={log.after} t={t} />{log.request_id && <small className="table-subline">{t('pf_request_id')}: {log.request_id}</small>}</td><td><StatusBadge status={log.severity} label={t(`pf_sev_${log.severity}`)} /></td><td>{log.actor ?? t('pf_system')}{log.actor_type && <small className="table-subline">{log.actor_type}</small>}</td><td>{log.company ?? '—'}</td><td>{log.subject ?? '—'}</td><td>{log.reason ?? '—'}</td></tr>)}
                    {logs.data.length === 0 && <EmptyRow colSpan={7} />}
                </tbody>
            </table></div>
            <Pagination links={logs.links} />
        </section>
    </SuperAdminLayout>;
}
