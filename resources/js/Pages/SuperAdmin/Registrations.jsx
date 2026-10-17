import { useState } from 'react';
import { Link } from '@inertiajs/react';
import SuperAdminLayout from '../../Layouts/SuperAdminLayout';
import { ConfirmActionDialog, EmptyRow, PageHeader, Pagination, StatusBadge, usePlatformApi, usePt } from '../../Components/Platform/ui';

export default function Registrations({ status, registrations, plans = {} }) {
    const { t, can, dateTime } = usePt();
    const api = usePlatformApi();
    const [dialog, setDialog] = useState(null);
    return <SuperAdminLayout title={t('pf_nav_registrations')}>
        <PageHeader title={t('pf_nav_registrations')} intro={t('pf_registrations_intro')} />
        <nav className="report-tabs no-print" aria-label={t('pf_nav_registrations')}>{['pending', 'rejected', 'approved'].map((s) => <Link key={s} href={`/super-admin/registrations?status=${s}`} aria-current={status === s ? 'page' : undefined} className={`erp-button ${status === s ? 'erp-button--primary' : 'erp-button--secondary'}`}>{t(`pf_reg_${s}`)}</Link>)}</nav>
        <section className="erp-section"><div className="erp-table-wrap"><table className="erp-table">
            <thead><tr><th>{t('companyName')}</th><th>{t('ownerName')}</th><th>{t('email')}</th><th>{t('pf_plan')}</th><th>{t('pf_requested_at')}</th><th>{t('status')}</th><th className="no-print">{t('actions')}</th></tr></thead>
            <tbody>
                {registrations.data.map((row) => <tr key={row.id}><td><strong>{row.company_name}</strong></td><td>{row.owner_name}</td><td>{row.email}</td><td>{plans[row.plan_id] ?? '—'}</td><td>{dateTime(row.created_at)}</td><td><StatusBadge status={row.status} label={t(`pf_reg_${row.status}`)} />{row.review_notes && <small className="table-subline">{row.review_notes}</small>}</td>
                    <td className="no-print">{row.status === 'pending' && can('registrations.manage') && <div className="row-actions"><button type="button" className="table-action" onClick={() => setDialog({ type: 'approve', row })}>{t('approve')}</button><button type="button" className="table-action table-action--danger" onClick={() => setDialog({ type: 'reject', row })}><span aria-hidden="true">⚠ </span>{t('reject')}</button></div>}</td></tr>)}
                {registrations.data.length === 0 && <EmptyRow colSpan={7} />}
            </tbody>
        </table></div><Pagination links={registrations.links} /></section>
        {dialog && <ConfirmActionDialog title={`${t(dialog.type)} — ${dialog.row.company_name}`} danger={dialog.type === 'reject'} withReason={dialog.type === 'reject'} confirmLabel={t(dialog.type)} impact={<p>{t(dialog.type === 'approve' ? 'pf_impact_approve' : 'pf_impact_reject')}</p>} onClose={() => setDialog(null)} onConfirm={({ reason }) => api.act('post', `/super-admin/registrations/${dialog.row.id}/${dialog.type}`, dialog.type === 'reject' ? { reason } : {})} />}
        {api.stepUpDialog}
    </SuperAdminLayout>;
}
