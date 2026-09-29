import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';

function groupPermissions(permissions) {
    return permissions.reduce((groups, permission) => {
        const [module] = permission.name.split('.', 1);
        groups[module] ??= [];
        groups[module].push(permission);
        return groups;
    }, {});
}

export default function Access({ roles, users, permissions, canViewActivity }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const text = (arabic, english) => locale === 'ar' ? arabic : english;
    const permissionGroups = groupPermissions(permissions);
    const [notice, setNotice] = useState('');
    const [roleName, setRoleName] = useState('');
    const [selectedPermissions, setSelectedPermissions] = useState([]);
    const [user, setUser] = useState({ first_name: '', second_name: '', email: '', password: '', password_confirmation: '', roles: [], permissions: [] });
    const [editing, setEditing] = useState(null);
    const [resetting, setResetting] = useState(null);
    const [resetPassword, setResetPassword] = useState('');

    async function request(path, method, body) {
        const response = await fetch(`/api/v1/${path}`, {
            method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: body ? JSON.stringify(body) : undefined,
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message ?? 'Request failed.');
        window.location.reload();
    }

    async function createRole(event) {
        event.preventDefault();
        try { await request('roles', 'POST', { name: roleName, permissions: selectedPermissions }); } catch (error) { setNotice(error.message); }
    }

    async function createUser(event) {
        event.preventDefault();
        try { await request('users', 'POST', user); } catch (error) { setNotice(error.message); }
    }

    async function updateUser(event) {
        event.preventDefault();
        try { await request(`users/${editing.id}`, 'PUT', editing); } catch (error) { setNotice(error.message); }
    }

    async function changeStatus(item) {
        try { await request(`users/${item.id}/status`, 'PATCH', { active: item.status !== 'active' }); } catch (error) { setNotice(error.message); }
    }

    async function submitReset(event) {
        event.preventDefault();
        try { await request(`users/${resetting.id}/password`, 'PUT', { password: resetPassword, password_confirmation: resetPassword }); } catch (error) { setNotice(error.message); }
    }

    return (
        <CompanyLayout title={t('usersAccess')}>
            <h1 className="text-2xl font-semibold">{t('usersAccess')}</h1>
            {notice && <p role="alert" className="mt-4 border-l-4 border-red-700 bg-white p-3 text-sm">{notice}</p>}
            <section className="mt-8 border-y border-[#d7ddd5] py-5">
                <h2 className="font-semibold">{t('users')}</h2>
                <div className="mt-3 overflow-x-auto">
                    <table className="w-full min-w-[1120px] border-collapse text-left text-sm">
                        <thead><tr className="border-y border-[#d7ddd5] text-xs text-[#637067]"><th className="py-3 pr-4">{text('الاسم / البريد', 'Name / email')}</th><th className="py-3 pr-4">{text('تاريخ التسجيل', 'Registered')}</th><th className="py-3 pr-4">{text('آخر دخول', 'Last login')}</th><th className="py-3 pr-4">{text('الدخول الناجح', 'Successful logins')}</th><th className="py-3 pr-4">{text('المحاولات الفاشلة', 'Failed attempts')}</th><th className="py-3 pr-4">{text('الأدوار / الحالة', 'Roles / status')}</th><th className="py-3">{text('الإجراءات', 'Actions')}</th></tr></thead>
                        <tbody>{users.data.map((item) => <tr key={item.id} className="border-b border-[#d7ddd5] align-top">
                            <td className="py-3 pr-4"><span className="font-medium">{item.name}</span><br /><span className="text-xs text-[#637067]">{item.email}</span></td>
                            <td className="py-3 pr-4">{item.created_at ? new Date(item.created_at).toLocaleDateString() : '-'}</td>
                            <td className="py-3 pr-4">{item.last_login_at ? new Date(item.last_login_at).toLocaleString() : '-'}</td>
                            <td className="py-3 pr-4">{canViewActivity ? <Link className="underline" href={`/settings/users/${item.id}/logins`}>{item.login_count}</Link> : item.login_count}</td>
                            <td className="py-3 pr-4">{item.failed_login_attempts}</td>
                            <td className="py-3 pr-4">{item.roles.join(', ')}<br /><span className="text-xs text-[#637067]">{item.status}</span></td>
                            <td className="py-3"><div className="flex flex-wrap gap-2">
                                {!item.is_company_owner && !item.is_current_user && <button type="button" onClick={() => setEditing({ ...item, roles: item.role_ids, permissions: item.direct_permissions })} className="underline">{t('edit')}</button>}
                                <Link href={`/settings/users/${item.id}`} className="underline">{t('profile')}</Link>
                                {canViewActivity && <Link href={`/settings/users/${item.id}/activity`} className="underline">{t('activity')}</Link>}
                                {!item.is_company_owner && !item.is_current_user && <button type="button" onClick={() => changeStatus(item)} className="underline">{item.status === 'active' ? t('deactivate') : t('activate')}</button>}
                                {!item.is_company_owner && !item.is_current_user && <button type="button" onClick={() => { setResetting(item); setResetPassword(''); }} className="underline">{t('resetPassword')}</button>}
                            </div></td>
                        </tr>)}</tbody>
                    </table>
                </div>
                <nav aria-label="User list pages" className="mt-3 flex gap-2 text-sm">{users.links.map((link, index) => {
                    const label = link.label.replace(/<[^>]*>/g, '').replace(/&laquo;|&raquo;/g, '').trim();
                    return link.url ? <Link key={index} href={link.url} className="border border-[#c9d0c8] px-2 py-1">{label}</Link> : <span key={index} className="px-2 py-1 text-[#8a928b]">{label}</span>;
                })}</nav>
                <form onSubmit={createUser} className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <input aria-label={t('firstName')} placeholder={t('firstName')} value={user.first_name} onChange={(event) => setUser({ ...user, first_name: event.target.value })} className="border border-[#c9d0c8] bg-white px-3 py-2" />
                    <input aria-label={t('secondName')} placeholder={t('secondName')} value={user.second_name} onChange={(event) => setUser({ ...user, second_name: event.target.value })} className="border border-[#c9d0c8] bg-white px-3 py-2" />
                    <input aria-label={t('email')} type="email" placeholder={t('email')} value={user.email} onChange={(event) => setUser({ ...user, email: event.target.value })} className="border border-[#c9d0c8] bg-white px-3 py-2" />
                    <input aria-label={t('initialPassword')} type="password" placeholder={t('initialPassword')} value={user.password} onChange={(event) => setUser({ ...user, password: event.target.value })} className="border border-[#c9d0c8] bg-white px-3 py-2" />
                    <input aria-label={t('confirmPassword')} type="password" placeholder={t('confirmPassword')} value={user.password_confirmation} onChange={(event) => setUser({ ...user, password_confirmation: event.target.value })} className="border border-[#c9d0c8] bg-white px-3 py-2" />
                    <select aria-label={t('assignRole')} multiple value={user.roles} onChange={(event) => setUser({ ...user, roles: Array.from(event.target.selectedOptions, (option) => Number(option.value)) })} className="min-h-20 border border-[#c9d0c8] bg-white px-3 py-2 sm:col-span-2">{roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}</select>
                    <div className="grid gap-4 sm:col-span-2">{Object.entries(permissionGroups).map(([module, items]) => <fieldset key={module}><legend className="mb-2 text-xs font-semibold uppercase text-[#637067]">{module}</legend><div className="grid gap-2 sm:grid-cols-2">{items.map(({ name }) => <label key={name} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={user.permissions.includes(name)} onChange={(event) => setUser({ ...user, permissions: event.target.checked ? [...user.permissions, name] : user.permissions.filter((permission) => permission !== name) })} />{name}</label>)}</div></fieldset>)}</div>
                    <button className="bg-[#34795c] px-3 py-2 text-white lg:col-span-2">{t('createUser')}</button>
                </form>
            </section>
            {editing && <section className="fixed inset-0 z-20 grid place-items-center bg-black/40 p-4"><form onSubmit={updateUser} className="max-h-[90vh] w-full max-w-2xl overflow-y-auto bg-white p-6 shadow-xl">
                <div className="flex items-center justify-between"><h2 className="text-lg font-semibold">{t('edit')} {editing.name}</h2><button type="button" onClick={() => setEditing(null)} aria-label={t('close')}>×</button></div>
                <div className="mt-5 grid gap-3 sm:grid-cols-2"><input aria-label={t('firstName')} value={editing.first_name ?? ''} onChange={(event) => setEditing({ ...editing, first_name: event.target.value })} placeholder={t('firstName')} className="border border-[#c9d0c8] px-3 py-2" /><input aria-label={t('secondName')} value={editing.second_name ?? ''} onChange={(event) => setEditing({ ...editing, second_name: event.target.value })} placeholder={t('secondName')} className="border border-[#c9d0c8] px-3 py-2" /><input aria-label={t('email')} type="email" value={editing.email} onChange={(event) => setEditing({ ...editing, email: event.target.value })} className="border border-[#c9d0c8] px-3 py-2 sm:col-span-2" /></div>
                <label className="mt-5 block text-sm font-medium">{t('roles')}<select multiple value={editing.roles} onChange={(event) => setEditing({ ...editing, roles: Array.from(event.target.selectedOptions, (option) => Number(option.value)) })} className="mt-2 min-h-24 w-full border border-[#c9d0c8] bg-white px-3 py-2">{roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}</select></label>
                <div className="mt-4 grid gap-4">{Object.entries(permissionGroups).map(([module, items]) => <fieldset key={module}><legend className="mb-2 text-xs font-semibold uppercase text-[#637067]">{module}</legend><div className="grid gap-2 sm:grid-cols-2">{items.map(({ name }) => <label key={name} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={(editing.permissions ?? []).includes(name)} onChange={(event) => setEditing({ ...editing, permissions: event.target.checked ? [...(editing.permissions ?? []), name] : editing.permissions.filter((permission) => permission !== name) })} />{name}</label>)}</div></fieldset>)}</div>
                <button className="mt-5 bg-[#34795c] px-4 py-2 text-white">{t('saveUser')}</button>
            </form></section>}
            {resetting && <section className="fixed inset-0 z-20 grid place-items-center bg-black/40 p-4"><form onSubmit={submitReset} className="w-full max-w-md bg-white p-6 shadow-xl"><div className="flex items-center justify-between"><h2 className="font-semibold">{t('resetPassword')} · {resetting.name}</h2><button type="button" onClick={() => setResetting(null)} aria-label={t('close')}>×</button></div><input autoComplete="new-password" aria-label={t('newPassword')} type="password" minLength="8" required value={resetPassword} onChange={(event) => setResetPassword(event.target.value)} className="mt-5 w-full border border-[#c9d0c8] px-3 py-2" /><button className="mt-4 bg-[#34795c] px-4 py-2 text-white">{t('updatePassword')}</button></form></section>}
            <section className="mt-8 border-b border-[#d7ddd5] pb-6">
                <h2 className="font-semibold">{t('customRole')}</h2>
                <form onSubmit={createRole} className="mt-4">
                    <input aria-label={t('roleName')} placeholder={t('roleName')} value={roleName} onChange={(event) => setRoleName(event.target.value)} className="w-full max-w-sm border border-[#c9d0c8] bg-white px-3 py-2" />
                    <div className="mt-4 grid gap-4">{Object.entries(permissionGroups).map(([module, items]) => <fieldset key={module}><legend className="mb-2 text-xs font-semibold uppercase text-[#637067]">{module}</legend><div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{items.map(({ name }) => <label key={name} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={selectedPermissions.includes(name)} onChange={(event) => setSelectedPermissions(event.target.checked ? [...selectedPermissions, name] : selectedPermissions.filter((item) => item !== name))} />{name}</label>)}</div></fieldset>)}</div>
                    <button className="mt-4 border border-[#34795c] px-4 py-2 text-sm">{t('create')} {t('customRole')}</button>
                </form>
            </section>
        </CompanyLayout>
    );
}