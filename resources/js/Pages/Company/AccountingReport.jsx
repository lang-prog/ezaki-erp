import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';
import { useCoreApi } from '../../useCoreApi';

const titles = { journal: 'journal', ledger: 'ledger', 'account-statement': 'accountStatement', 'trial-balance': 'trialBalance', 'income-statement': 'incomeStatement', 'balance-sheet': 'balanceSheet', debtors: 'debtors', receivables: 'receivablesReport', payables: 'payablesReport', creditors: 'creditorsReport' };
const sourceLabels = { sales: 'sales', purchase: 'purchases', receipt: 'receipts', voucher: 'vouchers' };
const money = (value, locale) => Number(value ?? 0).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function DocumentModal({ document, locale, t, onClose }) {
    if (!document) return null;
    const rows = document.details ?? [];
    const fullPage = () => window.open(document.view_url, '_blank', 'noopener,noreferrer');
    const print = () => window.open(document.print_url, '_blank', 'noopener,noreferrer');
    return <div className="modal-backdrop statement-modal-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}><section className="modal statement-document-modal" role="dialog" aria-modal="true" aria-labelledby="statement-document-title" onMouseDown={(event) => event.stopPropagation()}>
        <div className="modal-header"><div><span className="eyebrow">{t(sourceLabels[document.kind] ?? 'source')}</span><h2 id="statement-document-title">{document.title}</h2><p>{document.date} · {document.party ?? '—'}</p></div><button type="button" onClick={onClose} aria-label={t('close')}>×</button></div>
        <div className="statement-document-summary"><div><span>{t('amount')}</span><strong>{money(document.amount, locale)} {t('currency')}</strong></div><div><span>{t('status')}</span><strong>{document.status === 'posted' ? t('posted') : document.status}</strong></div><div><span>{t('notes')}</span><strong>{document.notes || '—'}</strong></div></div>
        {rows.length > 0 && <div className="erp-table-wrap modal-table-wrap"><table className="erp-table"><thead><tr><th>{t('productName')}</th><th>{t('actualWeight')}</th><th>{t('packages')}</th><th>{t('unitPrice')}</th><th>{t('total')}</th></tr></thead><tbody>{rows.map((row, index) => <tr key={`${row.product}-${index}`}><td>{row.product || '—'}</td><td>{row.weight ?? '—'}</td><td>{row.packages ?? '—'}</td><td>{money(row.unit_price, locale)}</td><td>{money(row.value, locale)}</td></tr>)}</tbody></table></div>}
        <div className="modal-footer"><button type="button" className="erp-button erp-button--secondary" onClick={onClose}>{t('close')}</button><button type="button" className="erp-button erp-button--secondary" onClick={print}>{t('print')}</button><button type="button" className="erp-button erp-button--primary" onClick={fullPage}>{t('openFullDocument')}</button></div>
    </section></div>;
}

export default function AccountingReport({ report, rows = [], entries, lines, account, availableAccounts = [], opening_balance: openingBalance, threshold, from = '', to = '', capabilities = {} }) {
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
            {statement && <div className="statement-kpis"><div><span>{t('openingAmount')}</span><strong>{money(openingBalance, locale)}</strong></div><div><span>{t('movementRows')}</span><strong>{reportRows.length}</strong></div><div><span>{t('balance')}</span><strong>{money(reportRows.at(-1)?.running_balance ?? 0, locale)}</strong></div></div>}
            {threshold !== undefined && <p className="report-note">{t('minimum')}: {threshold} {t('currency')}</p>}
            {notice.message && <div role="alert" className={`notice-card ${notice.type}`}>{notice.message}</div>}
            <div className="erp-table-wrap report-table-wrap"><table className="erp-table"><thead><tr><th>{t('entryDate')}</th><th>{report === 'journal' ? t('journal') : t('accountCode')}</th><th>{t('accountName')}</th><th>{t('debit')}</th><th>{t('credit')}</th><th>{t('balance')}</th>{statement && <th className="no-print">{t('source')}</th>}{report === 'journal' && <th className="no-print">{t('reverse')}</th>}</tr></thead><tbody>{reportRows.map((row, index) => <tr key={row.id ?? row.entry_number ?? `${row.code}-${index}`}><td>{row.entry_date ?? row.date ?? '-'}</td><td>{row.account_code ?? row.code ?? row.entry_number ?? '-'}</td><td><strong>{row.account_name ?? row.name ?? row.description ?? row.line_description ?? '-'}</strong>{row.source_document?.party && <small className="table-subline">{row.source_document.party}</small>}</td><td>{row.debit ?? row.debit_total ?? '-'}</td><td>{row.credit ?? row.credit_total ?? '-'}</td><td><strong>{row.running_balance ?? row.balance ?? row.balance_due ?? '-'}</strong></td>{statement && <td className="no-print">{row.source_document ? <button type="button" className="table-action" onClick={() => setSelectedDocument(row.source_document)}>{t('previewDocument')}</button> : row.source_url && <a href={row.source_url} className="table-action">{t('source')}</a>}</td>}{report === 'journal' && <td className="no-print">{capabilities['accounting.reverse'] && <button type="button" onClick={() => send(`journals/${row.id}/reverse`, 'POST', { date: new Date().toISOString().slice(0, 10)})} className="table-action">{t('reverse')}</button>}</td>}</tr>)}</tbody></table>{reportRows.length === 0 && <div className="erp-empty erp-empty--compact"><strong>{t('noRecords')}</strong><span>{statement ? t('statementEmptyHint') : ''}</span></div>}</div>
        </div>
        <DocumentModal document={selectedDocument} locale={locale} t={t} onClose={() => setSelectedDocument(null)} />
    </CompanyLayout>;
}
