import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import ModalShell from '../../Components/ModalShell';
import { translate } from '../../i18n';
import { formatDate, formatMoney } from '../../format';

const BUCKETS = [['current', 'agingCurrent'], ['1_30', 'aging1_30'], ['31_60', 'aging31_60'], ['61_90', 'aging61_90'], ['90_plus', 'aging90Plus']];
const STATES = ['all', 'unpaid', 'partial', 'paid', 'overdue', 'reversed'];
const STATE_CLASS = { unpaid: 'danger', partial: 'warning', paid: 'success', reversed: 'muted-pill' };
const EMPTY = { party_id: '', q: '', from: '', to: '', due_from: '', due_to: '', state: 'all', overdue_only: false, branch_id: '', as_of: '' };

const paginationLabel = (label) => label.replace(/<[^>]*>/g, '').replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/&hellip;/g, '…');

export default function InvoiceSettlementReport({ tab = 'receivables', filters = {}, report, parties = [], branches = [], capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const sales = tab === 'receivables';
    const [values, setValues] = useState({ ...EMPTY, ...filters, overdue_only: Boolean(filters.overdue_only) });
    const [selected, setSelected] = useState(null);
    const rows = report?.rows?.data ?? [];
    const summary = report?.summary ?? {};
    const canView = Boolean(capabilities[sales ? 'sales.view' : 'purchases.view']);
    const canPrint = Boolean(capabilities[sales ? 'sales.print' : 'purchases.print']);

    const submit = (event) => {
        event.preventDefault();
        const query = Object.fromEntries(Object.entries({ ...values, tab }).filter(([key, value]) => value !== '' && value !== false && !(key === 'state' && value === 'all')));
        router.get('/reports/invoice-settlements', query, { preserveState: true, preserveScroll: true });
    };
    const clear = () => { setValues(EMPTY); router.get('/reports/invoice-settlements', { tab }, { preserveState: true }); };
    const stateLabel = (state) => t(`state_${state}`);
    const money = (value) => formatMoney(value, locale);
    const historyType = (item) => (item.type === 'invoice_payment' ? t('paidWithInvoice') : item.type === 'receipt' ? t('receipts') : t('vouchers'));

    return <CompanyLayout title={t('invoiceSettlements')}>
        <div className="erp-page-header">
            <div><p className="eyebrow">{t('reports')} / {t('invoiceSettlements')}</p><h1>{t('invoiceSettlements')}</h1><p>{t('settlementsIntro')} {t('asOfDate')}: {formatDate(report?.as_of, locale)}</p></div>
            <div className="erp-toolbar no-print"><button type="button" className="erp-button erp-button--secondary" onClick={() => window.print()}>{t('print')}</button></div>
        </div>

        <nav className="report-tabs no-print" aria-label={t('invoiceSettlements')}>
            <Link href="/reports/invoice-settlements?tab=receivables" aria-current={sales ? 'page' : undefined} className={`erp-button ${sales ? 'erp-button--primary' : 'erp-button--secondary'}`}>{t('receivablesTab')}</Link>
            <Link href="/reports/invoice-settlements?tab=payables" aria-current={!sales ? 'page' : undefined} className={`erp-button ${!sales ? 'erp-button--primary' : 'erp-button--secondary'}`}>{t('payablesTab')}</Link>
        </nav>

        <section className="erp-section">
            <form className="no-print report-filter-bar settlement-filters" onSubmit={submit}>
                <label className="field"><span>{sales ? t('customer') : t('supplier')}</span><select value={values.party_id} onChange={(event) => setValues({ ...values, party_id: event.target.value })}><option value="">{t('allPartiesFilter')}</option>{parties.map((party) => <option key={party.id} value={party.id}>{party.name}</option>)}</select></label>
                <label className="field"><span>{t('searchInvoice')}</span><input type="search" value={values.q} onChange={(event) => setValues({ ...values, q: event.target.value })} /></label>
                <label className="field"><span>{t('invoiceDateFrom')}</span><input type="date" value={values.from} onChange={(event) => setValues({ ...values, from: event.target.value })} /></label>
                <label className="field"><span>{t('invoiceDateTo')}</span><input type="date" value={values.to} onChange={(event) => setValues({ ...values, to: event.target.value })} /></label>
                <label className="field"><span>{t('dueFrom')}</span><input type="date" value={values.due_from} onChange={(event) => setValues({ ...values, due_from: event.target.value })} /></label>
                <label className="field"><span>{t('dueTo')}</span><input type="date" value={values.due_to} onChange={(event) => setValues({ ...values, due_to: event.target.value })} /></label>
                <label className="field"><span>{t('settlementState')}</span><select value={values.state} onChange={(event) => setValues({ ...values, state: event.target.value })}>{STATES.map((state) => <option key={state} value={state}>{stateLabel(state)}</option>)}</select></label>
                <label className="field"><span>{t('branch')}</span><select value={values.branch_id} onChange={(event) => setValues({ ...values, branch_id: event.target.value })}><option value="">{t('allBranches')}</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
                <label className="field"><span>{t('asOfDate')}</span><input type="date" value={values.as_of} onChange={(event) => setValues({ ...values, as_of: event.target.value })} /></label>
                <label className="toggle-field"><input type="checkbox" checked={values.overdue_only} onChange={(event) => setValues({ ...values, overdue_only: event.target.checked })} /><span>{t('overdueOnly')}</span></label>
                <div className="erp-toolbar"><button className="erp-button erp-button--primary">{t('apply')}</button><button type="button" className="erp-button erp-button--secondary" onClick={clear}>{t('clearFilters')}</button></div>
            </form>
        </section>

        <section className="erp-section" aria-label={t('agingSummary')}>
            <div className="statement-kpis aging-kpis">
                <div className="statement-kpi statement-kpi--total"><span>{t('totalOutstanding')}</span><strong>{money(summary.total_outstanding)} {t('currency')}</strong><small>{summary.open_count ?? 0} / {summary.invoice_count ?? 0} {t('openInvoicesCount')}</small></div>
                {BUCKETS.map(([key, label]) => <div key={key} className="statement-kpi"><span>{t(label)}</span><strong>{money(summary[key])}</strong></div>)}
                <div className="statement-kpi"><span>{t('overdueInvoices')}</span><strong>{formatMoney(summary.overdue_count, locale, 0)}</strong></div>
                <div className="statement-kpi"><span>{t('unallocatedCredit')}</span><strong>{money(summary.unallocated_credit)}</strong><small>{t('unallocatedCreditHint')}</small></div>
            </div>
        </section>

        <section className="erp-section">
            <div className="erp-section__header"><div><h2>{t('partyAging')}</h2></div></div>
            <div className="erp-table-wrap"><table className="erp-table">
                <thead><tr><th>{sales ? t('customer') : t('supplier')}</th>{BUCKETS.map(([key, label]) => <th key={key}>{t(label)}</th>)}<th>{t('totalOutstanding')}</th><th>{t('unallocatedCredit')}</th></tr></thead>
                <tbody>
                    {(report?.parties ?? []).map((party) => <tr key={party.party_id}><td>{party.party_name}</td>{BUCKETS.map(([key]) => <td key={key}>{money(party[key])}</td>)}<td><strong>{money(party.total)}</strong></td><td>{party.unallocated_credit > 0 ? money(party.unallocated_credit) : '—'}</td></tr>)}
                    {(report?.parties ?? []).length === 0 && <tr><td colSpan="8" className="empty-cell">{t('noRecords')}</td></tr>}
                </tbody>
            </table></div>
        </section>

        <section className="erp-section">
            <div className="erp-section__header"><div><h2>{t('openInvoices')}</h2></div></div>
            <div className="erp-table-wrap"><table className="erp-table settlement-table">
                <thead><tr><th>{t('invoiceNumber')}</th><th>{sales ? t('customer') : t('supplier')}</th><th>{t('invoiceDate')}</th><th>{t('dueDate')}</th><th>{t('invoiceValue')}</th><th>{t('settledAmount')}</th><th>{t('outstanding')}</th><th>{t('daysOverdue')}</th><th>{t('settlementState')}</th><th className="no-print">{t('actions')}</th></tr></thead>
                <tbody>
                    {rows.map((row) => <tr key={row.id}>
                        <td><button type="button" className="erp-link-button" onClick={() => setSelected(row)}>{row.number}</button>{row.is_revised && <small className="table-subline">{t('revised')}</small>}</td>
                        <td>{row.party_name}</td>
                        <td>{formatDate(row.date, locale)}</td>
                        <td>{formatDate(row.due_date, locale)}</td>
                        <td>{money(row.total)}</td>
                        <td>{money(row.settled)}</td>
                        <td><strong>{money(row.outstanding)}</strong></td>
                        <td>{row.days_overdue > 0 ? formatMoney(row.days_overdue, locale, 0) : '—'}</td>
                        <td><span className={`status-pill ${STATE_CLASS[row.state] ?? ''}`}>{stateLabel(row.state)}</span>{row.is_overdue && row.state !== 'reversed' && <span className="status-pill danger">{t('state_overdue')}</span>}</td>
                        <td className="no-print"><button type="button" className="table-action" onClick={() => setSelected(row)}>{t('paymentHistory')}</button></td>
                    </tr>)}
                    {rows.length === 0 && <tr><td colSpan="10" className="empty-cell">{t('noRecords')}</td></tr>}
                </tbody>
            </table></div>
            {report?.rows?.links && report.rows.links.length > 3 && <nav className="pagination no-print" aria-label={t('pagination')}>
                {report.rows.links.map((link, index) => link.url ? <Link key={`${link.label}-${index}`} href={link.url} className={`erp-button ${link.active ? 'erp-button--primary' : 'erp-button--secondary'}`}>{paginationLabel(link.label)}</Link> : <span key={`${link.label}-${index}`} className="erp-button erp-button--secondary is-disabled">{paginationLabel(link.label)}</span>)}
            </nav>}
        </section>

        {selected && <ModalShell
            titleId="settlement-modal-title"
            eyebrow={sales ? t('salesInvoices') : t('purchaseInvoices')}
            title={selected.number}
            subtitle={selected.party_name}
            closeLabel={t('close')}
            onClose={() => setSelected(null)}
            footer={<>
                <button type="button" className="erp-button erp-button--secondary" onClick={() => setSelected(null)}>{t('close')}</button>
                {selected.view_url && canView && <button type="button" className="erp-button erp-button--secondary" onClick={() => window.open(selected.view_url, '_blank', 'noopener,noreferrer')}>{t('openFullDocument')}</button>}
                {selected.print_url && canPrint && <button type="button" className="erp-button erp-button--primary" onClick={() => window.open(selected.print_url, '_blank', 'noopener,noreferrer')}>{t('printInvoice')}</button>}
            </>}
        >
            <div className="document-view-meta">
                <div><span>{t('invoiceDate')}</span><strong>{formatDate(selected.date, locale)}</strong></div>
                <div><span>{t('dueDate')}</span><strong>{formatDate(selected.due_date, locale)}</strong></div>
                <div><span>{t('invoiceValue')}</span><strong>{money(selected.total)} {t('currency')}</strong></div>
                <div><span>{t('settledAmount')}</span><strong>{money(selected.settled)} {t('currency')}</strong></div>
                <div><span>{t('outstanding')}</span><strong>{money(selected.outstanding)} {t('currency')}</strong></div>
                <div><span>{t('settlementState')}</span><strong>{stateLabel(selected.state)}</strong></div>
                <div><span>{t('branch')}</span><strong>{selected.branch_name ?? '—'}</strong></div>
                <div><span>{t('warehouse')}</span><strong>{selected.warehouse_name ?? '—'}</strong></div>
            </div>
            <h3>{t('paymentHistory')}</h3>
            <div className="modal-table-wrap"><table className="erp-table">
                <thead><tr><th>{t('documentType')}</th><th>{t('documentNumber')}</th><th>{t('entryDate')}</th><th>{t('amount')}</th><th>{t('status')}</th><th>{t('reversalReason')}</th></tr></thead>
                <tbody>
                    {(selected.history ?? []).map((item, index) => <tr key={`${item.type}-${item.number}-${index}`}>
                        <td>{historyType(item)}{item.source && <small className="table-subline">{item.source}</small>}</td>
                        <td>{item.number ?? '—'}</td>
                        <td>{formatDate(item.date, locale)}</td>
                        <td>{money(item.amount)}</td>
                        <td><span className={`status-pill ${item.status === 'posted' ? 'success' : 'muted-pill'}`}>{item.status === 'posted' ? t('posted') : t('reversed')}</span></td>
                        <td>{item.reversal_reason ? `${item.reversal_reason}${item.reversed_at ? ` (${formatDate(item.reversed_at, locale)})` : ''}` : '—'}</td>
                    </tr>)}
                    {(selected.history ?? []).length === 0 && <tr><td colSpan="6" className="empty-cell">{t('noPayments')}</td></tr>}
                </tbody>
            </table></div>
        </ModalShell>}
    </CompanyLayout>;
}
