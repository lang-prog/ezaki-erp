import { Head, Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { translate } from '../i18n';
import { companyNavigation, platformNavigation } from '../navigation';
import NavigationIcon from '../Components/NavigationIcon';
import SidebarNav from '../Components/SidebarNav';

export default function WorkspaceLayout({ title, children, platform = false }) {
    const { props, url } = usePage();
    const { auth, locale, subscriptionWarning, navigationCapabilities = {} } = props;
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const closeSidebar = useCallback(() => setSidebarOpen(false), []);
    const t = (key) => translate(locale, key);
    const user = auth?.user;
    const initials = (user?.name || (platform ? 'SA' : 'EZ')).split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    const home = platform ? '/super-admin' : '/dashboard';

    useEffect(() => closeSidebar(), [url, closeSidebar]);
    useEffect(() => {
        document.documentElement.lang = locale;
        document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr';
    }, [locale]);

    return <div className={`ez-shell ${platform ? 'ez-shell--platform' : ''}`} dir={locale === 'ar' ? 'rtl' : 'ltr'}>
        <Head title={title} />
        <a href="#main-content" className="ez-skip-link">{t('skipToContent')}</a>
        <SidebarNav
            id={platform ? 'platform-sidebar' : 'company-sidebar'}
            open={sidebarOpen}
            onClose={closeSidebar}
            groups={platform ? platformNavigation : companyNavigation}
            locale={locale}
            capabilities={platform ? {} : navigationCapabilities}
            platform={platform}
            user={user}
            workspaceLabel={platform ? t('platformAdmin') : user?.company?.name || t('companyNameDefault')}
            workspaceHint={platform ? t('platformWorkspace') : t('companyWorkspace')}
        />
        <div className="ez-page">
            <header className="ez-header">
                <button type="button" className="ez-menu-button" onClick={() => setSidebarOpen(true)} aria-expanded={sidebarOpen} aria-controls={platform ? 'platform-sidebar' : 'company-sidebar'} aria-label={t('openMenu')}><NavigationIcon name="menu" size={22} /></button>
                <div className="ez-breadcrumb" aria-label={t('breadcrumb')}><Link href={home}>{platform ? t('platformOverview') : t('home')}</Link><span aria-hidden="true">/</span><strong>{title}</strong></div>
                <div className="ez-header-actions">
                    <Link href={`/locale/${locale === 'ar' ? 'en' : 'ar'}`} method="post" as="button" className="ez-language-button" aria-label={t('switchLanguage')}>{locale === 'ar' ? 'EN' : 'ع'}</Link>
                    <span className="ez-header-user" title={user?.name}><span className="ez-header-avatar">{initials}</span><span>{user?.name}</span></span>
                    <Link href="/logout" method="post" as="button" className="ez-logout-button" title={t('signOut')} aria-label={t('signOut')}><NavigationIcon name="logout" size={19} /></Link>
                </div>
            </header>
            <main id="main-content" className="ez-main" tabIndex={-1}>
                <div className="page-body">
                    {!platform && subscriptionWarning && <div className="notice-card" role="status">{t('renewalWarning')}</div>}
                    {children}
                </div>
            </main>
        </div>
    </div>;
}
