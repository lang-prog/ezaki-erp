import { useMemo, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';
import { useCoreApi } from '../../useCoreApi';

function AccountTree({ accounts, showSystem, canEdit, onEdit, t }) {
    const visible = accounts.filter((account) => showSystem || !account.is_system);
    const roots = visible.filter((account) => !account.parent_id || !visible.some((parent) => Number(parent.id) === Number(account.parent_id)));
    const children = (parentId) => visible.filter((account) => Number(account.parent_id) === Number(parentId));

    if (!visible.length) {
        return <div className="erp-empty erp-empty--compact"><strong>{t('emptyChartTitle')}</strong><span>{t('emptyChartCopy')}</span></div>;
    }

    const render = (account, level = 0) => <div key={account.id} className={`account-tree-row ${account.is_system ? 'account-tree-row--system' : ''}`} style={{ '--account-level': level }}>
        <div className="account-tree-main">
            <span className="account-tree-code">{account.code}</span>
            <span><strong>{account.name}</strong>{account.is_system && <small>{t('systemAccount')}</small>}</span>
        </div>
        <span className="account-tree-type">{account.account_type}</span>
        <div className="account-tree-actions">
            <Link href={`/reports/account-statement?account_id=${account.id}`} className="erp-link">{t('accountStatement')}</Link>
            {canEdit && !account.is_system && <button type="button" className="erp-link-button" onClick={() => onEdit(account)}>{t('edit')}</button>}
        </div>
        {children(account.id).map((child) => render(child, level + 1))}
    </div>;

    return <div className="account-tree">{roots.map((account) => render(account))}</div>;
}

function MoneyForm({ kind, parties, accounts, cashboxes, banks, send, processing, t }) {
    const receipt = kind === 'receipt';
    const [form, setForm] = useState({ counterparty_type: receipt ? 'customer' : 'supplier', party_id: '', other_account_id: '', cashbox_id: '', bank_id: '', [receipt ? 'receipt_date' : 'voucher_date']: new Date().toISOString().slice(0, 10), amount: '', notes: '' });
    const keyDate = receipt ? 'receipt_date' : 'voucher_date';
    const set = (key, value) => setForm((current) => ({ ...current, [key]: value }));
    const cashAccountId = cashboxes.find((item) => String(item.id) === String(form.cashbox_id))?.account_id ?? banks.find((item) => String(item.id) === String(form.bank_id))?.account_id;
    const postingAccounts = accounts.filter((account) => account.is_active !== false && !accounts.some((child) => Number(child.parent_id) === Number(account.id)) && Number(account.id) !== Number(cashAccountId));
    const onSubmit = (event) => { event.preventDefault(); send(receipt ? 'receipts' : 'payment-vouchers', 'POST', form); };

    return <form className={`posting-form posting-form--${receipt ? 'receipt' : 'voucher'}`} onSubmit={onSubmit}>
        <div className="posting-form__intro"><span className="posting-form__number">{receipt ? '01' : '02'}</span><div><h3>{receipt ? t('receipts') : t('vouchers')}</h3><p>{receipt ? t('receiptFormHint') : t('voucherFormHint')}</p></div></div>
        <div className="posting-form__grid">
            <label className="field"><span>{t('counterpartyType')}</span><select value={form.counterparty_type} onChange={(event) => setForm({ ...form, counterparty_type: event.target.value, party_id: '', other_account_id: '' })}><option value={receipt ? 'customer' : 'supplier'}>{receipt ? t('isCustomer') : t('isSupplier')}</option><option value="other">{t('otherAccount')}</option></select></label>
            {form.counterparty_type === (receipt ? 'customer' : 'supplier') ? <label className="field"><span>{receipt ? t('customer') : t('supplier')}</span><select required value={form.party_id} onChange={(event) => set('party_id', event.target.value)}><option value="">—</option>{parties.filter((party) => receipt ? party.is_customer : party.is_supplier).map((party) => <option key={party.id} value={party.id}>{party.name}</option>)}</select></label> : <label className="field"><span>{t('otherAccount')}</span><select required value={form.other_account_id} onChange={(event) => set('other_account_id', event.target.value)}><option value="">—</option>{postingAccounts.map((account) => <option key={account.id} value={account.id}>{account.code} · {account.name}</option>)}</select></label>}
            <label className="field"><span>{t('entryDate')}</span><input required type="date" value={form[keyDate]} onChange={(event) => set(keyDate, event.target.value)} /></label>
            <label className="field"><span>{t('amount')}</span><input required min="0.01" step="0.01" type="number" value={form.amount} onChange={(event) => set('amount', event.target.value)} /></label>
            <label className="field"><span>{t('cashboxes')}</span><select value={form.cashbox_id} onChange={(event) => setForm({ ...form, cashbox_id: event.target.value, bank_id: '' })}><option value="">—</option>{cashboxes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
            <label className="field"><span>{t('banks')}</span><select value={form.bank_id} onChange={(event) => setForm({ ...form, bank_id: event.target.value, cashbox_id: '' })}><option value="">—</option>{banks.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
            <label className="field field--wide"><span>{t('notes')}</span><input value={form.notes} onChange={(event) => set('notes', event.target.value)} /></label>
        </div>
        <button disabled={processing} className={`erp-button ${receipt ? 'erp-button--primary' : 'erp-button--danger'}`}>{receipt ? t('postReceipt') : t('postVoucher')}</button>
    </form>;
}

export default function Accounting({ accounts = [], cashboxes = [], banks = [], periods = [], parties = [], receipts = [], vouchers = [], capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const { notice, processing, send } = useCoreApi();
    const [showSystem, setShowSystem] = useState(false);
    const [editingAccount, setEditingAccount] = useState(null);
    const [childAccount, setChildAccount] = useState({ parent_id: '', name: '' });
    const [cashboxName, setCashboxName] = useState('');
    const [bank, setBank] = useState({ name: '', account_number: '' });
    const year = new Date().getFullYear();
    const [period, setPeriod] = useState({ name: String(year), starts_on: `${year}-01-01`, ends_on: `${year}-12-31` });
    const canCreate = Boolean(capabilities['accounting.create']);
    const canPost = Boolean(capabilities['accounting.post']);
    const visibleCustomCount = accounts.filter((account) => !account.is_system).length;
    const reportLinks = useMemo(() => [['journal', 'journal'], ['ledger', 'ledger'], ['trial-balance', 'trialBalance'], ['income-statement', 'incomeStatement'], ['balance-sheet', 'balanceSheet'], ['debtors', 'debtors'], ['receivables', 'receivablesReport'], ['payables', 'payablesReport']], []);

    const saveAccount = (event) => { event.preventDefault(); send(`accounts/${editingAccount.id}`, 'PUT', editingAccount).then(() => setEditingAccount(null)); };
    const createAccount = (event) => { event.preventDefault(); send('accounts', 'POST', childAccount).then(() => setChildAccount({ parent_id: '', name: '' })); };

    return <CompanyLayout title={t('accounting')}>
        <div className="erp-page-header"><div><span className="erp-eyebrow">{t('navCompany')} / {t('accounting')}</span><h1>{t('accounting')}</h1><p>{t('accountingPageIntro')}</p></div><div className="erp-toolbar">{canCreate && accounts.length === 0 && <button className="erp-button erp-button--primary" onClick={() => send('accounts/initialize', 'POST')}>{t('initializeChart')}</button>}</div></div>
        {notice.message && <div role={notice.type === 'error' ? 'alert' : 'status'} className={`notice-card ${notice.type}`}>{notice.message}</div>}

        <section className="erp-section accounting-reports"><div className="erp-section__header"><div><h2>{t('reports')}</h2><p>{t('reportsHint')}</p></div></div><div className="report-link-grid">{reportLinks.map(([slug, key]) => <Link key={slug} href={`/reports/${slug}`} className="report-link-card"><span>{t(key)}</span><strong>↗</strong></Link>)}</div></section>

        <section className="erp-section"><div className="erp-section__header"><div><h2>{t('chart')}</h2><p>{visibleCustomCount ? `${visibleCustomCount} ${t('customAccounts')}` : t('chartEmptyHint')}</p></div><div className="erp-toolbar">{accounts.some((account) => account.is_system) && <button className="erp-button erp-button--secondary" type="button" onClick={() => setShowSystem((current) => !current)}>{showSystem ? t('hideSystemAccounts') : t('showSystemAccounts')}</button>}{canCreate && <span className="permission-note">{t('accountingCreateHint')}</span>}</div></div>
            {accounts.length === 0 ? <div className="erp-empty"><div className="erp-empty__icon">＋</div><h3>{t('emptyChartTitle')}</h3><p>{t('emptyChartCopy')}</p>{canCreate && <button className="erp-button erp-button--primary" onClick={() => send('accounts/initialize', 'POST')}>{t('initializeChart')}</button>}</div> : <AccountTree accounts={accounts} showSystem={showSystem} canEdit={canCreate} onEdit={(account) => setEditingAccount({ id: account.id, name: account.name, parent_id: account.parent_id ?? '' })} t={t} />}
            {canCreate && accounts.length > 0 && <form className="account-create-form" onSubmit={createAccount}><div><h3>{t('newAccount')}</h3><p>{t('newAccountHint')}</p></div><select required value={childAccount.parent_id} onChange={(event) => setChildAccount({ ...childAccount, parent_id: event.target.value })}><option value="">{t('parentAccount')}</option>{accounts.map((account) => <option key={account.id} value={account.id}>{account.code} · {account.name}{account.is_system ? ` · ${t('systemAccount')}` : ''}</option>)}</select><input required value={childAccount.name} placeholder={t('accountName')} onChange={(event) => setChildAccount({ ...childAccount, name: event.target.value })} /><button className="erp-button erp-button--primary">{t('create')}</button></form>}
        </section>

        {editingAccount && <div className="modal-backdrop"><form className="modal account-edit-modal" onSubmit={saveAccount}><div className="modal-header"><div><h2>{t('editAccount')}</h2><p>{editingAccount.name}</p></div><button type="button" onClick={() => setEditingAccount(null)} aria-label={t('close')}>×</button></div><div className="modal-body"><label className="field"><span>{t('accountName')}</span><input required value={editingAccount.name} onChange={(event) => setEditingAccount({ ...editingAccount, name: event.target.value })} /></label><label className="field"><span>{t('parentAccount')}</span><select value={editingAccount.parent_id ?? ''} onChange={(event) => setEditingAccount({ ...editingAccount, parent_id: event.target.value || null })}><option value="">{t('rootAccount')}</option>{accounts.filter((account) => account.id !== editingAccount.id).map((account) => <option key={account.id} value={account.id}>{account.code} · {account.name}{account.is_system ? ` · ${t('systemAccount')}` : ''}</option>)}</select></label></div><div className="modal-footer"><button type="button" className="erp-button erp-button--secondary" onClick={() => setEditingAccount(null)}>{t('cancel')}</button><button disabled={processing} className="erp-button erp-button--primary">{t('saveChanges')}</button></div></form></div>}

        <section className="erp-section split-section"><div className="erp-section__header"><div><h2>{t('cashboxes')}</h2><p>{t('cashboxesHint')}</p></div></div><div className="erp-table-wrap"><table className="erp-table"><thead><tr><th>{t('accountName')}</th><th>{t('accountCode')}</th></tr></thead><tbody>{cashboxes.map((item) => <tr key={item.id}><td>{item.name}</td><td>{item.account?.code ?? '—'}</td></tr>)}{cashboxes.length === 0 && <tr><td colSpan="2" className="empty-cell">{t('noRecords')}</td></tr>}</tbody></table></div>{canCreate && <form className="inline-create-form" onSubmit={(event) => { event.preventDefault(); send('cashboxes', 'POST', { name: cashboxName }); }}><input required value={cashboxName} onChange={(event) => setCashboxName(event.target.value)} placeholder={t('cashboxName')} /><button className="erp-button erp-button--primary">{t('createCashbox')}</button></form>}</section>
        <section className="erp-section split-section"><div className="erp-section__header"><div><h2>{t('banks')}</h2><p>{t('banksHint')}</p></div></div><div className="erp-table-wrap"><table className="erp-table"><thead><tr><th>{t('accountName')}</th><th>{t('accountNumber')}</th><th>{t('accountCode')}</th></tr></thead><tbody>{banks.map((item) => <tr key={item.id}><td>{item.name}</td><td>{item.account_number || '—'}</td><td>{item.account?.code ?? '—'}</td></tr>)}{banks.length === 0 && <tr><td colSpan="3" className="empty-cell">{t('noRecords')}</td></tr>}</tbody></table></div>{canCreate && <form className="inline-create-form inline-create-form--wide" onSubmit={(event) => { event.preventDefault(); send('banks', 'POST', bank); }}><input required value={bank.name} onChange={(event) => setBank({ ...bank, name: event.target.value })} placeholder={t('bankName')} /><input value={bank.account_number} onChange={(event) => setBank({ ...bank, account_number: event.target.value })} placeholder={t('accountNumber')} /><button className="erp-button erp-button--primary">{t('createBank')}</button></form>}</section>

        <section className="erp-section"><div className="erp-section__header"><div><h2>{t('fiscalPeriods')}</h2><p>{t('fiscalPeriodsHint')}</p></div></div><div className="erp-table-wrap"><table className="erp-table"><thead><tr><th>{t('periodName')}</th><th>{t('periodStart')}</th><th>{t('periodEnd')}</th><th>{t('status')}</th><th>{t('actions')}</th></tr></thead><tbody>{periods.map((item) => <tr key={item.id}><td>{item.name}</td><td>{item.starts_on}</td><td>{item.ends_on}</td><td><span className={`status-pill ${item.status === 'open' ? 'success' : 'muted-pill'}`}>{item.status}</span></td><td>{item.status === 'open' && capabilities['accounting.close_period'] && <button className="erp-link-button" onClick={() => window.confirm(`${t('closePeriod')} ${item.name}?`) && send(`fiscal-periods/${item.id}/close`, 'POST')}>{t('closePeriod')}</button>}</td></tr>)}</tbody></table></div>{canCreate && <form className="period-create-form" onSubmit={(event) => { event.preventDefault(); send('fiscal-periods', 'POST', period); }}><input required value={period.name} onChange={(event) => setPeriod({ ...period, name: event.target.value })} placeholder={t('periodName')} /><input required type="date" value={period.starts_on} onChange={(event) => setPeriod({ ...period, starts_on: event.target.value })} /><input required type="date" value={period.ends_on} onChange={(event) => setPeriod({ ...period, ends_on: event.target.value })} /><button className="erp-button erp-button--primary">{t('createPeriod')}</button></form>}</section>

        <section className="erp-section split-section"><div><div className="erp-section__header"><div><h2>{t('receipts')}</h2><p>{t('recentReceipts')}</p></div></div><div className="erp-table-wrap"><table className="erp-table"><tbody>{receipts.map((item) => <tr key={item.id}><td>{item.receipt_number}</td><td>{item.receipt_date}</td><td>{item.amount} EGP</td><td>{item.status === 'posted' ? t('posted') : item.status}</td></tr>)}{receipts.length === 0 && <tr><td className="empty-cell">{t('noRecords')}</td></tr>}</tbody></table></div></div><div><div className="erp-section__header"><div><h2>{t('vouchers')}</h2><p>{t('recentVouchers')}</p></div></div><div className="erp-table-wrap"><table className="erp-table"><tbody>{vouchers.map((item) => <tr key={item.id}><td>{item.voucher_number}</td><td>{item.voucher_date}</td><td>{item.amount} EGP</td><td>{item.status === 'posted' ? t('posted') : item.status}</td></tr>)}{vouchers.length === 0 && <tr><td className="empty-cell">{t('noRecords')}</td></tr>}</tbody></table></div></div></section>
        {canPost && <section className="posting-grid"><MoneyForm kind="receipt" parties={parties} accounts={accounts} cashboxes={cashboxes} banks={banks} send={send} processing={processing} t={t} /><MoneyForm kind="voucher" parties={parties} accounts={accounts} cashboxes={cashboxes} banks={banks} send={send} processing={processing} t={t} /></section>}
    </CompanyLayout>;
}
