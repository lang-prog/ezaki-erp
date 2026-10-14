import { useState } from 'react';
import { translate } from '../i18n';
import { useCoreApi } from '../useCoreApi';

export default function PartyMoneyModal({ party, kind, cashboxes = [], banks = [], locale, onClose }) {
    const receipt = kind === 'customer';
    const t = (key) => translate(locale, key);
    const dateKey = receipt ? 'receipt_date' : 'voucher_date';
    const payerKey = receipt ? 'delivered_by' : 'paid_by';
    const [form, setForm] = useState({ counterparty_type: receipt ? 'customer' : 'supplier', party_id: party.id, [dateKey]: new Date().toISOString().slice(0, 10), amount: '', cashbox_id: '', bank_id: '', notes: '', [payerKey]: '', received_by: '' });
    const { processing, notice, send } = useCoreApi();
    const set = (key, value) => setForm((current) => ({ ...current, [key]: value }));
    const submit = (event) => {
        event.preventDefault();
        const payload = { ...form };
        [payerKey, 'received_by', 'notes'].forEach((key) => { if (String(payload[key]).trim() === '') delete payload[key]; });
        send(receipt ? 'receipts' : 'payment-vouchers', 'POST', payload);
    };

    return <div className="modal-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}><form className="modal party-money-modal" onSubmit={submit} onMouseDown={(event) => event.stopPropagation()}>
        <div className="modal-header"><div><span className="eyebrow">{receipt ? t('receiptForCustomer') : t('voucherForSupplier')}</span><h2>{party.name}</h2><p>{receipt ? t('receiptModalHint') : t('voucherModalHint')}</p></div><button type="button" onClick={onClose} aria-label={t('close')}>×</button></div>
        {notice.message && <div className={`notice-card ${notice.type}`} role={notice.type === 'error' ? 'alert' : 'status'}>{notice.message}</div>}
        <div className="modal-body form-grid"><label className="field"><span>{t('entryDate')}</span><input required type="date" value={form[dateKey]} onChange={(event) => set(dateKey, event.target.value)} /></label><label className="field"><span>{t('amount')}</span><input required min="0.01" step="0.01" type="number" value={form.amount} onChange={(event) => set('amount', event.target.value)} /></label><label className="field"><span>{t('cashboxes')}</span><select value={form.cashbox_id} onChange={(event) => setForm({ ...form, cashbox_id: event.target.value, bank_id: '' })}><option value="">—</option>{cashboxes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label className="field"><span>{t('banks')}</span><select value={form.bank_id} onChange={(event) => setForm({ ...form, bank_id: event.target.value, cashbox_id: '' })}><option value="">—</option>{banks.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label className="field"><span>{receipt ? t('deliveredBy') : t('paidBy')} <small>({t('optional')})</small></span><input maxLength={255} value={form[payerKey]} onChange={(event) => set(payerKey, event.target.value)} /></label><label className="field"><span>{t('receivedBy')} <small>({t('optional')})</small></span><input maxLength={255} value={form.received_by} onChange={(event) => set('received_by', event.target.value)} /></label><label className="field full-field"><span>{t('notes')}</span><textarea rows="3" value={form.notes} onChange={(event) => set('notes', event.target.value)} /></label></div>
        <div className="modal-footer"><button type="button" className="erp-button erp-button--secondary" onClick={onClose}>{t('cancel')}</button><button disabled={processing} className={`erp-button ${receipt ? 'erp-button--primary' : 'erp-button--danger'}`}>{receipt ? t('postReceipt') : t('postVoucher')}</button></div>
    </form></div>;
}
