import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';

function money(value, locale, fraction = 2) {
    return Number(value ?? 0).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: fraction,
        maximumFractionDigits: fraction,
    });
}

export default function InvoiceSettlementReport({ customerRows = [], supplierRows = [], openSales = [], openPurchases = [], from = '', to = '', customerTotal = 0, supplierTotal = 0 }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const customerList = customerRows?.data ?? customerRows ?? [];
    const supplierList = supplierRows?.data ?? supplierRows ?? [];

    return <CompanyLayout title={t('invoiceSettlements')}>
        <div className="erp-page-header">
            <div>
                <p className="eyebrow">{t('financialReports')} / {t('invoiceSettlements')}</p>
                <h1>{t('invoiceSettlements')}</h1>
                <p>{t('receivablesReport')} · {t('payablesReport')}</p>
            </div>
            <div className="erp-toolbar no-print">
                <button type="button" onClick={() => window.print()} className="erp-button erp-button--secondary">{t('print')}</button>
            </div>
        </div>

        <section className="erp-section printable-report">
            <form method="get" className="report-filter-bar no-print">
                <label className="field"><span>{t('fromDate')}</span><input type="date" name="from" defaultValue={from} /></label>
                <label className="field"><span>{t('toDate')}</span><input type="date" name="to" defaultValue={to} /></label>
                <button className="erp-button erp-button--primary">{t('apply')}</button>
            </form>

            <div className="erp-grid-cards">
                <div className="erp-card"><span>{t('receivablesReport')}</span><strong>{money(customerTotal, locale)}</strong><small>{t('currency')}</small></div>
                <div className="erp-card"><span>{t('payablesReport')}</span><strong>{money(supplierTotal, locale)}</strong><small>{t('currency')}</small></div>
                <div className="erp-card"><span>{t('openInvoices')}</span><strong>{openSales.length + openPurchases.length}</strong><small>{t('documents')}</small></div>
            </div>

            <div className="erp-section__header"><div><h2>{t('receivablesReport')}</h2></div></div>
            <div className="erp-table-wrap">
                <table className="erp-table">
                    <thead><tr><th>{t('customer')}</th><th>{t('balance')}</th><th>{t('aging')}</th></tr></thead>
                    <tbody>
                        {customerList.map((row) => <tr key={row.id}><td>{row.name}</td><td>{money(row.balance_due ?? row.balance ?? 0, locale)}</td><td>{row.aging ? JSON.stringify(row.aging) : '—'}</td></tr>)}
                        {customerList.length === 0 && <tr><td colSpan="3" className="empty-cell">{t('noRecords')}</td></tr>}
                    </tbody>
                </table>
            </div>

            <div className="erp-section__header"><div><h2>{t('payablesReport')}</h2></div></div>
            <div className="erp-table-wrap">
                <table className="erp-table">
                    <thead><tr><th>{t('supplier')}</th><th>{t('balance')}</th><th>{t('aging')}</th></tr></thead>
                    <tbody>
                        {supplierList.map((row) => <tr key={row.id}><td>{row.name}</td><td>{money(row.balance_due ?? row.balance ?? 0, locale)}</td><td>{row.aging ? JSON.stringify(row.aging) : '—'}</td></tr>)}
                        {supplierList.length === 0 && <tr><td colSpan="3" className="empty-cell">{t('noRecords')}</td></tr>}
                    </tbody>
                </table>
            </div>

            <div className="erp-section__header"><div><h2>{t('openInvoices')}</h2></div></div>
            <div className="erp-table-wrap">
                <table className="erp-table">
                    <thead><tr><th>{t('documentNumber')}</th><th>{t('entryDate')}</th><th>{t('party')}</th><th>{t('amount')}</th></tr></thead>
                    <tbody>
                        {[...openSales.map((row) => ({ ...row, kind: 'sales' })), ...openPurchases.map((row) => ({ ...row, kind: 'purchase' }))].map((row, index) => <tr key={`${row.kind}-${row.id ?? index}`}><td>{row.internal_number ?? row.id}</td><td>{row.bill_date}</td><td>{row.party_id}</td><td>{money(row.outstanding ?? row.amount ?? 0, locale)}</td></tr>)}
                        {openSales.length === 0 && openPurchases.length === 0 && <tr><td colSpan="4" className="empty-cell">{t('noRecords')}</td></tr>}
                    </tbody>
                </table>
            </div>
        </section>
    </CompanyLayout>;
}
