import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';
import { permissionDescription, permissionLabel, permissionModule } from '../../permissions';

function groupPermissions(permissions, locale) {
    return permissions.reduce((groups, permission) => {
        const module = permissionModule(locale, permission.name);
        groups[module] ??= [];
        groups[module].push(permission);
        return groups;
    }, {});
}

function PermissionGrid({ permissions, selected, onChange, locale, compact = false }) {
    const groups = groupPermissions(permissions, locale);
    const toggle = (name, checked) => onChange(checked ? [...new Set([...selected, name])] : selected.filter((permission) => permission !== name));
    return <div className={`permission-grid ${compact ? 'permission-grid--compact' : ''}`}>
        {Object.entries(groups).map(([module, items]) => <fieldset className="permission-group" key={module}><legend>{module}<span>{items.length}</span></legend><div className="permission-options">{items.map(({ name }) => {
            const description = permissionDescription(locale, name);
            return <label className="permission-option" key={name} title={description}><input type="checkbox" checked={selected.includes(name)} onChange={(event) => toggle(name, event.target.checked)} /><span className="permission-option__copy"><strong>{permissionLabel(locale, name)}</strong><small>{name}</small><em>{description}</em></span></label>;
        })}</div></fieldset>)}
    </div>;
}

function selectedIds(event) {
    return Array.from(event.target.selectedOptions, (option) => Number(option.value));
}

export default function Access({ roles, users, permissions, canViewActivity }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const [notice, setNotice] = useState({ type: '', message: '' });
    const [roleName, setRoleName] = useState('');
    const [selectedPermissions, setSelectedPermissions] = useState([]);
    const [user, setUser] = useState({ first_name: '', second_name: '', email: '', password: '', password_confirmation: '', roles: [], permissions: [] });
    const [editing, setEditing] = useState(null);
    const [resetting, setResetting] = useState(null);
    const [resetPassword, setResetPassword] = useState('');

    async function request(path, method, body) {
        try {
            const response = await fetch(`/api/v1/${path}`, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' }, body: body ? JSON.stringify(body) : undefined });
            const data = await response.json();
            if (!response.ok) throw new Error(data.errors ? Object.values(data.errors).flat().join(' ') : data.message ?? t('requestFailed'));
            window.location.reload();
        } catch (error) { setNotice({ type: 'error', message: error.message }); }
    }

    const beginEdit = (item) => setEditing({ ...item, roles: item.role_ids, permissions: item.effective_permissions ?? item.direct_permissions ?? [] });
    const ownerCanEditSelf = (item) => item.is_current_user && item.is_company_owner;

    return <CompanyLayout title={t('usersAccess')}>
        <div className="erp-page-header"><div><span className="erp-eyebrow">{t('navCompany')} / {t('usersAccess')}</span><h1>{t('usersAccess')}</h1><p>{t('accessPageIntro')}</p></div><div className="access-summary"><strong>{users.total ?? users.data.length}</strong><span>{t('users')}</span></div></div>
        {notice.message && <div role="alert" className={`notice-card ${notice.type}`}>{notice.message}</div>}

        <section className="erp-section access-users-section"><div className="erp-section__header"><div><h2>{t('users')}</h2><p>{t('usersTableHint')}</p></div><span className="section-count">{users.data.length}</span></div><div className="erp-table-wrap"><table className="erp-table users-table"><thead><tr><th>{t('nameEmail')}</th><th>{t('roles')}</th><th>{t('registered')}</th><th>{t('lastLogin')}</th><th>{t('status')}</th><th>{t('actions')}</th></tr></thead><tbody>{users.data.map((item) => <tr key={item.id}><td><div className="user-table-cell"><span className="user-table-avatar">{item.name?.split(' ').map((part) => part[0]).join('').slice(0, 2)}</span><span><strong>{item.name}</strong><small>{item.email}</small></span></div></td><td><div className="role-pills">{item.roles.map((role) => <span key={role} className="role-pill">{role}</span>)}</div><small className="table-subtext">{item.effective_permissions?.length ?? 0} {t('permissions')}</small></td><td>{item.created_at ? new Date(item.created_at).toLocaleDateString(locale) : '—'}</td><td>{item.last_login_at ? new Date(item.last_login_at).toLocaleString(locale) : '—'}</td><td><span className={`status-pill ${item.status === 'active' ? 'success' : 'muted-pill'}`}><i />{item.status === 'active' ? t('active') : t('disabled')}</span></td><td><div className="row-actions">{(item.is_current_user || !item.is_company_owner) && <button className="erp-link-button" type="button" onClick={() => beginEdit(item)}>{item.is_current_user ? t('manageMyPermissions') : t('edit')}</button>}<Link className="erp-link" href={`/settings/users/${item.id}`}>{t('profile')}</Link>{canViewActivity && <Link className="erp-link" href={`/settings/users/${item.id}/activity`}>{t('activity')}</Link>}{!item.is_company_owner && !item.is_current_user && <><button className="erp-link-button" type="button" onClick={() => request(`users/${item.id}/status`, 'PATCH', { active: item.status !== 'active' })}>{item.status === 'active' ? t('deactivate') : t('activate')}</button><button className="erp-link-button" type="button" onClick={() => { setResetting(item); setResetPassword(''); }}>{t('resetPassword')}</button></>}</div></td></tr>)}</tbody></table></div><nav className="pagination" aria-label={t('userListPages')}>{users.links.map((link, index) => { const label = link.label.replace(/<[^>]*>/g, '').replace(/&laquo;|&raquo;/g, '').trim(); return link.url ? <Link key={index} href={link.url} className="erp-button erp-button--secondary">{label}</Link> : <span key={index} className="erp-button erp-button--secondary" aria-disabled="true">{label}</span>; })}</nav></section>

        <div className="access-forms-grid"><section className="erp-section access-form-section"><div className="erp-section__header"><div><h2>{t('createUser')}</h2><p>{t('createUserHint')}</p></div></div><form className="admin-form access-form" onSubmit={(event) => { event.preventDefault(); request('users', 'POST', user); }}><div className="form-grid"><label className="field"><span>{t('firstName')}</span><input required value={user.first_name} onChange={(event) => setUser({ ...user, first_name: event.target.value })} /></label><label className="field"><span>{t('secondName')}</span><input required value={user.second_name} onChange={(event) => setUser({ ...user, second_name: event.target.value })} /></label><label className="field field--wide"><span>{t('email')}</span><input required type="email" value={user.email} onChange={(event) => setUser({ ...user, email: event.target.value })} /></label><label className="field"><span>{t('initialPassword')}</span><input required minLength="8" type="password" value={user.password} onChange={(event) => setUser({ ...user, password: event.target.value })} /></label><label className="field"><span>{t('confirmPassword')}</span><input required minLength="8" type="password" value={user.password_confirmation} onChange={(event) => setUser({ ...user, password_confirmation: event.target.value })} /></label><label className="field field--wide"><span>{t('assignRole')}</span><select required multiple value={user.roles} onChange={(event) => setUser({ ...user, roles: selectedIds(event) })}>{roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}</select><small>{t('multiSelectHint')}</small></label></div><div className="permission-editor"><h3>{t('directPermissions')}</h3><p>{t('directPermissionsHint')}</p><PermissionGrid permissions={permissions} selected={user.permissions} onChange={(value) => setUser({ ...user, permissions: value })} locale={locale} compact /></div><button className="erp-button erp-button--primary">{t('createUser')}</button></form></section>

        <section className="erp-section access-form-section"><div className="erp-section__header"><div><h2>{t('customRole')}</h2><p>{t('customRoleHint')}</p></div></div><form className="admin-form access-form" onSubmit={(event) => { event.preventDefault(); request('roles', 'POST', { name: roleName, permissions: selectedPermissions }); }}><label className="field"><span>{t('roleName')}</span><input required value={roleName} onChange={(event) => setRoleName(event.target.value)} /></label><div className="permission-editor"><div className="permission-editor__heading"><div><h3>{t('permissions')}</h3><p>{t('permissionHelp')}</p></div><span className="permission-count">{selectedPermissions.length}</span></div><PermissionGrid permissions={permissions} selected={selectedPermissions} onChange={setSelectedPermissions} locale={locale} /></div><button className="erp-button erp-button--primary">{t('createRole')}</button></form></section></div>

        {editing && <div className="modal-backdrop"><form className="modal modal--wide" onSubmit={(event) => { event.preventDefault(); request(`users/${editing.id}`, 'PUT', editing); }}><div className="modal-header"><div><span className="erp-eyebrow">{editing.is_current_user ? t('myProfile') : t('editUser')}</span><h2>{editing.name}</h2><p>{editing.is_company_owner ? t('ownerPermissionHint') : t('editUserHint')}</p></div><button type="button" onClick={() => setEditing(null)} aria-label={t('close')}>×</button></div><div className="modal-body"><div className="form-grid"><label className="field"><span>{t('firstName')}</span><input required value={editing.first_name ?? ''} onChange={(event) => setEditing({ ...editing, first_name: event.target.value })} /></label><label className="field"><span>{t('secondName')}</span><input required value={editing.second_name ?? ''} onChange={(event) => setEditing({ ...editing, second_name: event.target.value })} /></label><label className="field field--wide"><span>{t('email')}</span><input required type="email" value={editing.email} onChange={(event) => setEditing({ ...editing, email: event.target.value })} /></label><label className="field field--wide"><span>{t('roles')}</span><select required multiple value={editing.roles} onChange={(event) => setEditing({ ...editing, roles: selectedIds(event) })}>{roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}</select></label></div><div className="permission-editor"><div className="permission-editor__heading"><div><h3>{t('permissions')}</h3><p>{t('permissionHelp')}</p></div><span className="permission-count">{editing.permissions.length}</span></div><PermissionGrid permissions={permissions} selected={editing.permissions} onChange={(value) => setEditing({ ...editing, permissions: value })} locale={locale} /></div></div><div className="modal-footer"><button type="button" className="erp-button erp-button--secondary" onClick={() => setEditing(null)}>{t('cancel')}</button><button className="erp-button erp-button--primary">{t('saveUser')}</button></div></form></div>}
        {resetting && <div className="modal-backdrop"><form className="modal" onSubmit={(event) => { event.preventDefault(); request(`users/${resetting.id}/password`, 'PUT', { password: resetPassword, password_confirmation: resetPassword }); }}><div className="modal-header"><div><h2>{t('resetPasswordFor')} {resetting.name}</h2></div><button type="button" onClick={() => setResetting(null)} aria-label={t('close')}>×</button></div><div className="modal-body"><label className="field"><span>{t('newPassword')}</span><input required minLength="8" autoComplete="new-password" type="password" value={resetPassword} onChange={(event) => setResetPassword(event.target.value)} /></label></div><div className="modal-footer"><button type="button" className="erp-button erp-button--secondary" onClick={() => setResetting(null)}>{t('cancel')}</button><button className="erp-button erp-button--primary">{t('updatePassword')}</button></div></form></div>}
    </CompanyLayout>;
}
