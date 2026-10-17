import { useState } from 'react';
import { Link } from '@inertiajs/react';
import SuperAdminLayout from '../../Layouts/SuperAdminLayout';
import { ConfirmActionDialog, EmptyRow, PageHeader, Pagination, StatusBadge, usePlatformApi, usePt } from '../../Components/Platform/ui';

export default function Requests({ status, requests }) {
    const { t, can, dateTime } = usePt();
    const api = usePlatformApi();
    const [dialog, setDialog] = useState(null);
    return <SuperAdminLayout title={t('pf_nav_requests')}>
        <PageHeader title={t('pf_nav_requests')} intro={t('pf_requests_intro')} />
        <nav className="report-tabs no-print" aria-label={t('pf_nav_requests')}>{['open', 'handled'].map((s) => <Link key={s} href={`/super-admin/requests?status=${s}`} aria-current={status === s ? 'page' : undefined} className={`erp-button ${status === s ? 'erp-button--primary' : 'erp-button--secondary'}`}>{t(`pf_req_${s}`)}</Link>)}</nav>
        <section className="erp-section"><div className="erp-table-wrap"><table className="erp-table">
            <thead><tr><th>{t('pf_company')}</th><th>{t('pf_request_type')}</th><th>{t('pf_message')}</th><th>{t('pf_requested_by')}</th><th>{t('pf_when')}</th><th>{t('status')}</th><th className="no-print">{t('actions')}</th></tr></thead>
            <tbody>
                {requests.data.map((row) => <tr key={row.id}><td><strong>{row.company}</strong></td><td>{t(`pf_req_type_${row.type}`)}</td><td>{row.message ?? '—'}</td><td>{row.user}<small className="table-subline">{row.email}</small></td><td>{dateTime(row.created_at)}</td><td><StatusBadge status={row.status} label={t(`pf_req_${row.status}`)} /></td>
                    <td className="no-print">{row.status === 'open' && can('subscription_requests.manage') && <button type="button" className="table-action" onClick={() => setDialog(row)}>{t('pf_mark_handled')}</button>}</td></tr>)}
                {requests.data.length === 0 && <EmptyRow colSpan={7} />}
            </tbody>
        </table></div><Pagination links={requests.links} /></section>
        {dialog && <ConfirmActionDialog title={`${t('pf_mark_handled')} — ${dialog.company}`} confirmLabel={t('pf_mark_handled')} impact={<p>{t('pf_impact_handled')}</p>} onClose={() => setDialog(null)} onConfirm={({ reason }) => api.act('post', `/super-admin/requests/${dialog.id}/handle`, { reason })} />}
        {api.stepUpDialog}
    </SuperAdminLayout>;
}
