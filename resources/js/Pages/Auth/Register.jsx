import { Link, useForm, usePage } from '@inertiajs/react';
import { translate } from '../../i18n';

export default function Register({ plans }) {
    const { errors, flash, locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const form = useForm({ company_name: '', trade_name: '', tax_number: '', owner_name: '', email: '', phone: '', country: '', city: '', address: '', password: '', password_confirmation: '', plan_id: '', coupon_code: '', terms_accepted: false });
    const fields = [
        ['company_name', 'companyName'], ['trade_name', 'tradeName'], ['tax_number', 'taxNumber'], ['owner_name', 'ownerName'],
        ['email', 'ownerEmail'], ['phone', 'phone'], ['country', 'country'], ['city', 'city'], ['address', 'address'],
        ['password', 'password'], ['password_confirmation', 'confirmPassword'], ['coupon_code', 'couponCode'],
    ];

    function submit(event) {
        event.preventDefault();
        form.post('/register');
    }

    return (
        <main className="min-h-screen bg-[#edf2eb] px-5 py-12 text-[#202823]">
            <div className="mx-auto max-w-3xl">
                <Link href="/" className="text-sm text-[#39745b]">E-Zaki ERP</Link>
                <h1 className="mt-5 text-3xl font-semibold">{t('registration')}</h1>
                {flash?.status && <p className="mt-4 border-l-4 border-[#34795c] bg-white p-4">{flash.status}</p>}
                <form onSubmit={submit} className="mt-8 grid gap-5 bg-white p-6 md:grid-cols-2">
                    {fields.map(([name, label]) => <label key={name} className={`text-sm font-medium ${name === 'address' ? 'md:col-span-2' : ''}`}>{t(label)}<input type={name.includes('password') ? 'password' : name === 'email' ? 'email' : 'text'} value={form.data[name]} onChange={(event) => form.setData(name, event.target.value)} className="mt-2 w-full border border-[#c9d0c8] px-3 py-2.5" />{errors[name] && <span className="mt-1 block text-xs text-red-700">{errors[name]}</span>}</label>)}
                    <label className="text-sm font-medium">{t('requestedPlan')}<select value={form.data.plan_id} onChange={(event) => form.setData('plan_id', event.target.value)} className="mt-2 w-full border border-[#c9d0c8] bg-white px-3 py-2.5"><option value="">{t('selectLater')}</option>{plans.map((plan) => <option key={plan.id} value={plan.id}>{plan.name} · {plan.duration}</option>)}</select></label>
                    <label className="flex items-center gap-2 self-end text-sm"><input type="checkbox" checked={form.data.terms_accepted} onChange={(event) => form.setData('terms_accepted', event.target.checked)} />{t('acceptTerms')}</label>
                    <button disabled={form.processing} className="bg-[#34795c] px-4 py-3 font-medium text-white md:col-span-2">{t('sendVerification')}</button>
                </form>
            </div>
        </main>
    );
}