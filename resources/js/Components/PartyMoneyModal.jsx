import { useState } from 'react';
import { translate } from '../i18n';
import { useCoreApi } from '../useCoreApi';

export default function PartyMoneyModal({ party, kind, cashboxes = [], banks = [], locale, onClose }) {
    const receipt = kind === 'customer';
    const t = (key) => translate(locale, key);
    const dateKey = receipt ? 'receipt_date' : 'voucher_date';
    const [form, setForm] = useState({ counterparty_type: receipt ? 'customer' : 'supplier', party_id: party.id, [dateKey]: new Date().toISOString().slice(0, 10), amount: '', cashbox_id: '', bank_id: '', notes: '' });
    const { processing, notice, send } = useCoreApi();
    const set = (key, value) => setForm((current) => ({ ...current, [key]: value }));
    const submit = (event) => {
        event.preventDefault();
        send(receipt ? 'receipts' : 'payment-vouchers', 'POST', form);
    };

    return <div className="modal-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}><form className="modal party-money-modal" onSubmit={submit} onMouseDown={(event) => event.stopPropagation()}>
        <div className="modal-header"><div><span className="eyebrow">{receipt ? t('receiptForCustomer') : t('voucherForSupplier')}</span><h2>{party.name}</h2><p>{receipt ? t('receiptModalHint') : t('voucherModalHint')}</p></div><button type="button" onClick={onClose} aria-label={t('close')}>×</button></div>
        {notice.message && <div className={`notice-card ${notice.type}`} role={notice.type === 'error' ? 'alert' : 'status'}>{notice.message}</div>}
        <div className="modal-body form-grid"><label className="field"><span>{t('entryDate')}</span><input required type="date" value={form[dateKey]} onChange={(event) => set(dateKey, event.target.value)} /></label><label className="field"><span>{t('amount')}</span><input required min="0.01" step="0.01" type="number" value={form.amount} onChange={(event) => set('amount', event.target.value)} /></label><label className="field"><span>{t('cashboxes')}</span><select value={form.cashbox_id} onChange={(event) => setForm({ ...form, cashbox_id: event.target.value, bank_id: '' })}><option value="">—</option>{cashboxes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label className="field"><span>{t('banks')}</span><select value={form.bank_id} onChange={(event) => setForm({ ...form, bank_id: event.target.value, cashbox_id: '' })}><option value="">—</option>{banks.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label className="field full-field"><span>{t('notes')}</span><textarea rows="3" value={form.notes} onChange={(event) => set('notes', event.target.value)} /></label></div>
        <div className="modal-footer"><button type="button" className="erp-button erp-button--secondary" onClick={onClose}>{t('cancel')}</button><button disabled={processing} className={`erp-button ${receipt ? 'erp-button--primary' : 'erp-button--danger'}`}>{receipt ? t('postReceipt') : t('postVoucher')}</button></div>
    </form></div>;
}
