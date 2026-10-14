import { useState } from 'react';
import { accountLabel, formatDate, formatMoney } from '../format';

const today = () => new Date().toISOString().slice(0, 10);

/** Posting form shared by the receipts and payment vouchers pages. */
export default function MoneyForm({ kind, form: options, send, processing, t, locale, onPosted }) {
    const receipt = kind === 'receipt';
    const { parties = [], accounts = [], cashboxes = [], banks = [], invoices = [] } = options ?? {};
    const keyDate = receipt ? 'receipt_date' : 'voucher_date';
    const payerKey = receipt ? 'delivered_by' : 'paid_by';
    const partyType = receipt ? 'customer' : 'supplier';
    const [form, setForm] = useState({ counterparty_type: partyType, party_id: '', other_account_id: '', bill_id: '', cashbox_id: '', bank_id: '', [keyDate]: today(), amount: '', notes: '', [payerKey]: '', received_by: '', location: '' });
    const [unallocated, setUnallocated] = useState(0);
    const set = (key, value) => setForm((current) => ({ ...current, [key]: value, ...(key === 'party_id' ? { bill_id: '' } : {}) }));
    const isParty = form.counterparty_type === partyType;
    const availableInvoices = invoices.filter((invoice) => Number(invoice.party_id) === Number(form.party_id));
    const cashAccountId = cashboxes.find((item) => String(item.id) === String(form.cashbox_id))?.account_id ?? banks.find((item) => String(item.id) === String(form.bank_id))?.account_id;
    const postingAccounts = accounts.filter((account) => account.is_active !== false && !accounts.some((child) => Number(child.parent_id) === Number(account.id)) && Number(account.id) !== Number(cashAccountId));

    const submit = async (event) => {
        event.preventDefault();
        const payload = { ...form };
        ['delivered_by', 'paid_by', 'received_by', 'location', 'notes'].forEach((key) => { if (typeof payload[key] === 'string' && payload[key].trim() === '') delete payload[key]; });
        const result = await send(receipt ? 'receipts' : 'payment-vouchers', 'POST', payload);
        setUnallocated(Number(result?.data?.unallocated_amount ?? 0));
        if (result) onPosted?.();
    };

    return <form className={`posting-form posting-form--${receipt ? 'receipt' : 'voucher'}`} onSubmit={submit}>
        <div className="posting-form__intro"><div><h3>{receipt ? t('newReceipt') : t('newVoucher')}</h3><p>{receipt ? t('receiptFormHint') : t('voucherFormHint')}</p></div></div>
        <div className="posting-form__grid">
            <label className="field"><span>{t('counterpartyType')}</span><select value={form.counterparty_type} onChange={(event) => setForm({ ...form, counterparty_type: event.target.value, party_id: '', other_account_id: '', bill_id: '' })}><option value={partyType}>{receipt ? t('isCustomer') : t('isSupplier')}</option><option value="other">{t('otherAccount')}</option></select></label>
            {isParty
                ? <label className="field"><span>{receipt ? t('customer') : t('supplier')}</span><select required value={form.party_id} onChange={(event) => set('party_id', event.target.value)}><option value="">—</option>{parties.map((party) => <option key={party.id} value={party.id}>{party.name}</option>)}</select></label>
                : <label className="field"><span>{t('otherAccount')}</span><select required value={form.other_account_id} onChange={(event) => set('other_account_id', event.target.value)}><option value="">—</option>{postingAccounts.map((account) => <option key={account.id} value={account.id}>{accountLabel(account, locale)}</option>)}</select></label>}
            {isParty && <label className="field"><span>{t('allocateInvoice')}</span><select value={form.bill_id} onChange={(event) => set('bill_id', event.target.value)}><option value="">{t('allocateFifo')}</option>{availableInvoices.map((invoice) => <option key={invoice.id} value={invoice.id}>{invoice.internal_number} · {formatDate(invoice.bill_date, locale)} · {formatMoney(invoice.outstanding, locale)} {t('currency')}</option>)}</select></label>}
            <label className="field"><span>{t('entryDate')}</span><input required type="date" value={form[keyDate]} onChange={(event) => set(keyDate, event.target.value)} /></label>
            <label className="field"><span>{t('amount')}</span><input required min="0.01" step="0.01" type="number" value={form.amount} onChange={(event) => set('amount', event.target.value)} /></label>
            <label className="field"><span>{t('cashboxes')}</span><select value={form.cashbox_id} onChange={(event) => setForm({ ...form, cashbox_id: event.target.value, bank_id: '' })}><option value="">—</option>{cashboxes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
            <label className="field"><span>{t('banks')}</span><select value={form.bank_id} onChange={(event) => setForm({ ...form, bank_id: event.target.value, cashbox_id: '' })}><option value="">—</option>{banks.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
            <label className="field"><span>{receipt ? t('deliveredBy') : t('paidBy')} <small>({t('optional')})</small></span><input maxLength={255} value={form[payerKey]} onChange={(event) => set(payerKey, event.target.value)} /></label>
            <label className="field"><span>{t('receivedBy')} <small>({t('optional')})</small></span><input maxLength={255} value={form.received_by} onChange={(event) => set('received_by', event.target.value)} /></label>
            <label className="field"><span>{t('location')} <small>({t('optional')})</small></span><input maxLength={255} value={form.location} onChange={(event) => set('location', event.target.value)} /></label>
            <label className="field field--wide"><span>{t('notes')}</span><input value={form.notes} onChange={(event) => set('notes', event.target.value)} /></label>
        </div>
        <button disabled={processing} className={`erp-button ${receipt ? 'erp-button--primary' : 'erp-button--danger'}`}>{receipt ? t('postReceipt') : t('postVoucher')}</button>
        {unallocated > 0 && <p role="status" className="text-sm text-amber-800">{t('unallocated')}: {formatMoney(unallocated, locale)} {t('currency')}</p>}
    </form>;
}
