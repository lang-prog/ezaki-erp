import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';

function money(value, locale, fraction = 2) {
    return Number(value ?? 0).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', {
        minimumFractionDigits: fraction,
        maximumFractionDigits: fraction,
    });
}

export default function ReceiptsList({ documents = [], capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const rows = documents?.data ?? documents ?? [];
    const paginationLabel = (label) => label.replace(/<[^>]*>/g, '').replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/&hellip;/g, '…');

    return <CompanyLayout title={t('receipts')}>
        <div className="erp-page-header">
            <div>
                <p className="eyebrow">{t('accounting')} / {t('receipts')}</p>
                <h1>{t('receipts')}</h1>
                <p>{t('recentReceipts')}</p>
            </div>
            <div className="erp-toolbar no-print">
                <Link href="/accounting" className="erp-button erp-button--secondary">{t('accounting')}</Link>
            </div>
        </div>

        <section className="erp-section">
            <div className="erp-table-wrap">
                <table className="erp-table">
                    <thead>
                        <tr>
                            <th>{t('documentNumber')}</th>
                            <th>{t('entryDate')}</th>
                            <th>{t('customer')}</th>
                            <th>{t('amount')}</th>
                            <th>{t('status')}</th>
                            <th className="no-print">{t('actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((document) => <tr key={document.id}>
                            <td><Link href={`/accounting/receipts/${document.id}`} className="erp-link">{document.receipt_number}</Link></td>
                            <td>{document.receipt_date}</td>
                            <td>{document.party?.name ?? document.customer_name ?? '—'}</td>
                            <td>{money(document.amount, locale)} {t('currency')}</td>
                            <td>{document.status === 'posted' ? t('posted') : document.status}</td>
                            <td className="no-print"><div className="row-actions"><Link href={`/accounting/receipts/${document.id}`} className="table-action">{t('view')}</Link>{document.id && <a href={`/accounting/receipts/${document.id}/print`} target="_blank" rel="noreferrer" className="table-action">{t('print')}</a>}</div></td>
                        </tr>)}
                        {rows.length === 0 && <tr><td colSpan="6" className="empty-cell">{t('noRecords')}</td></tr>}
                    </tbody>
                </table>
            </div>
            {documents?.links && <nav className="pagination no-print" aria-label={t('pagination')}>
                {documents.links.map((link, index) => link.url ? <Link key={`${link.label}-${index}`} href={link.url} className={`erp-button ${link.active ? 'erp-button--primary' : 'erp-button--secondary'}`}>{paginationLabel(link.label)}</Link> : <span key={`${link.label}-${index}`} className="erp-button erp-button--secondary is-disabled">{paginationLabel(link.label)}</span>)}
            </nav>}
        </section>
    </CompanyLayout>;
}
