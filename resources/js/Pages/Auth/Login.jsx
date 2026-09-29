import { Link, useForm, usePage } from '@inertiajs/react';
import { translate } from '../../i18n';

export default function Login({ accountType }) {
    const { errors, registrationOpen, locale } = usePage().props;
    const form = useForm({ email: '', password: '', remember: false });
    const platform = accountType === 'super_admin';
    const t = (key) => translate(locale, key);

    function submit(event) {
        event.preventDefault();
        form.post(platform ? '/super-admin/login' : '/login');
    }

    return (
        <main className={`grid min-h-screen md:grid-cols-2 ${platform ? 'bg-[#f4efe9]' : 'bg-[#edf2eb]'}`}>
            <section className={`flex items-center px-6 py-14 text-white md:px-14 ${platform ? 'bg-[#382c27]' : 'bg-[#1e342a]'}`}>
                <div className="mx-auto w-full max-w-lg">
                    <p className="mb-6 text-sm uppercase text-white/65">E-Zaki ERP</p>
                    <h1 className="text-4xl font-semibold leading-tight">{t(platform ? 'platformAdmin' : 'companyWorkspace')}</h1>
                    <p className="mt-4 max-w-sm text-white/75">{t(platform ? 'platformSignInCopy' : 'companySignInCopy')}</p>
                </div>
            </section>
            <section className="flex items-center px-6 py-14 md:px-14">
                <form onSubmit={submit} className="mx-auto w-full max-w-md">
                    <h2 className="text-2xl font-semibold">{t('signIn')}</h2>
                    <label className="mt-8 block text-sm font-medium">{t('email')}<input autoComplete="username" type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} className="mt-2 w-full border border-[#c9d0c8] bg-white px-3 py-3 outline-none focus:border-[#39745b]" /></label>
                    {errors.email && <p className="mt-1 text-sm text-red-700">{errors.email}</p>}
                    <label className="mt-5 block text-sm font-medium">{t('password')}<input autoComplete="current-password" type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} className="mt-2 w-full border border-[#c9d0c8] bg-white px-3 py-3 outline-none focus:border-[#39745b]" /></label>
                    <label className="mt-4 flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.remember} onChange={(event) => form.setData('remember', event.target.checked)} />{t('rememberMe')}</label>
                    <button disabled={form.processing} className="mt-7 w-full bg-[#34795c] px-4 py-3 font-medium text-white disabled:opacity-60">{t('signIn')}</button>
                    {!platform && registrationOpen && <p className="mt-5 text-sm text-[#59645c]">{t('newCompany')} <Link href="/register" className="underline">{t('createRequest')}</Link></p>}
                </form>
            </section>
        </main>
    );
}