import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { translate } from '../i18n';
import SidebarNav from '../Components/SidebarNav';

const groups = [{ labelKey: 'platformManagement', items: [['platform.view', 'overview', '/super-admin', '▦'], ['platform.view', 'companies', '/super-admin#companies', '▣'], ['platform.view', 'plans', '/super-admin#plans', '◈'], ['platform.view', 'registrationRequests', '/super-admin#registrations', '▤'], ['platform.view', 'coupons', '/super-admin#coupons', '◎']] }];

export default function SuperAdminLayout({ title, children }) {
    const { auth, locale } = usePage().props;
    const { url } = usePage();
    const isArabic = locale === 'ar';
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const user = auth?.user;

    useEffect(() => setSidebarOpen(false), [url]);

    return <div className="app-shell laravel-shell super-laravel">
        <Head title={title} />
        <SidebarNav
            id="platform-sidebar"
            open={sidebarOpen}
            onClose={() => setSidebarOpen(false)}
            groups={groups}
            locale={locale}
            capabilities={{ 'platform.view': true }}
            platform
            user={user}
            workspaceLabel={translate(locale, 'platformAdmin')}
            workspaceHint="Super Admin"
        />
        <main className="main-content" id="main-content">
            <header className="topbar">
                <button type="button" className="menu-toggle" onClick={() => setSidebarOpen(true)} aria-expanded={sidebarOpen} aria-controls="platform-sidebar" aria-label={translate(locale, 'openMenu')}>☰</button>
                <div className="crumbs"><span>{translate(locale, 'platformAdmin')}</span><span aria-hidden="true">‹</span><strong>{title}</strong></div>
                <div className="top-actions">
                    <Link className="icon-button language-switch" href={`/locale/${isArabic ? 'en' : 'ar'}`} method="post" as="button" aria-label={translate(locale, 'switchLanguage')}><span>{isArabic ? 'EN' : 'ع'}</span></Link>
                    <div className="top-user"><div className="avatar avatar-purple">SA</div><div><strong>{user?.name}</strong><span>Super Admin</span></div></div>
                    <Link className="icon-button signout-button" href="/logout" method="post" as="button" title={translate(locale, 'signOut')} aria-label={translate(locale, 'signOut')}>↪</Link>
                </div>
            </header>
            <div className="page-body">{children}</div>
        </main>
    </div>;
}
