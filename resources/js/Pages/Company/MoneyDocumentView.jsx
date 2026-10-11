import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';

export default function MoneyDocumentView({ kind, document }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const receipt = kind === 'receipt';
    const number = receipt ? document.receipt_number : document.voucher_number;
    const date = receipt ? document.receipt_date : document.voucher_date;
    return <CompanyLayout title={number}>
        <div className="document-view-page"><div className="erp-page-header"><div><p className="eyebrow">{receipt ? t('receipts') : t('vouchers')}</p><h1>{receipt ? t('receiptDetails') : t('voucherDetails')}</h1><p>{number}</p></div><div className="row-actions"><button type="button" className="erp-button erp-button--primary" onClick={() => window.open(`/accounting/${receipt ? 'receipts' : 'payment-vouchers'}/${document.id}/print`, '_blank', 'noopener,noreferrer')}>{t('print')}</button><Link href="/accounting" className="erp-button erp-button--secondary">{t('backToAccounting')}</Link></div></div><section className="document-view-card"><div className="document-view-meta"><div><span>{t('documentNumber')}</span><strong>{number}</strong></div><div><span>{t('entryDate')}</span><strong>{date}</strong></div><div><span>{receipt ? t('customer') : t('supplier')}</span><strong>{document.party?.name ?? '—'}</strong></div><div><span>{t('amount')}</span><strong>{document.amount} {t('currency')}</strong></div><div><span>{t('cashboxes')}</span><strong>{document.cashbox?.name ?? '—'}</strong></div><div><span>{t('banks')}</span><strong>{document.bank?.name ?? '—'}</strong></div><div><span>{t('status')}</span><strong>{document.status === 'posted' ? t('posted') : document.status}</strong></div><div><span>{t('createdBy')}</span><strong>{document.created_by?.name ?? document.createdBy?.name ?? '—'}</strong></div></div>{(document.delivered_by || document.paid_by || document.received_by || document.location || document.notes) && <div className="document-view-notes">{(document.delivered_by || document.paid_by) && <p><strong>{receipt ? t('deliveredBy') : t('paidBy')}:</strong> {document.delivered_by || document.paid_by}</p>}{document.received_by && <p><strong>{t('receivedBy')}:</strong> {document.received_by}</p>}{document.location && <p><strong>{t('location')}:</strong> {document.location}</p>}{document.notes && <p><strong>{t('notes')}:</strong> {document.notes}</p>}</div>}</section></div>
    </CompanyLayout>;
}
