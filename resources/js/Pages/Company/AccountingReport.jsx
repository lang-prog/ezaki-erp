import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';
import { useCoreApi } from '../../useCoreApi';

const titles = {
    journal: 'journal',
    ledger: 'ledger',
    'account-statement': 'accountStatement',
    'trial-balance': 'trialBalance',
    'income-statement': 'incomeStatement',
    'balance-sheet': 'balanceSheet',
    debtors: 'debtors',
    receivables: 'receivablesReport',
    payables: 'payablesReport',
    creditors: 'creditorsReport',
};

const sourceLabels = {
    sales: 'salesInvoices',
    purchase: 'purchaseInvoices',
    receipt: 'receipts',
    voucher: 'vouchers',
    opening_balance: 'openingBalance',
    reversal: 'reversalOf',
    journal: 'journal',
};

const money = (value, locale, fraction = 2) => Number(value ?? 0).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', {
    minimumFractionDigits: fraction,
    maximumFractionDigits: fraction,
});

function formatBalanceTag(balance, locale, t) {
    const num = Number(balance ?? 0);
    if (Math.abs(num) < 0.005) {
        return t('zeroBalance');
    }
    return num > 0 ? t('debitBalance') : t('creditBalanceTag');
}

function DocumentModal({ document, locale, t, onClose, capabilities = {} }) {
    if (!document) return null;
    const rows = document.details ?? [];
    const allocations = document.allocations ?? [];
    const revisions = document.revisions ?? [];
    const isSales = document.kind === 'sales';
    const isPurchase = document.kind === 'purchase';
    const isReceipt = document.kind === 'receipt';
    const isVoucher = document.kind === 'voucher';

    const canView = isSales ? capabilities['sales.view']
        : isPurchase ? capabilities['purchases.view']
        : (isReceipt || isVoucher) ? capabilities['accounting.view']
        : true;

    const canPrint = isSales ? capabilities['sales.print']
        : isPurchase ? capabilities['purchases.print']
        : (isReceipt || isVoucher) ? capabilities['accounting.view']
        : true;

    const fullPage = () => document.view_url && window.open(document.view_url, '_blank', 'noopener,noreferrer');
    const print = () => document.print_url && window.open(document.print_url, '_blank', 'noopener,noreferrer');

    return <div className="modal-backdrop statement-modal-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
        <section className="modal statement-document-modal" role="dialog" aria-modal="true" aria-labelledby="statement-document-title" onMouseDown={(event) => event.stopPropagation()}>
            <div className="modal-header">
                <div>
                    <span className="eyebrow">{t(sourceLabels[document.kind] ?? 'source')}</span>
                    <h2 id="statement-document-title">{document.title}</h2>
                    <p>{document.date} · {document.party ?? '—'}</p>
                </div>
                <button type="button" onClick={onClose} aria-label={t('close')}>×</button>
            </div>

            <div className="statement-document-summary">
                <div>
                    <span>{t('amount')}</span>
                    <strong>{money(document.amount, locale)} {t('currency')}</strong>
                </div>
                {document.paid_amount != null && <div>
                    <span>{t('settledAmount')}</span>
                    <strong>{money(document.paid_amount, locale)} {t('currency')}</strong>
                </div>}
                {document.remaining_amount != null && <div>
                    <span>{t('remainingAmount')}</span>
                    <strong>{money(document.remaining_amount, locale)} {t('currency')}</strong>
                </div>}
                {document.payment_source && <div>
                    <span>{t('paymentSource')}</span>
                    <strong>{document.payment_source}</strong>
                </div>}
                <div>
                    <span>{t('status')}</span>
                    <strong className={`status-pill ${document.status === 'posted' || document.status === 'approved' ? 'success' : document.status === 'reversed' ? 'danger' : 'muted-pill'}`}>
                        {document.status === 'posted' ? t('posted') : document.status === 'approved' ? t('approved') : document.status === 'reversed' ? t('reversed') : document.status}
                    </strong>
                </div>
                {document.is_revised && <div>
                    <span>{t('revisionChain')}</span>
                    <strong className="text-amber-700">{t('amended')} ({document.revision_count ?? revisions.length})</strong>
                </div>}
                {document.reversal_reason && <div className="span-full text-red-700">
                    <span>{t('reversalReason')}</span>
                    <strong>{document.reversal_reason}</strong>
                </div>}
                {document.notes && <div className="span-full">
                    <span>{t('notes')}</span>
                    <strong>{document.notes}</strong>
                </div>}
            </div>

            {rows.length > 0 && <div className="erp-table-wrap modal-table-wrap">
                <table className="erp-table">
                    <thead>
                        <tr>
                            <th>{t('productName')}</th>
                            <th>{t('actualWeight')}</th>
                            <th>{t('packages')}</th>
                            <th>{t('unitPrice')}</th>
                            <th>{t('total')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, index) => <tr key={`${row.product}-${index}`}>
                            <td>{row.product || '—'}</td>
                            <td>{row.weight ?? '—'}</td>
                            <td>{row.packages ?? '—'}</td>
                            <td>{money(row.unit_price, locale)}</td>
                            <td>{money(row.value, locale)}</td>
                        </tr>)}
                    </tbody>
                </table>
            </div>}

            {allocations.length > 0 && <div className="erp-table-wrap modal-table-wrap">
                <div className="modal-subheading">
                    <h3>{t(isSales || isPurchase ? 'allocatedPayments' : 'allocatedInvoices')}</h3>
                </div>
                <table className="erp-table">
                    <thead>
                        <tr>
                            <th>{t('documentNumber')}</th>
                            <th>{t('entryDate')}</th>
                            <th>{t('amount')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {allocations.map((alloc, index) => <tr key={`${alloc.payment_number || alloc.invoice_number}-${index}`}>
                            <td><strong>{alloc.payment_number || alloc.invoice_number || '—'}</strong></td>
                            <td>{alloc.allocated_at || alloc.bill_date || '—'}</td>
                            <td>{money(alloc.amount, locale)} {t('currency')}</td>
                        </tr>)}
                    </tbody>
                </table>
            </div>}

            {revisions.length > 0 && <div className="modal-revision-history">
                <div className="modal-subheading">
                    <h3>{t('revisionHistory')}</h3>
                    <span className="revision-count-badge">{revisions.length} {t('amended')}</span>
                </div>
                <div className="revision-summary-cards">
                    <div>
                        <span>{t('originalAmount')}</span>
                        <strong>{money(document.original_amount ?? document.amount, locale)} {t('currency')}</strong>
                    </div>
                    <div>
                        <span>{t('revisedAmount')}</span>
                        <strong>{money(document.current_amount ?? document.amount, locale)} {t('currency')}</strong>
                    </div>
                    <div>
                        <span>{t('revisionDelta')}</span>
                        <strong className={Number(document.revision_delta ?? 0) > 0 ? 'text-green-700' : Number(document.revision_delta ?? 0) < 0 ? 'text-red-700' : ''}>
                            {money(document.revision_delta ?? 0, locale)} {t('currency')}
                        </strong>
                    </div>
                </div>
                <div className="erp-table-wrap modal-table-wrap">
                    <table className="erp-table">
                        <thead>
                            <tr>
                                <th>{t('revisionDate')}</th>
                                <th>{t('revisedBy')}</th>
                                <th>{t('reversalReason')}</th>
                                <th>{t('originalAmount')}</th>
                                <th>{t('revisedAmount')}</th>
                                <th>{t('revisionDelta')}</th>
                                <th>{t('reversalJournal')}</th>
                                <th>{t('replacementJournal')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {revisions.map((rev, index) => <tr key={rev.id ?? index}>
                                <td>{rev.created_at || '—'}</td>
                                <td><strong>{rev.user_name || '—'}</strong></td>
                                <td>{rev.reason || '—'}</td>
                                <td>{money(rev.original_amount, locale)}</td>
                                <td>{money(rev.revised_amount, locale)}</td>
                                <td className={rev.delta_amount > 0 ? 'text-green-700 font-bold' : rev.delta_amount < 0 ? 'text-red-700 font-bold' : ''}>
                                    {money(rev.delta_amount, locale)}
                                </td>
                                <td>{rev.reversal_journal_number ? <span className="journal-tag">{rev.reversal_journal_number}</span> : '—'}</td>
                                <td>{rev.replacement_journal_number ? <span className="journal-tag">{rev.replacement_journal_number}</span> : '—'}</td>
                            </tr>)}
                        </tbody>
                    </table>
                </div>
            </div>}

            <div className="modal-footer">
                <button type="button" className="erp-button erp-button--secondary" onClick={onClose}>{t('close')}</button>
                {document.print_url && canPrint && <button type="button" className="erp-button erp-button--secondary" onClick={print}>{t('print')}</button>}
                {document.view_url && canView && <button type="button" className="erp-button erp-button--primary" onClick={fullPage}>{t('openFullDocument')}</button>}
            </div>
        </section>
    </div>;
}

function RevisionModal({ document, locale, t, onClose }) {
    if (!document) return null;
    const revisions = document.revisions ?? [];
    return <div className="modal-backdrop statement-modal-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
        <section className="modal statement-document-modal" role="dialog" aria-modal="true" aria-labelledby="revision-modal-title" onMouseDown={(event) => event.stopPropagation()}>
            <div className="modal-header">
                <div>
                    <span className="eyebrow">{t('revisionHistory')}</span>
                    <h2 id="revision-modal-title">{document.title ?? document.document_number}</h2>
                    <p>{t('amended')} · {revisions.length} {t('revisions')}</p>
                </div>
                <button type="button" onClick={onClose} aria-label={t('close')}>×</button>
            </div>

            <div className="revision-summary-cards">
                <div>
                    <span>{t('originalAmount')}</span>
                    <strong>{money(document.original_amount ?? document.amount, locale)} {t('currency')}</strong>
                </div>
                <div>
                    <span>{t('revisedAmount')}</span>
                    <strong>{money(document.current_amount ?? document.amount, locale)} {t('currency')}</strong>
                </div>
                <div>
                    <span>{t('revisionDelta')}</span>
                    <strong className={Number(document.revision_delta ?? 0) > 0 ? 'text-green-700' : Number(document.revision_delta ?? 0) < 0 ? 'text-red-700' : ''}>
                        {money(document.revision_delta ?? 0, locale)} {t('currency')}
                    </strong>
                </div>
            </div>

            <div className="erp-table-wrap modal-table-wrap">
                <table className="erp-table">
                    <thead>
                        <tr>
                            <th>{t('revisionDate')}</th>
                            <th>{t('revisedBy')}</th>
                            <th>{t('reversalReason')}</th>
                            <th>{t('originalAmount')}</th>
                            <th>{t('revisedAmount')}</th>
                            <th>{t('revisionDelta')}</th>
                            <th>{t('reversalJournal')}</th>
                            <th>{t('replacementJournal')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {revisions.map((rev, index) => <tr key={rev.id ?? index}>
                            <td>{rev.created_at || '—'}</td>
                            <td><strong>{rev.user_name || '—'}</strong></td>
                            <td>{rev.reason || '—'}</td>
                            <td>{money(rev.original_amount, locale)}</td>
                            <td>{money(rev.revised_amount, locale)}</td>
                            <td className={rev.delta_amount > 0 ? 'text-green-700 font-bold' : rev.delta_amount < 0 ? 'text-red-700 font-bold' : ''}>
                                {money(rev.delta_amount, locale)}
                            </td>
                            <td>{rev.reversal_journal_number ? <span className="journal-tag">{rev.reversal_journal_number}</span> : '—'}</td>
                            <td>{rev.replacement_journal_number ? <span className="journal-tag">{rev.replacement_journal_number}</span> : '—'}</td>
                        </tr>)}
                        {revisions.length === 0 && <tr><td colSpan="8" className="empty-cell">{t('noRevisions')}</td></tr>}
                    </tbody>
                </table>
            </div>

            <div className="modal-footer">
                <button type="button" className="erp-button erp-button--secondary" onClick={onClose}>{t('close')}</button>
            </div>
        </section>
    </div>;
}

function StatementView({
    account,
    meta = {},
    summary = {},
    rows = [],
    pagination,
    locale,
    t,
    viewMode = 'operational',
    onSelectDocument,
    onSelectRevision,
}) {
    const openingBalance = Number(summary.opening_balance ?? 0);
    const periodDebit = Number(summary.period_debit ?? 0);
    const periodCredit = Number(summary.period_credit ?? 0);
    const periodNet = Number(summary.period_net ?? (periodDebit - periodCredit));
    const closingBalance = Number(summary.closing_balance ?? (openingBalance + periodNet));
    const transactionCount = Number(summary.transaction_count ?? rows.length);

    const paginationLabel = (label) => label.replace(/<[^>]*>/g, '').replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/&hellip;/g, '…');

    return <div className="statement-workspace">
        <div className="statement-view-toggle no-print">
            <span className="statement-view-toggle__label">{t('viewMode')}:</span>
            <Link
                href={`/reports/account-statement?account_id=${account?.id ?? ''}&from_date=${meta.from || ''}&to_date=${meta.to || ''}&view=operational`}
                className={`erp-button ${viewMode === 'operational' ? 'erp-button--primary' : 'erp-button--secondary'}`}
            >
                {t('operationalStatement')}
            </Link>
            <Link
                href={`/reports/account-statement?account_id=${account?.id ?? ''}&from_date=${meta.from || ''}&to_date=${meta.to || ''}&view=detailed`}
                className={`erp-button ${viewMode === 'detailed' ? 'erp-button--primary' : 'erp-button--secondary'}`}
            >
                {t('detailedLedger')}
            </Link>
        </div>

        <div className="statement-kpis">
            <div className="statement-kpi-card">
                <span className="statement-kpi-label">{t('openingBalance')}</span>
                <strong className={`statement-kpi-value ${openingBalance < 0 ? 'is-credit' : 'is-due'}`}>
                    {money(openingBalance, locale)} {t('currency')}
                </strong>
                <small className="statement-kpi-tag">{formatBalanceTag(openingBalance, locale, t)}</small>
            </div>
            <div className="statement-kpi-card">
                <span className="statement-kpi-label">{t('periodDebits')}</span>
                <strong className="statement-kpi-value text-green-700">
                    {money(periodDebit, locale)} {t('currency')}
                </strong>
            </div>
            <div className="statement-kpi-card">
                <span className="statement-kpi-label">{t('periodCredits')}</span>
                <strong className="statement-kpi-value text-amber-700">
                    {money(periodCredit, locale)} {t('currency')}
                </strong>
            </div>
            <div className="statement-kpi-card">
                <span className="statement-kpi-label">{t('netMovement')}</span>
                <strong className={`statement-kpi-value ${periodNet < 0 ? 'is-credit' : 'is-due'}`}>
                    {money(periodNet, locale)} {t('currency')}
                </strong>
                <small className="statement-kpi-tag">{formatBalanceTag(periodNet, locale, t)}</small>
            </div>
            <div className="statement-kpi-card statement-kpi-card--primary">
                <span className="statement-kpi-label">{t('closingBalance')}</span>
                <strong className={`statement-kpi-value ${closingBalance < 0 ? 'is-credit' : 'is-due'}`}>
                    {money(closingBalance, locale)} {t('currency')}
                </strong>
                <small className="statement-kpi-tag">{formatBalanceTag(closingBalance, locale, t)}</small>
            </div>
        </div>

        <div className="statement-meta-banner">
            <div>
                <h3>{account ? `${account.code} · ${account.name}` : ''}</h3>
                {meta.party_name && <p className="statement-meta-party">{meta.party_name} {meta.party_type ? `(${t(meta.party_type === 'both' ? 'customerAndSupplier' : meta.party_type === 'supplier' ? 'isSupplier' : 'isCustomer')})` : ''}</p>}
            </div>
            <div className="statement-meta-range">
                <span>{meta.from && meta.to ? `${meta.from} — ${meta.to}` : meta.from ? `From ${meta.from}` : meta.to ? `Through ${meta.to}` : t('allActivity')}</span>
                <small>{transactionCount} {t('movementRows')} · {viewMode === 'detailed' ? t('detailedLedger') : t('operationalStatement')}</small>
            </div>
        </div>

        <div className="erp-table-wrap statement-ledger-wrap">
            <table className="erp-table statement-ledger-table">
                <thead>
                    <tr>
                        <th>{t('entryDate')}</th>
                        <th>{t('particulars')}</th>
                        <th>{t('documentNumber')}</th>
                        <th className="statement-number">{t('debit')}</th>
                        <th className="statement-number">{t('credit')}</th>
                        <th className="statement-number">{t('settledAmount')}</th>
                        <th className="statement-number">{t('balance')}</th>
                        <th className="no-print">{t('actions')}</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, index) => {
                        const document = row.source_document;
                        const debit = Number(row.debit ?? 0);
                        const credit = Number(row.credit ?? 0);
                        const balance = Number(row.running_balance ?? 0);
                        const settled = row.settled_amount != null ? Number(row.settled_amount) : null;
                        const particulars = locale === 'ar' && row.particulars_ar ? row.particulars_ar : (row.particulars || row.line_description || row.description || '—');
                        const docBadge = row.document_type || document?.kind;
                        const isRevised = Boolean(row.is_revised || document?.is_revised);

                        return <tr key={row.id ?? `${row.entry_number}-${index}`} className={docBadge ? `statement-row statement-row--${docBadge}` : 'statement-row'}>
                            <td className="statement-date">{row.entry_date ?? row.date ?? '—'}</td>
                            <td>
                                <strong>{particulars}</strong>
                                {document?.party && <small className="table-subline">{document.party}</small>}
                            </td>
                            <td>
                                {docBadge && <span className={`document-badge document-badge--${docBadge}`}>{t(sourceLabels[docBadge] ?? docBadge)}</span>}
                                {isRevised && <span className="document-badge document-badge--revised">{t('amended')}</span>}
                                {row.document_number && <span>{row.document_number}</span>}
                            </td>
                            <td className={`statement-number ${debit > 0 ? 'statement-debit-val' : ''}`}>
                                {debit > 0 ? money(debit, locale) : '—'}
                            </td>
                            <td className={`statement-number ${credit > 0 ? 'statement-credit-val' : ''}`}>
                                {credit > 0 ? money(credit, locale) : '—'}
                            </td>
                            <td className="statement-number statement-settled-val">
                                {settled != null && settled > 0 ? money(settled, locale) : '—'}
                            </td>
                            <td className={`statement-number statement-balance ${balance < 0 ? 'is-credit' : 'is-due'}`}>
                                <span>{money(balance, locale)}</span>
                                <small className="balance-indicator">{balance > 0 ? 'Dr' : balance < 0 ? 'Cr' : ''}</small>
                            </td>
                            <td className="no-print">
                                <div className="row-actions">
                                    <button
                                        type="button"
                                        className="table-action"
                                        onClick={() => document && onSelectDocument(document)}
                                        disabled={!document}
                                    >
                                        {document ? t('previewDocument') : '—'}
                                    </button>
                                    {isRevised && <button
                                        type="button"
                                        className="table-action table-action--revision"
                                        onClick={() => onSelectRevision(document || row)}
                                    >
                                        {t('revisionHistory')}
                                    </button>}
                                    {document?.view_url && <a href={document.view_url} target="_blank" rel="noreferrer" className="table-action">↗</a>}
                                </div>
                            </td>
                        </tr>;
                    })}
                    {rows.length === 0 && <tr>
                        <td colSpan="8" className="empty-cell">
                            <div className="erp-empty erp-empty--compact">
                                <strong>{t('noRecords')}</strong>
                                <span>{t('statementEmptyHint')}</span>
                            </div>
                        </td>
                    </tr>}
                </tbody>
                <tfoot>
                    <tr>
                        <th colSpan="3">{t('periodTotals')}</th>
                        <th className="statement-number">{money(periodDebit, locale)}</th>
                        <th className="statement-number">{money(periodCredit, locale)}</th>
                        <th className="statement-number">—</th>
                        <th className={`statement-number statement-balance ${closingBalance < 0 ? 'is-credit' : 'is-due'}`}>
                            <span>{money(closingBalance, locale)}</span>
                            <small className="balance-indicator">{closingBalance > 0 ? 'Dr' : closingBalance < 0 ? 'Cr' : ''}</small>
                        </th>
                        <th className="no-print">—</th>
                    </tr>
                </tfoot>
            </table>
        </div>

        {pagination?.links && <nav className="pagination no-print" aria-label={t('pagination')}>
            {pagination.links.map((link, index) => link.url ? <Link key={`${link.label}-${index}`} href={link.url} className={`erp-button ${link.active ? 'erp-button--primary' : 'erp-button--secondary'}`}>{paginationLabel(link.label)}</Link> : <span key={`${link.label}-${index}`} className="erp-button erp-button--secondary is-disabled">{paginationLabel(link.label)}</span>)}
        </nav>}
    </div>;
}

export default function AccountingReport({
    report,
    rows = [],
    entries,
    lines,
    account,
    availableAccounts = [],
    opening_balance: openingBalance,
    summary,
    meta,
    threshold,
    from = '',
    to = '',
    view = 'operational',
    reconciliation_difference: reconciliationDifference,
    capabilities = {},
}) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const { notice, send } = useCoreApi();
    const [selectedDocument, setSelectedDocument] = useState(null);
    const [selectedRevision, setSelectedRevision] = useState(null);

    const statement = report === 'account-statement';
    const reportRows = statement
        ? (lines?.data ?? rows?.data ?? lines ?? rows ?? [])
        : (rows?.data ?? entries?.data ?? lines?.data ?? rows ?? []);

    const statementSummary = summary ?? {
        opening_balance: openingBalance ?? 0,
        period_debit: 0,
        period_credit: 0,
        period_net: 0,
        closing_balance: reportRows.at(-1)?.running_balance ?? openingBalance ?? 0,
        transaction_count: reportRows.length,
        view_mode: view,
    };

    const statementMeta = meta ?? {
        from,
        to,
        account_id: account?.id,
        account_code: account?.code,
        account_name: account?.name,
        view_mode: view,
    };

    const currentViewMode = view || statementMeta.view_mode || 'operational';

    return <CompanyLayout title={t(titles[report] ?? 'reports')}>
        <div className="printable-report accounting-report-page">
            <div className="erp-page-header">
                <div>
                    <Link href="/accounting" className="no-print text-link">{t('accounting')}</Link>
                    <p className="eyebrow">{t('financialReports')}</p>
                    <h1>{t(titles[report] ?? 'reports')}</h1>
                    {account && <p className="report-account-caption">{account.code} · {account.name}</p>}
                </div>
                <div className="erp-toolbar no-print">
                    <button type="button" onClick={() => window.print()} className="erp-button erp-button--secondary">{t('print')}</button>
                </div>
            </div>

            <form method="get" className="no-print report-filter-bar">
                {statement && <label className="field">
                    <span>{t('selectAccount')}</span>
                    <select name="account_id" required defaultValue={account?.id ?? ''}>
                        <option value="">{t('selectAccount')}</option>
                        {availableAccounts.map((item) => <option key={item.id} value={item.id}>{item.code} · {item.name}</option>)}
                    </select>
                </label>}
                <label className="field">
                    <span>{t('fromDate')}</span>
                    <input type="date" name="from_date" defaultValue={from} />
                </label>
                <label className="field">
                    <span>{t('toDate')}</span>
                    <input type="date" name="to_date" defaultValue={to} />
                </label>
                {statement && <input type="hidden" name="view" value={currentViewMode} />}
                <button className="erp-button erp-button--primary">{t('apply')}</button>
            </form>

            {threshold !== undefined && <p className="report-note">{t('minimum')}: {threshold} {t('currency')}</p>}
            {report === 'balance-sheet' && Number(reconciliationDifference ?? 0) !== 0 && <div className="notice-card error" role="alert">{t('balanceSheetOutOfBalance')}: {money(reconciliationDifference, locale)} {t('currency')}</div>}
            {notice.message && <div role="alert" className={`notice-card ${notice.type}`}>{notice.message}</div>}

            {statement ? <StatementView
                account={account}
                meta={statementMeta}
                summary={statementSummary}
                rows={reportRows}
                pagination={lines ?? rows}
                locale={locale}
                t={t}
                viewMode={currentViewMode}
                onSelectDocument={setSelectedDocument}
                onSelectRevision={setSelectedRevision}
            /> : <div className="erp-table-wrap report-table-wrap">
                <table className="erp-table">
                    <thead>
                        <tr>
                            <th>{t('entryDate')}</th>
                            <th>{report === 'journal' ? t('journal') : t('accountCode')}</th>
                            <th>{t('accountName')}</th>
                            <th>{t('debit')}</th>
                            <th>{t('credit')}</th>
                            <th>{t('balance')}</th>
                            {report === 'journal' && <th className="no-print">{t('reverse')}</th>}
                        </tr>
                    </thead>
                    <tbody>
                        {reportRows.map((row, index) => <tr key={row.id ?? row.entry_number ?? `${row.code}-${index}`}>
                            <td>{row.entry_date ?? row.date ?? '-'}</td>
                            <td>{row.account_code ?? row.code ?? row.entry_number ?? '-'}</td>
                            <td><strong>{row.name === 'current_period_earnings' ? t('currentPeriodEarnings') : row.account_name ?? row.name ?? row.description ?? row.line_description ?? '-'}</strong></td>
                            <td>{row.debit ?? row.debit_total ?? '-'}</td>
                            <td>{row.credit ?? row.credit_total ?? '-'}</td>
                            <td><strong>{row.running_balance ?? row.balance ?? row.balance_due ?? '-'}</strong></td>
                            {report === 'journal' && <td className="no-print">
                                {capabilities['accounting.reverse'] && <button type="button" onClick={() => send(`journals/${row.id}/reverse`, 'POST', { date: new Date().toISOString().slice(0, 10) })} className="table-action">{t('reverse')}</button>}
                            </td>}
                        </tr>)}
                    </tbody>
                </table>
                {reportRows.length === 0 && <div className="erp-empty erp-empty--compact"><strong>{t('noRecords')}</strong></div>}
            </div>}
        </div>

        <DocumentModal
            document={selectedDocument}
            locale={locale}
            t={t}
            onClose={() => setSelectedDocument(null)}
            capabilities={capabilities}
        />

        <RevisionModal
            document={selectedRevision}
            locale={locale}
            t={t}
            onClose={() => setSelectedRevision(null)}
        />
    </CompanyLayout>;
}
