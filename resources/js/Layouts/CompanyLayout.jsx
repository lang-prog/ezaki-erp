import { Head, Link, usePage } from '@inertiajs/react';
import { translate } from '../i18n';

const groups = [
    { label: 'نظرة عامة', items: [['dashboard.view', 'لوحة التحكم', '/dashboard', '▦']] },
    { label: 'إدارة الشركة', items: [['users.view', 'المستخدمون والصلاحيات', '/settings/access', '♙'], ['subscriptions.view', 'الاشتراك والترخيص', '/subscription', '▣'], ['branches.view', 'الفروع والمخازن', '/branches', '⌖']] },
    { label: 'العمليات', items: [['sales.view', 'المبيعات', '/operations#sales', '↗'], ['purchases.view', 'المشتريات', '/operations#purchases', '↙'], ['inventory.view', 'المخزون', '/inventory', '▤'], ['parties.view', 'العملاء والموردون', '/parties', '◎'], ['fleet.view', 'الأسطول', '/fleet', '▱'], ['accounting.view', 'المحاسبة', '/accounting', '◫']] },
    { label: 'التقارير والإعدادات', items: [['reports.view', 'التقارير', '/reports/journal', '◒']] },
];

export default function CompanyLayout({ title, children }) {
    const { auth, locale, subscriptionWarning, capabilities = {} } = usePage().props;
    const isArabic = locale === 'ar';
    const user = auth?.user;
    const initials = (user?.name || 'EZ').split(' ').slice(0, 2).map((part) => part[0]).join('');
    const activePath = typeof window !== 'undefined' ? window.location.pathname : '';
    return <div className="app-shell laravel-shell">
        <Head title={title} />
        <aside className="sidebar">
            <div className="brand"><div className="brand-mark">◆</div><div><strong>E‑Zaki</strong><span>ERP / BUSINESS OS</span></div></div>
            <div className="workspace"><div className="workspace-logo">EZ</div><div><strong>{user?.company?.name || 'شركة E‑Zaki'}</strong><small>{translate(locale, 'companyWorkspace')}</small></div><span>‹</span></div>
            <nav>{groups.map((group) => <div className="nav-group" key={group.label}><div className="nav-label">{locale === 'ar' ? group.label : group.label}</div>{group.items.map(([permission, label, href, icon]) => capabilities[permission] && <Link key={href} href={href} className={`nav-item ${activePath === href.split('#')[0] ? 'active' : ''}`}><b>{icon}</b><span>{translate(locale, label) === label ? label : translate(locale, label)}</span></Link>)}</div>)}</nav>
            <div className="sidebar-footer"><div className="help-card"><div className="help-icon">?</div><div><strong>{locale === 'ar' ? 'هل تحتاج مساعدة؟' : 'Need help?'}</strong><span>{locale === 'ar' ? 'تواصل مع الدعم' : 'Contact support'}</span></div><span>‹</span></div><div className="profile-mini"><div className="avatar avatar-blue">{initials}</div><div><strong>{user?.name}</strong><span>{locale === 'ar' ? 'مستخدم الشركة' : 'Company user'}</span></div></div></div>
        </aside>
        <main className="main-content"><header className="topbar"><div className="crumbs"><span>{locale === 'ar' ? 'الرئيسية' : 'Home'}</span><span>‹</span><strong>{title}</strong></div><div className="top-actions"><Link className="icon-button" href={`/locale/${isArabic ? 'en' : 'ar'}`} method="post" as="button"><span>{isArabic ? 'EN' : 'ع'}</span></Link><div className="top-user"><div className="avatar avatar-blue">{initials}</div><div><strong>{user?.name}</strong><span>{locale === 'ar' ? 'حساب الشركة' : 'Company account'}</span></div></div><Link className="icon-button" href="/logout" method="post" as="button" title={translate(locale, 'signOut')}>↪</Link></div></header><div className="page-body">{subscriptionWarning && <div className="notice-card">{translate(locale, 'renewalWarning')}</div>}{children}</div></main>
    </div>;
}
