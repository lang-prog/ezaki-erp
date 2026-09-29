import { useForm } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { usePage } from '@inertiajs/react';
import { translate } from '../../i18n';

export default function Subscription({ subscription, status }) {
    const { locale } = usePage().props;
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });
    return (
        <CompanyLayout title="Subscription">
            <h1 className="text-2xl font-semibold">{locale === 'ar' ? 'الاشتراك' : 'Subscription'}</h1>
            <p className="mt-3">{translate(locale, 'subscriptionStatus')}: <strong>{status}</strong></p>
            {subscription && <p className="mt-2 text-sm text-[#637067]">{subscription.plan?.name} · {subscription.ends_at ?? 'Lifetime plan'}</p>}
            {!['suspended', 'archived'].includes(status) && <section className="mt-8 max-w-xl border-t border-[#d7ddd5] pt-6">
                <h2 className="font-semibold">{translate(locale, 'changePassword')}</h2>
                <form onSubmit={(event) => { event.preventDefault(); form.post('/password'); }} className="mt-4 grid gap-3">
                    <input aria-label={translate(locale, 'currentPassword')} type="password" placeholder={translate(locale, 'currentPassword')} value={form.data.current_password} onChange={(event) => form.setData('current_password', event.target.value)} className="border border-[#c9d0c8] bg-white px-3 py-2.5" />
                    <input aria-label={translate(locale, 'newPassword')} type="password" placeholder={translate(locale, 'newPassword')} value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} className="border border-[#c9d0c8] bg-white px-3 py-2.5" />
                    <input aria-label={translate(locale, 'confirmPassword')} type="password" placeholder={translate(locale, 'confirmPassword')} value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} className="border border-[#c9d0c8] bg-white px-3 py-2.5" />
                    <button className="w-fit bg-[#34795c] px-4 py-2.5 text-white">{translate(locale, 'updatePassword')}</button>
                </form>
            </section>}
        </CompanyLayout>
    );
}