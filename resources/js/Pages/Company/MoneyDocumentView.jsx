import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';
import { useCoreApi } from '../../useCoreApi';

export default function MoneyDocumentView({ kind, document, capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const { notice, processing, send } = useCoreApi();
    const [reversing, setReversing] = useState(false);
    const [reverseDate, setReverseDate] = useState(new Date().toISOString().slice(0, 10));
    const [reverseReason, setReverseReason] = useState('');
    const receipt = kind === 'receipt';
    const number = receipt ? document.receipt_number : document.voucher_number;
    const date = receipt ? document.receipt_date : document.voucher_date;
    const canReverse = capabilities['accounting.reverse'] && document.status === 'posted';

    const onReverseSubmit = async (e) => {
        e.preventDefault();
        const endpoint = receipt ? `receipts/${document.id}/reverse` : `payment-vouchers/${document.id}/reverse`;
        await send(endpoint, 'POST', { date: reverseDate, reason: reverseReason });
        setReversing(false);
    };

    return <CompanyLayout title={number}>
        <div className="document-view-page">
            <div className="erp-page-header">
                <div>
                    <p className="eyebrow">{receipt ? t('receipts') : t('vouchers')}</p>
                    <h1>{receipt ? t('receiptDetails') : t('voucherDetails')}</h1>
                    <p>{number}</p>
                </div>
                <div className="row-actions">
                    {canReverse && <button type="button" className="erp-button erp-button--danger" onClick={() => setReversing(true)}>{t('reverse')}</button>}
                    <button type="button" className="erp-button erp-button--primary" onClick={() => window.open(`/accounting/${receipt ? 'receipts' : 'payment-vouchers'}/${document.id}/print`, '_blank', 'noopener,noreferrer')}>{t('print')}</button>
                    <Link href={receipt ? '/accounting/receipts' : '/accounting/payment-vouchers'} className="erp-button erp-button--secondary">{receipt ? t('receipts') : t('vouchers')}</Link>
                    <Link href="/accounting" className="erp-button erp-button--secondary">{t('backToAccounting')}</Link>
                </div>
            </div>

            {notice.message && <div role="status" className={`notice-card ${notice.type}`}>{notice.message}</div>}

            {reversing && <div className="modal-backdrop">
                <form className="modal" onSubmit={onReverseSubmit}>
                    <div className="modal-header">
                        <h2>{t('reverse')} - {number}</h2>
                        <button type="button" onClick={() => setReversing(false)} aria-label={t('close')}>×</button>
                    </div>
                    <div className="modal-body">
                        <label className="field">
                            <span>{t('entryDate')}</span>
                            <input required type="date" value={reverseDate} onChange={(e) => setReverseDate(e.target.value)} />
                        </label>
                        <label className="field">
                            <span>{t('notes')}</span>
                            <input required value={reverseReason} onChange={(e) => setReverseReason(e.target.value)} placeholder="Reason for reversal" />
                        </label>
                    </div>
                    <div className="modal-footer">
                        <button type="button" className="erp-button erp-button--secondary" onClick={() => setReversing(false)}>{t('cancel')}</button>
                        <button disabled={processing} className="erp-button erp-button--danger">{t('reverse')}</button>
                    </div>
                </form>
            </div>}

            <section className="document-view-card">
                <div className="document-view-meta">
                    <div><span>{t('documentNumber')}</span><strong>{number}</strong></div>
                    <div><span>{t('entryDate')}</span><strong>{date}</strong></div>
                    <div><span>{receipt ? t('customer') : t('supplier')}</span><strong>{document.party?.name ?? '—'}</strong></div>
                    <div><span>{t('amount')}</span><strong>{document.amount} {t('currency')}</strong></div>
                    <div><span>{t('cashboxes')}</span><strong>{document.cashbox?.name ?? '—'}</strong></div>
                    <div><span>{t('banks')}</span><strong>{document.bank?.name ?? '—'}</strong></div>
                    <div><span>{t('status')}</span><strong className={`status-pill ${document.status === 'posted' ? 'success' : 'muted-pill'}`}>{document.status === 'posted' ? t('posted') : document.status}</strong></div>
                    <div><span>{t('createdBy')}</span><strong>{document.created_by?.name ?? document.createdBy?.name ?? '—'}</strong></div>
                </div>
                {(document.delivered_by || document.paid_by || document.received_by || document.location || document.notes || document.reversal_reason) && <div className="document-view-notes">
                    {(document.delivered_by || document.paid_by) && <p><strong>{receipt ? t('deliveredBy') : t('paidBy')}:</strong> {document.delivered_by || document.paid_by}</p>}
                    {document.received_by && <p><strong>{t('receivedBy')}:</strong> {document.received_by}</p>}
                    {document.location && <p><strong>{t('location')}:</strong> {document.location}</p>}
                    {document.notes && <p><strong>{t('notes')}:</strong> {document.notes}</p>}
                    {document.reversal_reason && <p className="text-red-700"><strong>Reversal reason:</strong> {document.reversal_reason}</p>}
                </div>}
            </section>
        </div>
    </CompanyLayout>;
}
