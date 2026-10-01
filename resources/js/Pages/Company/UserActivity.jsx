import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';

export default function UserActivity({ profile, activity }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    return <CompanyLayout title={`${t('activityLog')}: ${profile.name}`}>
        <Link href={`/settings/users/${profile.id}`} className="text-sm underline">{t('backToProfile')}</Link>
        <h1 className="mt-3 text-2xl font-semibold">{t('activityLog')} · {profile.name}</h1>
        <div className="mt-6 divide-y divide-[#d7ddd5] border-y border-[#d7ddd5]">{activity.data.map((item) => <div key={item.id} className="py-3"><p className="font-medium">{item.event}</p><p className="mt-1 text-xs text-[#637067]">{new Date(item.created_at).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US')} · {item.subject_type ?? t('system')} {item.subject_id ?? ''}</p></div>)}{activity.data.length === 0 && <p className="py-4 text-sm text-[#637067]">{t('noActivityRecorded')}</p>}</div>
        <nav className="mt-4 flex gap-2 text-sm" aria-label={t('pages')}>{activity.links.map((link, index) => link.url ? <Link key={index} href={link.url} className="border border-[#c9d0c8] px-2 py-1">{link.label.replace(/<[^>]*>/g, '').trim()}</Link> : <span key={index} className="px-2 py-1 text-[#8a928b]">{link.label.replace(/<[^>]*>/g, '').trim()}</span>)}</nav>
    </CompanyLayout>;
}
