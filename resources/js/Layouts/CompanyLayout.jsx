import { Head, Link, usePage } from '@inertiajs/react';
import { translate } from '../i18n';

export default function CompanyLayout({ title, children }) {
    const { auth, locale, subscriptionWarning, capabilities = {} } = usePage().props;
    const isArabic = locale === 'ar';

    return (
        <div className="min-h-screen bg-[#f1f3ed] text-[#202823]">
            <Head title={title} />
            <header className="flex min-h-16 items-center justify-between border-b border-[#d7ddd5] bg-white px-5 md:px-10">
                <Link href="/dashboard" className="font-semibold tracking-normal">E-Zaki <span className="text-[#34795c]">ERP</span></Link>
                <div className="flex items-center gap-4 text-sm">
                    <span>{auth?.user?.name}</span>
                    <Link href={`/locale/${isArabic ? 'en' : 'ar'}`} method="post" as="button" className="border-l border-[#d7ddd5] pl-4">{isArabic ? 'English' : 'العربية'}</Link>
                    <Link href="/logout" method="post" as="button" className="border-l border-[#d7ddd5] pl-4">{translate(locale, 'signOut')}</Link>
                </div>
            </header>
            <div className="mx-auto grid max-w-7xl md:grid-cols-[220px_1fr]">
                <aside className="border-b border-[#d7ddd5] px-5 py-6 md:min-h-[calc(100vh-4rem)] md:border-b-0 md:border-r">
                    <p className="mb-3 text-xs font-semibold uppercase text-[#68736b]">{translate(locale, 'companyWorkspace')}</p>
                    {capabilities['dashboard.view'] && <Link href="/dashboard" className="block rounded px-3 py-2 text-sm hover:bg-[#e4ebe3]">{translate(locale, 'overview')}</Link>}
                    {capabilities['users.view'] && <Link href="/settings/access" className="block rounded px-3 py-2 text-sm hover:bg-[#e4ebe3]">{translate(locale, 'usersAccess')}</Link>}
                    {capabilities['subscriptions.view'] && <Link href="/subscription" className="block rounded px-3 py-2 text-sm hover:bg-[#e4ebe3]">{translate(locale, 'settings')}</Link>}
                    {capabilities['branches.view'] && <Link href="/branches" className="block rounded px-3 py-2 text-sm hover:bg-[#e4ebe3]">{translate(locale, 'branches')}</Link>}
                    {capabilities['inventory.view'] && <Link href="/inventory" className="block rounded px-3 py-2 text-sm hover:bg-[#e4ebe3]">{translate(locale, 'inventory')}</Link>}
                    {capabilities['parties.view'] && <Link href="/customers" className="block rounded px-3 py-2 text-sm hover:bg-[#e4ebe3]">{translate(locale, 'customers')}</Link>}
                    {capabilities['parties.view'] && <Link href="/suppliers" className="block rounded px-3 py-2 text-sm hover:bg-[#e4ebe3]">{translate(locale, 'suppliers')}</Link>}
                    {capabilities['accounting.view'] && <Link href="/accounting" className="block rounded px-3 py-2 text-sm hover:bg-[#e4ebe3]">{translate(locale, 'accounting')}</Link>}
                    {capabilities['purchases.view'] && <Link href="/operations#purchases" className="block rounded px-3 py-2 text-sm hover:bg-[#e4ebe3]">{translate(locale, 'purchases')}</Link>}
                    {capabilities['sales.view'] && <Link href="/operations#sales" className="block rounded px-3 py-2 text-sm hover:bg-[#e4ebe3]">{translate(locale, 'sales')}</Link>}
                    {capabilities['reports.view'] && <Link href="/reports/journal" className="block rounded px-3 py-2 text-sm hover:bg-[#e4ebe3]">{translate(locale, 'reports')}</Link>}
                    {capabilities['fleet.view'] && <Link href="/fleet" className="block rounded px-3 py-2 text-sm hover:bg-[#e4ebe3]">{translate(locale, 'fleet')}</Link>}
                </aside>
                <main className="min-w-0 px-5 py-8 md:px-10">
                    {subscriptionWarning && <p className="mb-6 border-l-4 border-[#d7a94f] bg-white px-4 py-3 text-sm">{translate(locale, 'renewalWarning')}</p>}
                    {children}
                </main>
            </div>
        </div>
    );
}