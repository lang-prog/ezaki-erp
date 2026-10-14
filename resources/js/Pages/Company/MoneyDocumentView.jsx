import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';
import { useCoreApi } from '../../useCoreApi';
import { counterpartyLabel, formatDate, formatMoney } from '../../format';

export default function MoneyDocumentView({ kind, document, allocations = [], capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const { notice, processing, send } = useCoreApi();
    const receipt = kind === 'receipt';
    const payerKey = receipt ? 'delivered_by' : 'paid_by';
    const base = receipt ? 'receipts' : 'payment-vouchers';
    const number = receipt ? document.receipt_number : document.voucher_number;
    const date = receipt ? document.receipt_date : document.voucher_date;
    const canReverse = capabilities['accounting.reverse'] && document.status === 'posted';
    const canEdit = capabilities['accounting.post'] && document.status === 'posted';
    const [reversing, setReversing] = useState(false);
    const [editing, setEditing] = useState(false);
    const [reverseDate, setReverseDate] = useState(new Date().toISOString().slice(0, 10));
    const [reverseReason, setReverseReason] = useState('');
    const [details, setDetails] = useState({ [payerKey]: document[payerKey] ?? '', received_by: document.received_by ?? '', location: document.location ?? '', notes: document.notes ?? '' });
    const statusLabel = (status) => (status === 'posted' ? t('posted') : status === 'reversed' ? t('reversed') : status);

    const onReverseSubmit = async (event) => {
        event.preventDefault();
        if (await send(`${base}/${document.id}/reverse`, 'POST', { date: reverseDate, reason: reverseReason })) setReversing(false);
    };
    const onDetailsSubmit = async (event) => {
        event.preventDefault();
        if (await send(`${base}/${document.id}/details`, 'PUT', details)) setEditing(false);
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
                    {canEdit && <button type="button" className="erp-button erp-button--secondary" onClick={() => setEditing(true)}>{t('editDetails')}</button>}
                    {canReverse && <button type="button" className="erp-button erp-button--danger" onClick={() => setReversing(true)}>{t('reverse')}</button>}
                    <button type="button" className="erp-button erp-button--primary" onClick={() => window.open(`/accounting/${base}/${document.id}/print`, '_blank', 'noopener,noreferrer')}>{t('print')}</button>
                    <Link href={`/accounting/${base}`} className="erp-button erp-button--secondary">{receipt ? t('receipts') : t('vouchers')}</Link>
                    <Link href="/accounting" className="erp-button erp-button--secondary">{t('backToAccounting')}</Link>
                </div>
            </div>

            {notice.message && <div role={notice.type === 'error' ? 'alert' : 'status'} className={`notice-card ${notice.type}`}>{notice.message}</div>}

            {reversing && <div className="modal-backdrop">
                <form className="modal" onSubmit={onReverseSubmit}>
                    <div className="modal-header"><h2>{t('reverse')} - {number}</h2><button type="button" onClick={() => setReversing(false)} aria-label={t('close')}>×</button></div>
                    <div className="modal-body">
                        <label className="field"><span>{t('entryDate')}</span><input required type="date" value={reverseDate} onChange={(event) => setReverseDate(event.target.value)} /></label>
                        <label className="field"><span>{t('reversalReason')}</span><input required value={reverseReason} onChange={(event) => setReverseReason(event.target.value)} /></label>
                    </div>
                    <div className="modal-footer"><button type="button" className="erp-button erp-button--secondary" onClick={() => setReversing(false)}>{t('cancel')}</button><button disabled={processing} className="erp-button erp-button--danger">{t('reverse')}</button></div>
                </form>
            </div>}

            {editing && <div className="modal-backdrop">
                <form className="modal" onSubmit={onDetailsSubmit}>
                    <div className="modal-header"><h2>{t('editDetails')} - {number}</h2><button type="button" onClick={() => setEditing(false)} aria-label={t('close')}>×</button></div>
                    <div className="modal-body">
                        <p className="permission-note">{t('detailsOnlyHint')}</p>
                        <label className="field"><span>{receipt ? t('deliveredBy') : t('paidBy')}</span><input maxLength={255} value={details[payerKey]} onChange={(event) => setDetails({ ...details, [payerKey]: event.target.value })} /></label>
                        <label className="field"><span>{t('receivedBy')}</span><input maxLength={255} value={details.received_by} onChange={(event) => setDetails({ ...details, received_by: event.target.value })} /></label>
                        <label className="field"><span>{t('location')}</span><input maxLength={255} value={details.location} onChange={(event) => setDetails({ ...details, location: event.target.value })} /></label>
                        <label className="field"><span>{t('notes')}</span><input maxLength={2000} value={details.notes} onChange={(event) => setDetails({ ...details, notes: event.target.value })} /></label>
                    </div>
                    <div className="modal-footer"><button type="button" className="erp-button erp-button--secondary" onClick={() => setEditing(false)}>{t('cancel')}</button><button disabled={processing} className="erp-button erp-button--primary">{t('saveChanges')}</button></div>
                </form>
            </div>}

            <section className="document-view-card">
                <div className="document-view-meta">
                    <div><span>{t('documentNumber')}</span><strong>{number}</strong></div>
                    <div><span>{t('entryDate')}</span><strong>{formatDate(date, locale)}</strong></div>
                    <div><span>{t('counterpartyAccount')}</span><strong>{counterpartyLabel(document, locale)}</strong></div>
                    <div><span>{t('amount')}</span><strong>{formatMoney(document.amount, locale)} {t('currency')}</strong></div>
                    <div><span>{t('cashboxes')}</span><strong>{document.cashbox?.name ?? '—'}</strong></div>
                    <div><span>{t('banks')}</span><strong>{document.bank?.name ?? '—'}</strong></div>
                    <div><span>{t('status')}</span><strong className={`status-pill ${document.status === 'posted' ? 'success' : 'muted-pill'}`}>{statusLabel(document.status)}</strong></div>
                    <div><span>{t('createdBy')}</span><strong>{document.created_by?.name ?? document.createdBy?.name ?? '—'}</strong></div>
                    <div><span>{receipt ? t('deliveredBy') : t('paidBy')}</span><strong>{document[payerKey] || '—'}</strong></div>
                    <div><span>{t('receivedBy')}</span><strong>{document.received_by || '—'}</strong></div>
                    <div><span>{t('location')}</span><strong>{document.location || '—'}</strong></div>
                </div>
                {(document.notes || document.reversal_reason) && <div className="document-view-notes">
                    {document.notes && <p><strong>{t('notes')}:</strong> {document.notes}</p>}
                    {document.reversal_reason && <p className="text-red-700"><strong>{t('reversalReason')}:</strong> {document.reversal_reason}</p>}
                </div>}
            </section>

            {allocations.length > 0 && <section className="document-view-card">
                <h2>{t('allocatedInvoices')}</h2>
                <div className="erp-table-wrap"><table className="erp-table">
                    <thead><tr><th>{t('invoiceNumber')}</th><th>{t('amount')}</th><th>{t('status')}</th><th>{t('reversalReason')}</th><th className="no-print">{t('actions')}</th></tr></thead>
                    <tbody>{allocations.map((row, index) => <tr key={`${row.invoice_number}-${index}`}>
                        <td>{row.invoice_number}</td><td>{formatMoney(row.amount, locale)} {t('currency')}</td>
                        <td><span className={`status-pill ${row.status === 'posted' ? 'success' : 'muted-pill'}`}>{statusLabel(row.status)}</span></td>
                        <td>{row.reversal_reason || '—'}</td>
                        <td className="no-print">{row.view_url && capabilities[receipt ? 'sales.view' : 'purchases.view'] && <Link href={row.view_url} className="table-action">{t('view')}</Link>}</td>
                    </tr>)}</tbody>
                </table></div>
            </section>}
        </div>
    </CompanyLayout>;
}
