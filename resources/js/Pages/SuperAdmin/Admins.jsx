import { useState } from 'react';
import SuperAdminLayout from '../../Layouts/SuperAdminLayout';
import { ConfirmActionDialog, EmptyRow, Notice, PageHeader, StatusBadge, errorText, usePlatformApi, usePt } from '../../Components/Platform/ui';
import { router } from '@inertiajs/react';

export default function Admins({ admins = [], roles = [] }) {
    const { t, can, dateTime } = usePt();
    const api = usePlatformApi();
    const [dialog, setDialog] = useState(null);
    const [role, setRole] = useState('');
    const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '', role: 'support' });
    const [message, setMessage] = useState({ type: '', text: '' });
    async function create(event) {
        event.preventDefault();
        setMessage({ type: '', text: '' });
        try {
            await api.call('post', '/super-admin/admins', { ...form, reason: 'create' });
            setForm({ name: '', email: '', password: '', password_confirmation: '', role: 'support' });
            setMessage({ type: 'success', text: t('pf_saved') });
            router.reload({ preserveScroll: true });
        } catch (error) {
            setMessage({ type: 'error', text: errorText(error, t('pf_error_generic')) });
        }
    }
    return <SuperAdminLayout title={t('pf_nav_admins')}>
        <PageHeader title={t('pf_nav_admins')} intro={t('pf_admins_intro')} />
        {message.text && <Notice type={message.type}>{message.text}</Notice>}
        <section className="erp-section"><div className="erp-table-wrap"><table className="erp-table">
            <thead><tr><th>{t('pf_admin')}</th><th>{t('pf_role')}</th><th>{t('status')}</th><th>MFA</th><th>{t('lastLogin')}</th><th className="no-print">{t('actions')}</th></tr></thead>
            <tbody>
                {admins.map((admin) => <tr key={admin.id}><td><strong>{admin.name}</strong><small className="table-subline">{admin.email}</small></td><td>{t(`pf_role_${admin.role ?? 'none'}`)}</td><td><StatusBadge status={admin.active ? 'active' : 'disabled'} label={t(admin.active ? 'active' : 'disabled')} /></td><td><StatusBadge status={admin.mfa_enabled ? 'enabled' : 'pending'} label={t(admin.mfa_enabled ? 'pf_mfa_on' : 'pf_mfa_off')} /></td><td>{dateTime(admin.last_login_at)}</td>
                    <td className="no-print">{can('admins.manage') && <div className="row-actions">
                        <button type="button" className="table-action" onClick={() => { setRole(admin.role ?? 'support'); setDialog({ type: 'role', admin }); }}>{t('pf_change_role')}</button>
                        <button type="button" className="table-action table-action--danger" onClick={() => setDialog({ type: 'status', admin })}><span aria-hidden="true">⚠</span>{t(admin.active ? 'pf_disable_admin' : 'pf_enable_admin')}</button>
                        {admin.mfa_enabled && <button type="button" className="table-action table-action--danger" onClick={() => setDialog({ type: 'mfa', admin })}><span aria-hidden="true">⚠</span>{t('pf_reset_mfa')}</button>}
                    </div>}</td></tr>)}
                {admins.length === 0 && <EmptyRow colSpan={6} />}
            </tbody>
        </table></div></section>
        {can('admins.manage') && <form className="erp-section posting-form" onSubmit={create}>
            <h2>{t('pf_create_admin')}</h2><p className="permission-note">{t('pf_password_policy')}</p>
            <div className="posting-form__grid">
                <label className="field"><span>{t('pf_name')}</span><input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label>
                <label className="field"><span>{t('email')}</span><input type="email" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></label>
                <label className="field"><span>{t('password')}</span><input type="password" minLength={12} required autoComplete="new-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} /></label>
                <label className="field"><span>{t('confirmPassword')}</span><input type="password" minLength={12} required autoComplete="new-password" value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} /></label>
                <label className="field"><span>{t('pf_role')}</span><select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>{roles.map((r) => <option key={r} value={r}>{t(`pf_role_${r}`)}</option>)}</select><small>{t(`pf_role_hint_${form.role}`)}</small></label>
            </div>
            <button className="erp-button erp-button--primary">{t('pf_create_admin')}</button>
        </form>}
        {dialog?.type === 'role' && <ConfirmActionDialog title={`${t('pf_change_role')} — ${dialog.admin.name}`} confirmLabel={t('save')} impact={<p>{t('pf_impact_role')}</p>} onClose={() => setDialog(null)} onConfirm={({ reason }) => api.act('put', `/super-admin/admins/${dialog.admin.id}/role`, { role, reason })}>
            <label className="field"><span>{t('pf_role')}</span><select value={role} onChange={(e) => setRole(e.target.value)}>{roles.map((r) => <option key={r} value={r}>{t(`pf_role_${r}`)}</option>)}</select></label>
        </ConfirmActionDialog>}
        {dialog?.type === 'status' && <ConfirmActionDialog title={`${t(dialog.admin.active ? 'pf_disable_admin' : 'pf_enable_admin')} — ${dialog.admin.name}`} danger={dialog.admin.active} confirmLabel={t(dialog.admin.active ? 'pf_disable_admin' : 'pf_enable_admin')} impact={<p>{t('pf_impact_admin_status')}</p>} onClose={() => setDialog(null)} onConfirm={({ reason }) => api.act('post', `/super-admin/admins/${dialog.admin.id}/status`, { active: !dialog.admin.active, reason })} />}
        {dialog?.type === 'mfa' && <ConfirmActionDialog title={`${t('pf_reset_mfa')} — ${dialog.admin.name}`} danger confirmLabel={t('pf_reset_mfa')} impact={<p>{t('pf_impact_mfa_reset')}</p>} onClose={() => setDialog(null)} onConfirm={({ reason }) => api.act('post', `/super-admin/admins/${dialog.admin.id}/mfa-reset`, { reason })} />}
        {api.stepUpDialog}
    </SuperAdminLayout>;
}
