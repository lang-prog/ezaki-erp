import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';
import { useCoreApi } from '../../useCoreApi';

export default function UserProfile({ profile, roles }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const { notice, processing, send } = useCoreApi();
    const [form, setForm] = useState({ first_name: profile.first_name ?? '', second_name: profile.second_name ?? '', email: profile.email ?? '', current_password: '', password: '', password_confirmation: '' });
    const update = (key, value) => setForm({ ...form, [key]: value });
    const formatDate = (value) => value ? new Date(value).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-GB') : '-';

    return <CompanyLayout title={`${t('profile')}: ${profile.name}`}>
        <div className="erp-page-header">
            <div><p className="eyebrow">{t('profile')}</p><h1>{profile.name}</h1><p>{profile.email}</p></div>
            <div className="row-actions"><Link href={`/settings/users/${profile.id}/logins`} className="secondary-button">{t('loginHistory')}</Link><Link href={`/settings/users/${profile.id}/activity`} className="secondary-button">{t('activityLog')}</Link></div>
        </div>
        <dl className="panel profile-facts">
            <div><dt>{t('status')}</dt><dd>{profile.status}</dd></div><div><dt>{t('registered')}</dt><dd>{formatDate(profile.created_at)}</dd></div>
            <div><dt>{t('lastLogin')}</dt><dd>{formatDate(profile.last_login_at)}</dd></div><div><dt>{t('successfulLogins')}</dt><dd>{profile.login_count}</dd></div>
            <div><dt>{t('failedAttempts')}</dt><dd>{profile.failed_login_attempts}</dd></div><div><dt>{t('roles')}</dt><dd>{roles.join(', ') || '-'}</dd></div>
        </dl>
        <form onSubmit={(event) => { event.preventDefault(); send('profile', 'PUT', form); }} className="panel profile-form">
            <h2>{t('editProfile')}</h2>
            {notice.message && <p role="status" className={`notice-card ${notice.type}`}>{notice.message}</p>}
            <div className="form-grid">
                <label className="field"><span>{t('firstName')}</span><input required value={form.first_name} onChange={(event) => update('first_name', event.target.value)} /></label>
                <label className="field"><span>{t('secondName')}</span><input required value={form.second_name} onChange={(event) => update('second_name', event.target.value)} /></label>
                <label className="field span-2"><span>{t('email')}</span><input required type="email" value={form.email} onChange={(event) => update('email', event.target.value)} /></label>
                <label className="field"><span>{t('currentPassword')}</span><input type="password" autoComplete="current-password" value={form.current_password} onChange={(event) => update('current_password', event.target.value)} /></label>
                <label className="field"><span>{t('newPassword')}</span><input type="password" autoComplete="new-password" value={form.password} onChange={(event) => update('password', event.target.value)} /></label>
                <label className="field"><span>{t('confirmNewPassword')}</span><input type="password" autoComplete="new-password" value={form.password_confirmation} onChange={(event) => update('password_confirmation', event.target.value)} /></label>
            </div>
            <button disabled={processing} className="primary-button">{t('saveProfile')}</button>
        </form>
    </CompanyLayout>;
}
