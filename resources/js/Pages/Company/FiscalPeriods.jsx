import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';
import { useCoreApi } from '../../useCoreApi';
import { formatDate } from '../../format';

export default function FiscalPeriods({ periods = [], capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const { notice, processing, send } = useCoreApi();
    const year = new Date().getFullYear();
    const [period, setPeriod] = useState({ name: String(year), starts_on: `${year}-01-01`, ends_on: `${year}-12-31` });
    const canCreate = Boolean(capabilities['accounting.create']);
    const canClose = Boolean(capabilities['accounting.close_period']);
    const setStart = (value) => setPeriod((current) => {
        const nextYear = value ? value.slice(0, 4) : '';
        const currentYear = current.starts_on ? current.starts_on.slice(0, 4) : '';
        const autoName = !current.name || current.name === currentYear || current.name === String(year);

        return { ...current, starts_on: value, ...(autoName && nextYear ? { name: nextYear } : {}) };
    });

    return <CompanyLayout title={t('fiscalPeriods')}>
        <div className="erp-page-header"><div><span className="erp-eyebrow"><Link href="/accounting">{t('accounting')}</Link> / {t('fiscalPeriods')}</span><h1>{t('fiscalPeriods')}</h1><p>{t('fiscalPeriodsHint')}</p></div></div>
        {notice.message && <div role={notice.type === 'error' ? 'alert' : 'status'} className={`notice-card ${notice.type}`}>{notice.message}</div>}
        <section className="erp-section">
            <div className="erp-table-wrap"><table className="erp-table">
                <thead><tr><th>{t('periodName')}</th><th>{t('periodStart')}</th><th>{t('periodEnd')}</th><th>{t('status')}</th><th className="no-print">{t('actions')}</th></tr></thead>
                <tbody>
                    {periods.map((item) => <tr key={item.id}>
                        <td>{item.name}</td><td>{formatDate(item.starts_on, locale)}</td><td>{formatDate(item.ends_on, locale)}</td>
                        <td><span className={`status-pill ${item.status === 'open' ? 'success' : 'muted-pill'}`}>{item.status === 'open' ? t('periodOpen') : t('periodClosed')}</span></td>
                        <td className="no-print">{item.status === 'open' && canClose && <button type="button" className="erp-link-button" onClick={() => window.confirm(`${t('closePeriod')} ${item.name}?`) && send(`fiscal-periods/${item.id}/close`, 'POST')}>{t('closePeriod')}</button>}</td>
                    </tr>)}
                    {periods.length === 0 && <tr><td colSpan="5" className="empty-cell">{t('noRecords')}</td></tr>}
                </tbody>
            </table></div>
            {canCreate && <form className="period-create-form" onSubmit={(event) => { event.preventDefault(); send('fiscal-periods', 'POST', period); }}>
                <input value={period.name} onChange={(event) => setPeriod({ ...period, name: event.target.value })} placeholder={t('periodName')} aria-label={t('periodName')} />
                <input required type="date" value={period.starts_on} onChange={(event) => setStart(event.target.value)} aria-label={t('periodStart')} />
                <input required type="date" value={period.ends_on} onChange={(event) => setPeriod({ ...period, ends_on: event.target.value })} aria-label={t('periodEnd')} />
                <button disabled={processing} className="erp-button erp-button--primary">{t('createPeriod')}</button>
            </form>}
        </section>
    </CompanyLayout>;
}
