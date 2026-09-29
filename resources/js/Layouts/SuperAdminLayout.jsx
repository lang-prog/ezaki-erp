import { Head, Link, usePage } from '@inertiajs/react';
import { translate } from '../i18n';

export default function SuperAdminLayout({ title, children }) {
    const { auth, locale } = usePage().props;
    const isArabic = locale === 'ar';

    return (
        <div className="min-h-screen bg-[#f5f1ec] text-[#262522]">
            <Head title={title} />
            <header className="flex min-h-16 items-center justify-between border-b border-[#ded8cf] bg-white px-5 md:px-10">
                <Link href="/super-admin" className="font-semibold">E-Zaki <span className="text-[#a64b36]">Platform</span></Link>
                <span className="text-sm text-[#68645f]">{auth?.user?.name}</span>
                <Link href={`/locale/${isArabic ? 'en' : 'ar'}`} method="post" as="button" className="text-sm">{isArabic ? 'English' : 'العربية'}</Link>
                <Link href="/logout" method="post" as="button" className="text-sm underline">{translate(locale, 'signOut')}</Link>
            </header>
            <div className="mx-auto grid max-w-7xl md:grid-cols-[220px_1fr]">
                <aside className="border-b border-[#ded8cf] px-5 py-6 md:min-h-[calc(100vh-4rem)] md:border-b-0 md:border-r">
                    <p className="mb-3 text-xs font-semibold uppercase text-[#766f67]">{translate(locale, 'platformAdmin')}</p>
                    <Link href="/super-admin" className="block rounded px-3 py-2 text-sm hover:bg-[#eee7df]">{translate(locale, 'overview')}</Link>
                    <Link href="/super-admin#companies" className="block rounded px-3 py-2 text-sm hover:bg-[#eee7df]">{translate(locale, 'companies')}</Link>
                    <Link href="/super-admin#plans" className="block rounded px-3 py-2 text-sm hover:bg-[#eee7df]">{translate(locale, 'plans')}</Link>
                    <Link href="/super-admin#coupons" className="block rounded px-3 py-2 text-sm hover:bg-[#eee7df]">{translate(locale, 'coupons')}</Link>
                    <Link href="/super-admin#registrations" className="block rounded px-3 py-2 text-sm hover:bg-[#eee7df]">{translate(locale, 'registrationRequests')}</Link>
                </aside>
                <main className="min-w-0 px-5 py-8 md:px-10">{children}</main>
            </div>
        </div>
    );
}