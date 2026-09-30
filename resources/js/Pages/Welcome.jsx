import { Link, usePage } from '@inertiajs/react';
import { translate } from '../i18n';

export default function Welcome() {
    const { locale } = usePage().props;
    const isArabic = locale === 'ar';
    return <main className="welcome-page" dir={isArabic ? 'rtl' : 'ltr'}>
        <div className="welcome-shell">
            <header className="welcome-topbar">
                <Link href="/" className="welcome-brand"><span className="brand-mark" aria-hidden="true">◆</span><span><strong>E‑Zaki</strong><small>ERP / BUSINESS OS</small></span></Link>
                <Link href={`/locale/${isArabic ? 'en' : 'ar'}`} method="post" as="button" className="welcome-language" aria-label={isArabic ? 'Switch to English' : 'التبديل إلى العربية'}>{isArabic ? 'English' : 'العربية'}</Link>
            </header>
            <section className="welcome-hero">
                <div className="welcome-copy">
                    <p className="welcome-kicker">{isArabic ? 'منصة أعمال عربية دقيقة' : 'A precise Arabic business platform'}</p>
                    <h1>{translate(locale, 'clearControl')}</h1>
                    <p className="welcome-lead">{isArabic ? 'إدارة المبيعات والمشتريات والمخزون والمحاسبة من مساحة عمل واحدة واضحة.' : 'Manage sales, purchasing, inventory, and accounting from one clear workspace.'}</p>
                    <div className="welcome-actions">
                        <Link href="/login" className="primary-button">{translate(locale, 'companySignIn')} <span aria-hidden="true">←</span></Link>
                        <Link href="/super-admin/login" className="secondary-button">{translate(locale, 'platformSignIn')}</Link>
                    </div>
                    <div className="welcome-trust"><span aria-hidden="true">✓</span><span>{isArabic ? 'صلاحيات واضحة وأثر محاسبي قابل للتدقيق' : 'Clear permissions and auditable accounting context'}</span></div>
                </div>
                <div className="welcome-visual" aria-label={isArabic ? 'ملخص لوحة أعمال' : 'Business dashboard preview'}>
                    <div className="visual-header"><span className="visual-dot" /><span className="visual-dot" /><span className="visual-dot" /><strong>E‑Zaki / {isArabic ? 'ملخص الشركة' : 'Company overview'}</strong></div>
                    <div className="visual-metrics"><div><small>{translate(locale, 'monthly_sales')}</small><strong>1,248,600</strong><span>EGP</span></div><div><small>{translate(locale, 'inventory')}</small><strong>86</strong><span>{isArabic ? 'صنف' : 'items'}</span></div></div>
                    <div className="visual-chart"><span /><span /><span /><span /><span /><span /></div>
                    <div className="visual-footer"><i /> {isArabic ? 'كل العمليات موحدة في مساحة شركتك' : 'Every operation in your company workspace'}</div>
                </div>
            </section>
        </div>
    </main>;
}
