import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { translate } from '../i18n';
import SidebarNav from '../Components/SidebarNav';

const groups = [
    { labelKey: 'navOverview', items: [['dashboard.view', 'overview', '/dashboard', '▦']] },
    { labelKey: 'navCompany', items: [['users.view', 'usersAccess', '/settings/access', '♙'], ['company.owner', 'accountingPolicies', '/settings/accounting', '⚙'], ['subscriptions.view', 'subscriptionStatus', '/subscription', '▣'], ['branches.view', 'branches', '/branches', '⌖']] },
    { labelKey: 'navOperations', items: [['sales.view', 'sales', '/operations#sales', '↗'], ['purchases.view', 'purchases', '/operations#purchases', '↙'], ['inventory.view', 'inventory', '/inventory', '▤'], ['parties.view', 'parties', '/parties', '◎'], ['fleet.view', 'fleet', '/fleet', '▱'], ['accounting.view', 'accounting', '/accounting', '◫']] },
    { labelKey: 'navReports', items: [['reports.view', 'reports', '/reports/journal', '◒']] },
];

export default function CompanyLayout({ title, children }) {
    const page = usePage();
    const { auth, locale, subscriptionWarning, navigationCapabilities = {}, isCompanyOwner = false } = page.props;
    const { url } = page;
    const isArabic = locale === 'ar';
    const user = auth?.user;
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const initials = (user?.name || 'EZ').split(' ').slice(0, 2).map((part) => part[0]).join('').toUpperCase();

    useEffect(() => setSidebarOpen(false), [url]);

    return <div className="app-shell laravel-shell">
        <Head title={title} />
        <SidebarNav
            id="company-sidebar"
            open={sidebarOpen}
            onClose={() => setSidebarOpen(false)}
            groups={groups}
            locale={locale}
            capabilities={{ ...navigationCapabilities, 'company.owner': isCompanyOwner }}
            user={user}
            workspaceLabel={user?.company?.name || translate(locale, 'companyNameDefault')}
            workspaceHint={translate(locale, 'companyWorkspace')}
        />
        <main className="main-content" id="main-content">
            <header className="topbar">
                <button type="button" className="menu-toggle" onClick={() => setSidebarOpen(true)} aria-expanded={sidebarOpen} aria-controls="company-sidebar" aria-label={translate(locale, 'openMenu')}>☰</button>
                <div className="crumbs"><span>{translate(locale, 'home')}</span><span aria-hidden="true">‹</span><strong>{title}</strong></div>
                <div className="top-actions">
                    <Link className="icon-button language-switch" href={`/locale/${isArabic ? 'en' : 'ar'}`} method="post" as="button" aria-label={translate(locale, 'switchLanguage')}><span>{isArabic ? 'EN' : 'ع'}</span></Link>
                    <div className="top-user"><div className="avatar avatar-blue">{initials}</div><div><strong>{user?.name}</strong><span>{translate(locale, 'companyAccount')}</span></div></div>
                    <Link className="icon-button signout-button" href="/logout" method="post" as="button" title={translate(locale, 'signOut')} aria-label={translate(locale, 'signOut')}>↪</Link>
                </div>
            </header>
            <div className="page-body">{subscriptionWarning && <div className="notice-card" role="status">{translate(locale, 'renewalWarning')}</div>}{children}</div>
        </main>
    </div>;
}
