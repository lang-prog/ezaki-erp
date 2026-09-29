import { Link, usePage } from '@inertiajs/react';
import { translate } from '../i18n';

export default function Welcome() {
    const { locale } = usePage().props;
    return (
        <main className="relative flex min-h-screen items-center overflow-hidden bg-[#17251f] px-6 py-16 text-[#f4f1e9]">
            <div className="absolute inset-y-0 right-0 hidden w-[42%] border-l border-[#61746a] bg-[linear-gradient(135deg,#273c32_0%,#17251f_68%)] md:block" />
            <section className="relative mx-auto w-full max-w-6xl">
                <div className="mb-6 flex items-center justify-between text-sm uppercase text-[#b7c8b9]"><p>E-Zaki ERP · {translate(locale, 'steelTrade')}</p><Link href={`/locale/${locale === 'ar' ? 'en' : 'ar'}`} method="post" as="button">{locale === 'ar' ? 'English' : 'العربية'}</Link></div>
                <h1 className="max-w-3xl text-5xl font-semibold leading-tight md:text-6xl">{translate(locale, 'clearControl')}</h1>
                <div className="mt-10 flex flex-wrap gap-3">
                    <Link href="/login" className="bg-[#d7a94f] px-5 py-3 font-medium text-[#20251f]">{translate(locale, 'companySignIn')}</Link>
                    <Link href="/super-admin/login" className="border border-[#8fa196] px-5 py-3">{translate(locale, 'platformSignIn')}</Link>
                </div>
            </section>
        </main>
    );
}