import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';

export default function UserLoginHistory({ profile, logins }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    return <CompanyLayout title={`${t('loginHistory')}: ${profile.name}`}>
        <Link href={`/settings/users/${profile.id}`} className="text-sm underline">{t('backToProfile')}</Link>
        <h1 className="mt-3 text-2xl font-semibold">{t('loginHistory')} · {profile.name}</h1>
        <div className="mt-6 overflow-x-auto"><table className="w-full min-w-[650px] text-sm"><thead><tr className="border-y border-[#d7ddd5]"><th className="py-3">{t('result')}</th><th className="py-3">{t('date')}</th><th className="py-3">{t('ipAddress')}</th><th className="py-3">{t('browser')}</th></tr></thead><tbody>{logins.data.map((login) => <tr key={login.id} className="border-b border-[#d7ddd5]"><td className="py-3">{login.successful ? t('loginSuccess') : t('loginFailed')}</td><td className="py-3">{new Date(login.created_at).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US')}</td><td className="py-3" dir="ltr">{login.ip_address ?? '-'}</td><td className="py-3" dir="ltr">{login.user_agent ?? '-'}</td></tr>)}</tbody></table>{logins.data.length === 0 && <p className="py-4 text-sm text-[#637067]">{t('noLoginRecords')}</p>}</div>
    </CompanyLayout>;
}
