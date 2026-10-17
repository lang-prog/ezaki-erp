import { useEffect } from 'react';
import { Link } from '@inertiajs/react';
import SuperAdminLayout from '../../Layouts/SuperAdminLayout';
import { Notice, PageHeader, usePt } from '../../Components/Platform/ui';

// Bookmarks to the old single-page anchors keep working.
const LEGACY = { '#registrations': '/super-admin/registrations', '#registration-settings': '/super-admin/settings', '#new-company': '/super-admin/companies', '#companies': '/super-admin/companies', '#subscriptions': '/super-admin/subscriptions', '#plans': '/super-admin/plans', '#coupons': '/super-admin/plans' };

export default function Dashboard({ overview, alerts = [] }) {
    const { t, money } = usePt();
    useEffect(() => { if (LEGACY[window.location.hash]) window.location.replace(LEGACY[window.location.hash]); }, []);
    const cards = [
        ['pf_kpi_companies', overview.companies.total, '/super-admin/companies'],
        ['pf_kpi_active', overview.companies.active, '/super-admin/companies?status=active'],
        ['pf_kpi_suspended', overview.companies.suspended, '/super-admin/companies?status=suspended'],
        ['pf_kpi_archived', overview.companies.archived, '/super-admin/companies?status=archived'],
        ['pf_kpi_expiring', overview.subscriptions.expiring_7d, '/super-admin/subscriptions?state=expiring'],
        ['pf_kpi_lapsed', overview.subscriptions.lapsed, '/super-admin/subscriptions?state=lapsed'],
        ['pf_kpi_pending_regs', overview.pending_registrations, '/super-admin/registrations'],
        ['pf_kpi_requests', overview.open_requests, '/super-admin/requests'],
    ];
    return <SuperAdminLayout title={t('platformOverview')}>
        <PageHeader title={t('platformOverview')} intro={t('pf_overview_intro')} />
        <section aria-labelledby="alerts-title" className="erp-section">
            <div className="erp-section__header"><div><h2 id="alerts-title">{t('pf_urgent')}</h2><p>{t('pf_urgent_hint')}</p></div></div>
            {alerts.length === 0 ? <Notice type="success">{t('pf_no_alerts')}</Notice> : <ul className="platform-alerts">{alerts.map((alert) => <li key={alert.key}><Link href={alert.href} className="platform-alert"><span aria-hidden="true">▲</span><strong>{alert.count}</strong> {t(`pf_alert_${alert.key}`)}</Link></li>)}</ul>}
        </section>
        <section aria-labelledby="kpi-title" className="erp-section">
            <div className="erp-section__header"><div><h2 id="kpi-title">{t('pf_kpis')}</h2><p>{t('pf_kpis_hint')}</p></div></div>
            <div className="hub-card-grid">{cards.map(([label, value, href]) => <Link key={label} href={href} className="hub-card"><span className="hub-card__copy">{t(label)}</span><strong className="hub-card__title">{value}</strong></Link>)}</div>
        </section>
        <section aria-labelledby="money-title" className="erp-section">
            <div className="erp-section__header"><div><h2 id="money-title">{t('pf_revenue')}</h2><p>{t('pf_revenue_hint')}</p></div></div>
            <div className="statement-kpis aging-kpis">
                <div className="statement-kpi"><span>{t('pf_revenue_30')}</span><strong>{money(overview.revenue.last_30_days)} {t('currency')}</strong></div>
                <div className="statement-kpi"><span>{t('pf_revenue_mtd')}</span><strong>{money(overview.revenue.month_to_date)} {t('currency')}</strong></div>
                <div className="statement-kpi"><span>{t('pf_failed_logins')}</span><strong>{overview.failed_logins_24h}</strong></div>
            </div>
        </section>
    </SuperAdminLayout>;
}
