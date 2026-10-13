import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';
import { useCoreApi } from '../../useCoreApi';

const titles = { journal: 'journal', ledger: 'ledger', 'account-statement': 'accountStatement', 'trial-balance': 'trialBalance', 'income-statement': 'incomeStatement', 'balance-sheet': 'balanceSheet', debtors: 'debtors', receivables: 'receivablesReport', payables: 'payablesReport', creditors: 'creditorsReport' };
const sourceLabels = { sales: 'sales', purchase: 'purchases', receipt: 'receipts', voucher: 'vouchers' };
const money = (value, locale, fraction = 2) => Number(value ?? 0).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-EG', { minimumFractionDigits: fraction, maximumFractionDigits: fraction });
const absoluteMovement = (row) => Math.abs(Number(row.debit ?? 0) - Number(row.credit ?? 0));
const isPayment = (kind) => kind === 'receipt' || kind === 'voucher';

function DocumentModal({ document, locale, t, onClose }) {
    if (!document) return null;
    const rows = document.details ?? [];
    const fullPage = () => document.view_url && window.open(document.view_url, '_blank', 'noopener,noreferrer');
    const print = () => document.print_url && window.open(document.print_url, '_blank', 'noopener,noreferrer');
    return <div className="modal-backdrop statement-modal-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}><section className="modal statement-document-modal" role="dialog" aria-modal="true" aria-labelledby="statement-document-title" onMouseDown={(event) => event.stopPropagation()}>
        <div className="modal-header"><div><span className="eyebrow">{t(sourceLabels[document.kind] ?? 'source')}</span><h2 id="statement-document-title">{document.title}</h2><p>{document.date} · {document.party ?? '—'}</p></div><button type="button" onClick={onClose} aria-label={t('close')}>×</button></div>
        <div className="statement-document-summary"><div><span>{t('amount')}</span><strong>{money(document.amount, locale)} {t('currency')}</strong></div><div><span>{t('status')}</span><strong>{document.status === 'posted' ? t('posted') : document.status}</strong></div><div><span>{t('notes')}</span><strong>{document.notes || '—'}</strong></div></div>
        {rows.length > 0 && <div className="erp-table-wrap modal-table-wrap"><table className="erp-table"><thead><tr><th>{t('productName')}</th><th>{t('actualWeight')}</th><th>{t('packages')}</th><th>{t('unitPrice')}</th><th>{t('total')}</th></tr></thead><tbody>{rows.map((row, index) => <tr key={`${row.product}-${index}`}><td>{row.product || '—'}</td><td>{row.weight ?? '—'}</td><td>{row.packages ?? '—'}</td><td>{money(row.unit_price, locale)}</td><td>{money(row.value, locale)}</td></tr>)}</tbody></table></div>}
        <div className="modal-footer"><button type="button" className="erp-button erp-button--secondary" onClick={onClose}>{t('close')}</button><button type="button" className="erp-button erp-button--secondary" onClick={print} disabled={!document.print_url}>{t('print')}</button><button type="button" className="erp-button erp-button--primary" onClick={fullPage} disabled={!document.view_url}>{t('openFullDocument')}</button></div>
    </section></div>;
}

function StatementTable({ rows, openingBalance, locale, t, onSelectDocument }) {
    const totals = rows.reduce((result, row) => {
        const movement = absoluteMovement(row);
        const payment = isPayment(row.source_document?.kind) ? movement : 0;
        const value = row.source_document && !isPayment(row.source_document.kind) ? movement : 0;
        result.weight += Number(row.source_document?.weight ?? row.weight ?? 0);
        result.value += value;
        result.payments += payment;
        return result;
    }, { weight: 0, value: 0, payments: 0 });
    const lastBalance = rows.at(-1)?.running_balance ?? openingBalance ?? 0;
    return <div className="erp-table-wrap statement-ledger-wrap"><table className="erp-table statement-ledger-table"><thead><tr><th>{t('entryDate')}</th><th>{t('statementDescription')}</th><th>{t('weight')}</th><th>{t('unitPrice')}</th><th>{t('statementValue')}</th><th>{t('payments')}</th><th>{t('balance')}</th><th className="no-print">{t('source')}</th></tr></thead><tbody>
        <tr className="statement-opening-row"><td colSpan="7"><strong>{t('openingBalance')}</strong></td><td className="statement-number">{money(openingBalance, locale)}</td></tr>
        {rows.map((row, index) => {
            const document = row.source_document;
            const movement = absoluteMovement(row);
            const payment = document && isPayment(document.kind) ? movement : 0;
            const value = document && !isPayment(document.kind) ? movement : 0;
            const description = document ? `${t(sourceLabels[document.kind] ?? 'source')} · ${document.title}` : (row.line_description ?? row.description ?? '—');
            const balance = Number(row.running_balance ?? 0);
            return <tr key={row.id ?? `${row.entry_number}-${index}`} className={document ? `statement-row statement-row--${document.kind}` : 'statement-row'}>
                <td className="statement-date">{row.entry_date ?? row.date ?? '—'}</td>
                <td><strong>{description}</strong>{document?.party && <small className="table-subline">{document.party}</small>}</td>
                <td className="statement-number">{document?.weight != null ? money(document.weight, locale, 3) : '—'}</td>
                <td className="statement-number">{document?.unit_price != null ? money(document.unit_price, locale) : '—'}</td>
                <td className="statement-number statement-value">{value ? money(value, locale) : '—'}</td>
                <td className="statement-number statement-payment">{payment ? money(payment, locale) : '—'}</td>
                <td className={`statement-number statement-balance ${balance < 0 ? 'is-credit' : 'is-due'}`}>{money(balance, locale)}</td>
                <td className="no-print"><button type="button" className="table-action" onClick={() => document && onSelectDocument(document)} disabled={!document}>{document ? t('previewDocument') : '—'}</button></td>
            </tr>;
        })}
    </tbody><tfoot><tr><th colSpan="2">{t('statementTotal')}</th><th className="statement-number">{money(totals.weight, locale, 3)}</th><th>—</th><th className="statement-number">{money(totals.value, locale)}</th><th className="statement-number">{money(totals.payments, locale)}</th><th className={`statement-number statement-balance ${Number(lastBalance) < 0 ? 'is-credit' : 'is-due'}`}>{money(lastBalance, locale)}</th><th className="no-print">—</th></tr></tfoot></table>{rows.length === 0 && <div className="erp-empty erp-empty--compact"><strong>{t('noRecords')}</strong><span>{t('statementEmptyHint')}</span></div>}</div>;
}

export default function AccountingReport({ report, rows = [], entries, lines, account, availableAccounts = [], opening_balance: openingBalance, threshold, from = '', to = '', reconciliation_difference: reconciliationDifference, capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const { notice, send } = useCoreApi();
    const [selectedDocument, setSelectedDocument] = useState(null);
    const reportRows = rows?.data ?? entries?.data ?? lines?.data ?? rows ?? [];
    const statement = report === 'account-statement';
    return <CompanyLayout title={t(titles[report] ?? 'reports')}>
        <div className="printable-report accounting-report-page">
            <div className="erp-page-header"><div><Link href="/accounting" className="no-print text-link">{t('accounting')}</Link><p className="eyebrow">{t('financialReports')}</p><h1>{t(titles[report] ?? 'reports')}</h1>{account && <p className="report-account-caption">{account.code} · {account.name}</p>}</div><button type="button" onClick={() => window.print()} className="no-print erp-button erp-button--secondary">{t('print')}</button></div>
            <form method="get" className="no-print report-filter-bar">{statement && <label className="field"><span>{t('selectAccount')}</span><select name="account_id" required defaultValue={account?.id ?? ''}><option value="">{t('selectAccount')}</option>{availableAccounts.map((item) => <option key={item.id} value={item.id}>{item.code} · {item.name}</option>)}</select></label>}<label className="field"><span>{t('fromDate')}</span><input type="date" name="from_date" defaultValue={from} /></label><label className="field"><span>{t('toDate')}</span><input type="date" name="to_date" defaultValue={to} /></label><button className="erp-button erp-button--primary">{t('apply')}</button></form>
            {statement && <div className="statement-kpis"><div><span>{t('openingAmount')}</span><strong>{money(openingBalance, locale)}</strong></div><div><span>{t('movementRows')}</span><strong>{reportRows.length}</strong></div><div><span>{t('balance')}</span><strong>{money(reportRows.at(-1)?.running_balance ?? openingBalance ?? 0, locale)}</strong></div></div>}
            {threshold !== undefined && <p className="report-note">{t('minimum')}: {threshold} {t('currency')}</p>}
            {report === 'balance-sheet' && Number(reconciliationDifference ?? 0) !== 0 && <div className="notice-card error" role="alert">{t('balanceSheetOutOfBalance')}: {money(reconciliationDifference, locale)} {t('currency')}</div>}
            {notice.message && <div role="alert" className={`notice-card ${notice.type}`}>{notice.message}</div>}
            {statement ? <StatementTable rows={reportRows} openingBalance={openingBalance} locale={locale} t={t} onSelectDocument={setSelectedDocument} /> : <div className="erp-table-wrap report-table-wrap"><table className="erp-table"><thead><tr><th>{t('entryDate')}</th><th>{report === 'journal' ? t('journal') : t('accountCode')}</th><th>{t('accountName')}</th><th>{t('debit')}</th><th>{t('credit')}</th><th>{t('balance')}</th>{report === 'journal' && <th className="no-print">{t('reverse')}</th>}</tr></thead><tbody>{reportRows.map((row, index) => <tr key={row.id ?? row.entry_number ?? `${row.code}-${index}`}><td>{row.entry_date ?? row.date ?? '-'}</td><td>{row.account_code ?? row.code ?? row.entry_number ?? '-'}</td><td><strong>{row.name === 'current_period_earnings' ? t('currentPeriodEarnings') : row.account_name ?? row.name ?? row.description ?? row.line_description ?? '-'}</strong></td><td>{row.debit ?? row.debit_total ?? '-'}</td><td>{row.credit ?? row.credit_total ?? '-'}</td><td><strong>{row.running_balance ?? row.balance ?? row.balance_due ?? '-'}</strong></td>{report === 'journal' && <td className="no-print">{capabilities['accounting.reverse'] && <button type="button" onClick={() => send(`journals/${row.id}/reverse`, 'POST', { date: new Date().toISOString().slice(0, 10) })} className="table-action">{t('reverse')}</button>}</td>}</tr>)}</tbody></table>{reportRows.length === 0 && <div className="erp-empty erp-empty--compact"><strong>{t('noRecords')}</strong></div>}</div>}
        </div>
        <DocumentModal document={selectedDocument} locale={locale} t={t} onClose={() => setSelectedDocument(null)} />
    </CompanyLayout>;
}
