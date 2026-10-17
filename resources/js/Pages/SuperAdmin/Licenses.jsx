import { useState } from 'react';
import SuperAdminLayout from '../../Layouts/SuperAdminLayout';
import { ConfirmActionDialog, EmptyRow, PageHeader, Pagination, StatusBadge, usePlatformApi, usePt } from '../../Components/Platform/ui';

export default function Licenses({ licenses }) {
    const { t, can, date, dateTime } = usePt();
    const api = usePlatformApi();
    const [dialog, setDialog] = useState(null);
    const [key, setKey] = useState('');
    const [target, setTarget] = useState({ installation_id: '', customer_binding: '', company_id: '' });
    const actions = (row) => [
        ...(row.status === 'disabled' ? [['enable', false]] : []), ...(row.status === 'enabled' ? [['disable', false]] : []),
        ...(row.status !== 'revoked' ? [['revoke', true], ['transfer', true]] : []),
    ];
    const payload = (reason) => (dialog.action === 'transfer' ? { ...target, company_id: target.company_id || undefined, license_key: key, reason } : { reason });
    return <SuperAdminLayout title={t('pf_nav_licenses')}>
        <PageHeader title={t('pf_nav_licenses')} intro={t('pf_licenses_intro')} />
        <section className="erp-section"><div className="erp-table-wrap"><table className="erp-table">
            <thead><tr><th>{t('pf_company')}</th><th>{t('pf_installation')}</th><th>{t('status')}</th><th>{t('pf_activated')}</th><th>{t('pf_last_verified')}</th><th>{t('pf_expires')}</th><th>{t('pf_history')}</th><th className="no-print">{t('actions')}</th></tr></thead>
            <tbody>
                {licenses.data.map((row) => <tr key={row.id}><td>{row.company ?? '—'}</td><td><code>{row.installation_id}</code></td><td><StatusBadge status={row.status} label={t(`pf_lic_${row.status}`)} /></td><td>{date(row.activated_at)}</td><td>{dateTime(row.last_verified_at)}</td><td>{row.expires_at ? date(row.expires_at) : t('pf_no_expiry')}</td>
                    <td>{row.history.map((h, i) => <small key={i} className="table-subline">{t(`pf_lic_${h.action}`)} · {date(h.at)}</small>)}</td>
                    <td className="no-print">{can('licenses.manage') && <div className="row-actions">{actions(row).map(([action, danger]) => <button key={action} type="button" className={`table-action ${danger ? 'table-action--danger' : ''}`} onClick={() => setDialog({ action, danger, row })}>{danger && <span aria-hidden="true">⚠ </span>}{t(`pf_lic_action_${action}`)}</button>)}</div>}</td></tr>)}
                {licenses.data.length === 0 && <EmptyRow colSpan={8} />}
            </tbody>
        </table></div><Pagination links={licenses.links} /></section>
        {dialog && <ConfirmActionDialog title={`${t(`pf_lic_action_${dialog.action}`)} — ${dialog.row.company ?? dialog.row.installation_id}`} danger={dialog.danger} confirmLabel={t(`pf_lic_action_${dialog.action}`)} impact={<p>{t(`pf_impact_lic_${dialog.action}`)}</p>} onClose={() => setDialog(null)} onConfirm={({ reason }) => api.act('post', `/super-admin/licenses/${dialog.row.id}/${dialog.action}`, payload(reason))}>
            {dialog.action === 'transfer' && <div className="posting-form__grid">
                <label className="field"><span>{t('pf_installation')}</span><input required value={target.installation_id} onChange={(e) => setTarget({ ...target, installation_id: e.target.value })} /></label>
                <label className="field"><span>{t('pf_customer_binding')}</span><input required value={target.customer_binding} onChange={(e) => setTarget({ ...target, customer_binding: e.target.value })} /></label>
                <label className="field"><span>{t('pf_company_id')}</span><input type="number" min="1" required value={target.company_id} onChange={(e) => setTarget({ ...target, company_id: e.target.value })} /></label>
                <label className="field field--wide"><span>{t('pf_new_license_key')}</span><textarea required rows={3} value={key} onChange={(e) => setKey(e.target.value)} autoComplete="off" spellCheck={false} /></label>
            </div>}
        </ConfirmActionDialog>}
        {api.stepUpDialog}
    </SuperAdminLayout>;
}
