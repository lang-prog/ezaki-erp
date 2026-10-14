import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import CompanyLayout from '../Layouts/CompanyLayout';
import MoneyForm from './MoneyForm';
import { translate } from '../i18n';
import { useCoreApi } from '../useCoreApi';
import { counterpartyLabel, formatDate, formatMoney } from '../format';

const paginationLabel = (label) => label.replace(/<[^>]*>/g, '').replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/&hellip;/g, '…');

export default function MoneyDocumentsList({ kind, documents = [], filters = {}, form = null, capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const receipt = kind === 'receipt';
    const base = receipt ? '/accounting/receipts' : '/accounting/payment-vouchers';
    const { notice, processing, send } = useCoreApi();
    const [values, setValues] = useState({ q: filters.q ?? '', from: filters.from ?? '', to: filters.to ?? '', status: filters.status ?? '' });
    const [showForm, setShowForm] = useState(false);
    const rows = documents?.data ?? documents ?? [];
    const active = Object.fromEntries(Object.entries(values).filter(([, value]) => value !== ''));
    const exportUrl = `${base}/export${Object.keys(active).length ? `?${new URLSearchParams(active).toString()}` : ''}`;
    const apply = (event) => { event.preventDefault(); router.get(base, active, { preserveState: true, preserveScroll: true }); };
    const clear = () => { setValues({ q: '', from: '', to: '', status: '' }); router.get(base, {}, { preserveState: true }); };
    const number = (row) => (receipt ? row.receipt_number : row.voucher_number);
    const date = (row) => (receipt ? row.receipt_date : row.voucher_date);
    const person = (row) => (receipt ? row.delivered_by : row.paid_by);
    const statusLabel = (status) => (status === 'posted' ? t('posted') : status === 'reversed' ? t('reversed') : status);
    const title = receipt ? t('receipts') : t('vouchers');

    return <CompanyLayout title={title}>
        <div className="erp-page-header">
            <div><p className="eyebrow"><Link href="/accounting">{t('accounting')}</Link> / {title}</p><h1>{title}</h1><p>{receipt ? t('recentReceipts') : t('recentVouchers')}</p></div>
            <div className="erp-toolbar no-print">
                {form && <button type="button" className="erp-button erp-button--primary" aria-expanded={showForm} onClick={() => setShowForm((current) => !current)}>{receipt ? t('newReceipt') : t('newVoucher')}</button>}
                <a href={exportUrl} className="erp-button erp-button--secondary">{t('exportCsv')}</a>
            </div>
        </div>
        {notice.message && <div role={notice.type === 'error' ? 'alert' : 'status'} className={`notice-card ${notice.type}`}>{notice.message}</div>}

        {form && showForm && <section className="erp-section"><MoneyForm kind={kind} form={form} send={send} processing={processing} t={t} locale={locale} onPosted={() => setShowForm(false)} /></section>}

        <section className="erp-section">
            <form className="no-print report-filter-bar" onSubmit={apply}>
                <label className="field"><span>{t('searchDocs')}</span><input type="search" value={values.q} onChange={(event) => setValues({ ...values, q: event.target.value })} /></label>
                <label className="field"><span>{t('fromDate')}</span><input type="date" value={values.from} onChange={(event) => setValues({ ...values, from: event.target.value })} /></label>
                <label className="field"><span>{t('toDate')}</span><input type="date" value={values.to} onChange={(event) => setValues({ ...values, to: event.target.value })} /></label>
                <label className="field"><span>{t('status')}</span><select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })}><option value="">{t('allStatuses')}</option><option value="posted">{t('posted')}</option><option value="reversed">{t('reversed')}</option></select></label>
                <div className="erp-toolbar"><button className="erp-button erp-button--primary">{t('apply')}</button><button type="button" className="erp-button erp-button--secondary" onClick={clear}>{t('clearFilters')}</button></div>
            </form>
            <div className="erp-table-wrap">
                <table className="erp-table">
                    <thead><tr><th>{t('documentNumber')}</th><th>{t('entryDate')}</th><th>{t('counterpartyAccount')}</th><th>{receipt ? t('deliveredBy') : t('paidBy')}</th><th>{t('receivedBy')}</th><th>{t('amount')}</th><th>{t('status')}</th><th className="no-print">{t('actions')}</th></tr></thead>
                    <tbody>
                        {rows.map((row) => <tr key={row.id}>
                            <td><Link href={`${base}/${row.id}`} className="erp-link">{number(row)}</Link></td>
                            <td>{formatDate(date(row), locale)}</td>
                            <td>{counterpartyLabel(row, locale)}</td>
                            <td>{person(row) || '—'}</td>
                            <td>{row.received_by || '—'}</td>
                            <td>{formatMoney(row.amount, locale)} {t('currency')}</td>
                            <td><span className={`status-pill ${row.status === 'posted' ? 'success' : 'muted-pill'}`}>{statusLabel(row.status)}</span></td>
                            <td className="no-print"><div className="row-actions"><Link href={`${base}/${row.id}`} className="table-action">{t('view')}</Link><a href={`${base}/${row.id}/print`} target="_blank" rel="noreferrer" className="table-action">{t('print')}</a></div></td>
                        </tr>)}
                        {rows.length === 0 && <tr><td colSpan="8" className="empty-cell">{t('noRecords')}</td></tr>}
                    </tbody>
                </table>
            </div>
            {documents?.links && documents.links.length > 3 && <nav className="pagination no-print" aria-label={t('pagination')}>
                {documents.links.map((link, index) => link.url ? <Link key={`${link.label}-${index}`} href={link.url} className={`erp-button ${link.active ? 'erp-button--primary' : 'erp-button--secondary'}`}>{paginationLabel(link.label)}</Link> : <span key={`${link.label}-${index}`} className="erp-button erp-button--secondary is-disabled">{paginationLabel(link.label)}</span>)}
            </nav>}
        </section>
    </CompanyLayout>;
}
