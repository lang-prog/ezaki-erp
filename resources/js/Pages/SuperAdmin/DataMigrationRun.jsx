import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import SuperAdminLayout from '../../Layouts/SuperAdminLayout';
import { ConfirmActionDialog, Notice, PageHeader, StatusBadge, usePlatformApi, usePt } from '../../Components/Platform/ui';
import { MIGRATION_DECISIONS, MIGRATION_ENTITIES, MIGRATION_RECORD_STATUSES, MIGRATION_RECORD_TYPES, MIGRATION_REPORTS, MIGRATION_UNITS } from '../../i18n-migration';

const CREATABLE = ['customer', 'supplier', 'product_type', 'warehouse', 'cashbox', 'bank', 'product', 'branch'];
const TONE = { failed_terminal: 'failed', failed_recoverable: 'warning', completed: 'approved', signed_off: 'approved', archived: 'archived', completed_with_review: 'warning', executing: 'pending', parsing: 'pending' };
const DECISION_TONE = { matched: 'approved', create_required: 'info', needs_review: 'warning', excluded: 'archived' };
const RECORD_TONE = { ready: 'info', needs_review: 'warning', excluded: 'archived', created: 'approved', failed: 'failed' };
const STAGE_TONE = { done: 'approved', current: 'pending', todo: 'disabled', blocked: 'failed' };
const SIGNS = { customer_start_balance_sign: ['debit_positive', 'debit_negative'], supplier_start_balance_sign: ['credit_positive', 'credit_negative'] };

/** Localized text for any failed request; server refusal codes win over generic status text. */
function explain(error, t) {
    const status = error?.response?.status;
    const data = error?.response?.data;
    if (!status) return t('mg_err_network');
    if (data?.code) {
        const key = `mg_err_${data.code}`;
        const text = t(key);
        if (text !== key) return text;
    }
    if (status === 422 && data?.errors) return Object.values(data.errors).flat().join(' ');

    return t(`mg_err_${[403, 409, 419, 422].includes(status) ? status : 500}`);
}

function codeLabel(t, code) {
    const key = `mg_code_${code}`;
    const text = t(key);
    if (text !== key) return text;
    const unmapped = /^(.*)_unmapped$/.exec(code ?? '');

    return unmapped ? `${t(`mg_entity_${unmapped[1]}`)}: ${t('mg_code_party_unmapped')}` : String(code ?? '');
}

const scalars = (params) => Object.entries(params ?? {}).filter(([, value]) => ['string', 'number'].includes(typeof value)).map(([key, value]) => `${key}: ${value}`).join(' · ');

function Pager({ meta, onPage, t, busy }) {
    if (!meta || meta.last_page <= 1) return null;

    return <nav className="pagination no-print" aria-label={t('pf_pagination')}>
        <button type="button" className="erp-button erp-button--secondary" disabled={busy || meta.page <= 1} onClick={() => onPage(meta.page - 1)}>{t('mg_prev')}</button>
        <span className="erp-button erp-button--secondary is-disabled">{t('mg_page').replace('{page}', String(meta.page)).replace('{last}', String(meta.last_page))}</span>
        <button type="button" className="erp-button erp-button--secondary" disabled={busy || meta.page >= meta.last_page} onClick={() => onPage(meta.page + 1)}>{t('mg_next')}</button>
    </nav>;
}

function BlockerList({ blockers, t, onOpenMapping, onAcknowledge, canManage }) {
    if (!blockers || blockers.length === 0) return <p className="permission-note">{t('mg_no_blockers')}</p>;

    return <ul className="blocker-list" aria-label={t('mg_blockers')}>{blockers.map((blocker, index) => <li key={`${blocker.code}-${blocker.mapping_id ?? index}-${blocker.source_key ?? index}`}>
        <strong>{codeLabel(t, blocker.code)}</strong>
        {blocker.entity && <span> · {t(`mg_entity_${blocker.entity}`)} {blocker.source_key ?? blocker.source_id ?? ''}</span>}
        {blocker.name && <span> · {blocker.name}</span>}
        {blocker.records ? <span> · {blocker.records}</span> : null}
        {scalars(blocker.params) && <small className="table-subline">{scalars(blocker.params)}</small>}
        {blocker.mapping_id && onOpenMapping && <> <button type="button" className="erp-link-button" onClick={() => onOpenMapping(blocker)}>{t('mg_open_mapping')}</button></>}
        {blocker.ack && canManage && onAcknowledge && <> <button type="button" className="erp-link-button" onClick={() => onAcknowledge(blocker)}>{t('mg_ack')}</button></>}
    </li>)}</ul>;
}

function MappingRow({ mapping, options, t, disabled, onSave, onExclude, hidePartyCreate }) {
    const [unit, setUnit] = useState(mapping.unit_basis ?? '');
    const [cost, setCost] = useState(mapping.opening_cost_per_ton ?? '');
    const frozen = mapping.locked || disabled;
    const list = mapping.entity === 'payment_source'
        ? [...(options.cashbox ?? []).map((o) => ({ ...o, kind: 'cashbox', value: `cashbox:${o.id}` })), ...(options.bank ?? []).map((o) => ({ ...o, kind: 'bank', value: `bank:${o.id}` }))]
        : (options[mapping.entity] ?? []).map((o) => ({ ...o, value: String(o.id) }));
    const current = mapping.target_id ? (mapping.entity === 'payment_source' ? `${(mapping.target_type ?? '').toLowerCase() === 'bank' ? 'bank' : 'cashbox'}:${mapping.target_id}` : String(mapping.target_id)) : '';
    const choose = (event) => {
        const value = event.target.value;
        if (!value) return;
        const picked = list.find((o) => o.value === value);
        onSave(mapping, { decision: 'matched', target_id: picked.id, ...(picked.kind ? { target_kind: picked.kind } : {}) });
    };
    const productExtras = mapping.entity === 'product';
    const saveProduct = () => onSave(mapping, {
        decision: mapping.decision === 'needs_review' ? 'create_required' : mapping.decision,
        ...(mapping.decision === 'matched' && mapping.target_id ? { target_id: mapping.target_id } : {}),
        ...(unit ? { unit_basis: unit } : {}),
        ...(cost !== '' && cost !== null ? { opening_cost_per_ton: Number(cost) } : {}),
    });
    const nonWeight = unit && !['ton_weight', 'kg_weight'].includes(unit);

    return <tr>
        <td>{t(`mg_entity_${mapping.entity}`)}</td>
        <td><code>{mapping.source_key}</code></td>
        <td>{mapping.name ?? '—'}{mapping.note && <small className="table-subline">{codeLabel(t, mapping.note)}</small>}</td>
        <td><StatusBadge status={DECISION_TONE[mapping.decision] ?? 'info'} label={t(`mg_decision_${mapping.decision}`)} /><small className="table-subline">{mapping.locked ? t('mg_locked') : (mapping.reviewed ? t('mg_reviewed') : t('mg_not_reviewed'))}{mapping.reason ? ` · ${mapping.reason}` : ''}</small></td>
        <td>
            <select aria-label={t('mg_choose_target')} value={current} disabled={frozen} onChange={choose}><option value="">{t('mg_choose_target')}</option>{list.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>
            {productExtras && <div className="row-actions">
                <select aria-label={t('mg_unit')} value={unit} disabled={frozen} onChange={(event) => setUnit(event.target.value)}><option value="">{t('mg_unit_choose')}</option>{MIGRATION_UNITS.map((u) => <option key={u} value={u}>{t(`mg_unit_${u}`)}</option>)}</select>
                <input aria-label={t('mg_cost_per_ton')} type="number" min="0" step="0.01" placeholder={t('mg_cost_per_ton')} value={cost} disabled={frozen} onChange={(event) => setCost(event.target.value)} />
                <button type="button" className="table-action" disabled={frozen || !unit} onClick={saveProduct}>{t('mg_save')}</button>
            </div>}
            {nonWeight && <small className="permission-note">{t('mg_unit_warning')}</small>}
        </td>
        <td className="no-print"><div className="row-actions">
            {CREATABLE.includes(mapping.entity) && mapping.entity !== 'product' && !(hidePartyCreate && ['customer', 'supplier'].includes(mapping.entity) && ['create_required', 'matched'].includes(mapping.decision)) && <button type="button" className="table-action" disabled={frozen} onClick={() => onSave(mapping, { decision: 'create_required' })}>{t('mg_create_new')}</button>}
            {mapping.entity === 'category' && mapping.decision === 'needs_review' && <button type="button" className="table-action" disabled={frozen} onClick={() => onSave(mapping, { decision: 'create_required' })}>{t('mg_create_new')}</button>}
            <button type="button" className="table-action table-action--danger" disabled={frozen} onClick={() => onExclude(mapping)}>{t('mg_exclude')}</button>
        </div></td>
    </tr>;
}

function MappingsPanel({ base, options, counts, t, busy, canManage, phase1Bulk, focus, onSave, onExclude, onApproveSafe, onCreateAllParties, version }) {
    const [filters, setFilters] = useState({ entity: focus?.entity ?? '', decision: '', q: focus?.q ?? '' });
    const [applied, setApplied] = useState(filters);
    const [page, setPage] = useState(1);
    const [state, setState] = useState({ data: [], meta: null, loading: true, error: '' });
    const tRef = useRef(t);
    tRef.current = t;
    useEffect(() => {
        if (focus) {
            const next = { entity: focus.entity ?? '', decision: '', q: focus.q ?? '' };
            setFilters(next);
            setApplied(next);
            setPage(1);
        }
    }, [focus]);
    useEffect(() => {
        let cancelled = false;
        setState((previous) => ({ ...previous, loading: true }));
        window.axios.get(`${base}/mappings`, { params: { ...Object.fromEntries(Object.entries(applied).filter(([, value]) => value !== '')), page } })
            .then((response) => { if (!cancelled) setState({ data: response.data.data, meta: response.data.meta, loading: false, error: '' }); })
            .catch((error) => { if (!cancelled) setState({ data: [], meta: null, loading: false, error: explain(error, tRef.current) }); });

        return () => { cancelled = true; };
    }, [base, applied, page, version]);
    const totals = useMemo(() => Object.entries(counts ?? {}).map(([entity, byDecision]) => ({ entity, byDecision })), [counts]);

    return <div>
        <div className="summary-grid">{totals.map(({ entity, byDecision }) => <div key={entity} className="summary-card"><strong>{t(`mg_entity_${entity}`)}</strong>{Object.entries(byDecision).map(([decision, n]) => <span key={decision}>{t(`mg_decision_${decision}`)}: {n}</span>)}</div>)}</div>
        <form className="report-filter-bar no-print" onSubmit={(event) => { event.preventDefault(); setApplied(filters); setPage(1); }}>
            <label className="field"><span>{t('mg_filter_entity')}</span><select value={filters.entity} onChange={(event) => setFilters({ ...filters, entity: event.target.value })}><option value="">{t('mg_all')}</option>{MIGRATION_ENTITIES.map((entity) => <option key={entity} value={entity}>{t(`mg_entity_${entity}`)}</option>)}</select></label>
            <label className="field"><span>{t('mg_filter_decision')}</span><select value={filters.decision} onChange={(event) => setFilters({ ...filters, decision: event.target.value })}><option value="">{t('mg_all')}</option>{MIGRATION_DECISIONS.map((decision) => <option key={decision} value={decision}>{t(`mg_decision_${decision}`)}</option>)}</select></label>
            <label className="field"><span>{t('mg_search')}</span><input type="search" value={filters.q} onChange={(event) => setFilters({ ...filters, q: event.target.value })} /></label>
            <div className="erp-toolbar"><button type="submit" className="erp-button erp-button--primary">{t('mg_apply')}</button>{canManage && <button type="button" className="erp-button erp-button--secondary" disabled={busy} onClick={onApproveSafe}>{t('mg_approve_safe')}</button>}{phase1Bulk && <button type="button" className="erp-button erp-button--primary" disabled={busy} onClick={onCreateAllParties}>{t('mg_phase1_create_all_parties')}</button>}</div>
        </form>
        {state.error && <Notice type="error">{state.error}</Notice>}
        <div className="erp-table-wrap"><table className="erp-table" aria-busy={state.loading}>
            <thead><tr><th>{t('mg_filter_entity')}</th><th>{t('mg_source_key')}</th><th>{t('mg_source_name')}</th><th>{t('mg_decision')}</th><th>{t('mg_target')}</th><th className="no-print">{t('actions')}</th></tr></thead>
            <tbody>
                {state.data.map((mapping) => <MappingRow key={`${mapping.id}-${mapping.decision}-${mapping.target_id}-${mapping.unit_basis}`} mapping={mapping} options={options} t={t} disabled={busy || !canManage} hidePartyCreate={Boolean(phase1Bulk)} onSave={onSave} onExclude={onExclude} />)}
                {!state.loading && state.data.length === 0 && <tr><td colSpan={6} className="empty-cell">{t('mg_mappings_empty')}</td></tr>}
            </tbody>
        </table></div>
        <Pager meta={state.meta} onPage={setPage} t={t} busy={state.loading} />
    </div>;
}

function RecordsPanel({ base, t, money, busy, canManage, version, onExclude, onInclude, initialStatus }) {
    const [filters, setFilters] = useState({ entity: '', status: initialStatus ?? '', code: '', q: '' });
    const [applied, setApplied] = useState(filters);
    const [page, setPage] = useState(1);
    const [state, setState] = useState({ data: [], meta: null, loading: true, error: '' });
    const tRef = useRef(t);
    tRef.current = t;
    useEffect(() => {
        let cancelled = false;
        setState((previous) => ({ ...previous, loading: true }));
        window.axios.get(`${base}/records`, { params: { ...Object.fromEntries(Object.entries(applied).filter(([, value]) => value !== '')), page } })
            .then((response) => { if (!cancelled) setState({ data: response.data.data, meta: response.data.meta, loading: false, error: '' }); })
            .catch((error) => { if (!cancelled) setState({ data: [], meta: null, loading: false, error: explain(error, tRef.current) }); });

        return () => { cancelled = true; };
    }, [base, applied, page, version]);

    return <div>
        <form className="report-filter-bar no-print" onSubmit={(event) => { event.preventDefault(); setApplied(filters); setPage(1); }}>
            <label className="field"><span>{t('mg_record_type')}</span><select value={filters.entity} onChange={(event) => setFilters({ ...filters, entity: event.target.value })}><option value="">{t('mg_all')}</option>{MIGRATION_RECORD_TYPES.map((type) => <option key={type} value={type}>{t(`mg_type_${type}`)}</option>)}</select></label>
            <label className="field"><span>{t('mg_filter_status')}</span><select value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}><option value="">{t('mg_all')}</option>{MIGRATION_RECORD_STATUSES.map((status) => <option key={status} value={status}>{t(`mg_record_status_${status}`)}</option>)}</select></label>
            <label className="field"><span>{t('mg_filter_code')}</span><input value={filters.code} onChange={(event) => setFilters({ ...filters, code: event.target.value })} /></label>
            <label className="field"><span>{t('mg_search')}</span><input type="search" value={filters.q} onChange={(event) => setFilters({ ...filters, q: event.target.value })} /></label>
            <div className="erp-toolbar"><button type="submit" className="erp-button erp-button--primary">{t('mg_apply')}</button></div>
        </form>
        {state.error && <Notice type="error">{state.error}</Notice>}
        <div className="erp-table-wrap"><table className="erp-table" aria-busy={state.loading}>
            <thead><tr><th>{t('mg_record_number')}</th><th>{t('mg_record_date')}</th><th>{t('mg_record_type')}</th><th>{t('mg_record_amount')}</th><th>{t('mg_filter_status')}</th><th>{t('mg_record_reason')}</th><th>{t('mg_record_target')}</th><th className="no-print">{t('actions')}</th></tr></thead>
            <tbody>
                {state.data.map((record) => <tr key={record.id}>
                    <td><code>{record.number ?? record.source_id}</code></td>
                    <td>{record.date ?? '—'}</td>
                    <td>{t(`mg_type_${record.entity}`)}</td>
                    <td>{record.amount !== null && record.amount !== undefined ? money(record.amount) : '—'}</td>
                    <td><StatusBadge status={RECORD_TONE[record.status] ?? 'info'} label={t(`mg_record_status_${record.status}`)} /></td>
                    <td>{record.code ? codeLabel(t, record.code) : (record.error ?? '—')}{record.exclusion_reason && <small className="table-subline">{record.exclusion_reason}</small>}</td>
                    <td>{record.target ?? '—'}</td>
                    <td className="no-print"><div className="row-actions">
                        {canManage && ['ready', 'needs_review', 'failed'].includes(record.status) && <button type="button" className="table-action table-action--danger" disabled={busy} onClick={() => onExclude(record)}>{t('mg_exclude')}</button>}
                        {canManage && record.status === 'excluded' && <button type="button" className="table-action" disabled={busy} onClick={() => onInclude(record)}>{t('mg_include')}</button>}
                    </div></td>
                </tr>)}
                {!state.loading && state.data.length === 0 && <tr><td colSpan={8} className="empty-cell">{t('mg_records_empty')}</td></tr>}
            </tbody>
        </table></div>
        <Pager meta={state.meta} onPage={setPage} t={t} busy={state.loading} />
    </div>;
}

function OpeningsPreview({ preview, t, money }) {
    if (!preview) return null;
    const totals = preview.totals ?? {};
    const rows = [['mg_customers_debit', totals.customers_debit], ['mg_customers_credit', totals.customers_credit], ['mg_suppliers_credit', totals.suppliers_credit], ['mg_suppliers_debit', totals.suppliers_debit], ['mg_inventory_value', totals.inventory_value], ['mg_cash', totals.cash], ['mg_bank', totals.bank], ['mg_equity', totals.equity_balancing]];
    const stock = (preview.stock ?? []).reduce((acc, row) => ({ ...acc, [row.status]: (acc[row.status] ?? 0) + 1 }), {});

    return <div>
        <div className="erp-table-wrap"><table className="erp-table"><tbody>{rows.map(([label, value]) => <tr key={label}><th scope="row">{t(label)}</th><td>{money(value ?? 0)}</td></tr>)}</tbody></table></div>
        <p className="permission-note">{t('mg_stock_rows')}: {(preview.stock ?? []).length} · {t('mg_stock_posting')}: {stock.post ?? 0} · {t('mg_stock_skipped')}: {stock.skipped_acknowledged ?? 0} · {t('mg_stock_zero')}: {stock.zero ?? 0}</p>
    </div>;
}

function Reconciliation({ data, t, money }) {
    if (!data) return null;
    const documents = Object.entries(data.documents ?? {});

    return <div>
        <Notice type={data.opening?.balanced ? 'info' : 'error'}>{data.opening?.balanced ? t('mg_balanced') : t('mg_unbalanced')}</Notice>
        <div className="erp-table-wrap"><table className="erp-table">
            <thead><tr><th>{t('mg_record_type')}</th><th>{t('mg_total')}</th><th>{t('mg_legacy_amount')}</th><th>{t('mg_erp_amount')}</th><th>{t('mg_difference')}</th></tr></thead>
            <tbody>{documents.map(([type, row]) => <tr key={type}><td>{t(`mg_type_${type}`)}</td><td>{row.total}</td><td>{money(row.legacy_amount_created ?? 0)}</td><td>{money(row.erp_amount_created ?? 0)}</td><td>{money(row.amount_difference ?? 0)}</td></tr>)}</tbody>
        </table></div>
    </div>;
}

function PhaseOneReconciliation({ data, t, money }) {
    if (!data) return null;
    return <div>
        <Notice type={data.balanced ? 'info' : 'error'}>{data.balanced ? t('mg_balanced') : t('mg_unbalanced')}</Notice>
        <div className="summary-grid">{[['mg_phase1_source_positive', data.source_positive], ['mg_phase1_source_negative', data.source_negative], ['mg_phase1_debit', data.target?.debit], ['mg_phase1_credit', data.target?.credit], ['mg_difference', (data.difference?.debit ?? 0) + (data.difference?.credit ?? 0)], ['mg_phase1_supplier_sales', data.supplier_sales?.total], ['mg_phase1_supplier_sales_review', data.supplier_sales?.review_or_excluded]].map(([label, value]) => <div key={label} className="summary-card"><strong>{t(label)}</strong><span>{typeof value === 'number' ? money(value) : (value ?? 0)}</span></div>)}</div>
        {data.unresolved_count > 0 && <Notice type="error">{t('mg_phase1_issue_unresolved')} ({data.unresolved_count})</Notice>}
    </div>;
}

function phaseOneIssueOptions(code) {
    if (code === 'supplier_formula_differs_from_legacy_runtime') return ['approve_details', 'exclude_party'];
    if (code === 'supplier_category_conversion_differs_from_legacy') return ['approve_category_rule', 'exclude_party'];
    if (code === 'supplier_sale_amount_mismatch' || code === 'supplier_sale_detail_total_mismatch') return ['include_eda3', 'exclude'];
    if (['invalid_customer_start_balance', 'supplier_start_amount_invalid', 'sales_business_date_missing', 'customer_sale_amount_invalid', 'purchase_business_date_missing', 'supplier_payment_amount_invalid', 'supplier_header_amount_invalid', 'purchase_category_flag_missing', 'purchase_line_amount_invalid'].includes(code)) return ['exclude_component', 'exclude_party'];

    return ['exclude'];
}

function phaseOneDefaultDecision(code) {
    return code?.includes('formula') ? 'approve_details' : code?.includes('sale_') ? 'include_eda3' : code?.includes('start_balance') || code?.includes('amount_invalid') ? 'exclude_component' : 'exclude';
}

function PhaseOneIssueDialog({ issue, t, onConfirm, onClose }) {
    const [decision, setDecision] = useState(phaseOneDefaultDecision(issue.code));
    const options = phaseOneIssueOptions(issue.code);

    return <ConfirmActionDialog title={t('mg_phase1_issue_decision')} impact={<p>{codeLabel(t, issue.code)} · {issue.source_table}#{issue.source_id}</p>} confirmLabel={t('mg_confirm')} onConfirm={({ reason }) => onConfirm({ decision, reason })} onClose={onClose}>
        <label className="field"><span>{t('mg_decision')}</span><select value={decision} onChange={(event) => setDecision(event.target.value)}>{options.map((option) => <option key={option} value={option}>{t(`mg_phase1_issue_${option}`)}</option>)}</select></label>
    </ConfirmActionDialog>;
}

function PhaseOneBulkIssueDialog({ group, t, onConfirm, onClose }) {
    const [decision, setDecision] = useState(phaseOneDefaultDecision(group.code));
    const options = phaseOneIssueOptions(group.code);

    return <ConfirmActionDialog title={`${t('mg_ack_all')} · ${group.count}`} impact={<><p>{codeLabel(t, group.code)}</p><p>{t('mg_ack_all_impact')}</p></>} confirmLabel={t('mg_confirm')} onConfirm={({ reason }) => onConfirm({ decision, reason })} onClose={onClose}>
        <label className="field"><span>{t('mg_decision')}</span><select value={decision} onChange={(event) => setDecision(event.target.value)}>{options.map((option) => <option key={option} value={option}>{t(`mg_phase1_issue_${option}`)}</option>)}</select></label>
    </ConfirmActionDialog>;
}

function PhaseOneIssueGroups({ issues, t, canManage, onBulk }) {
    if (!canManage || issues.length === 0) return null;
    const groups = Object.values(issues.reduce((result, issue) => {
        result[issue.code] ??= { code: issue.code, count: 0 };
        result[issue.code].count += 1;
        return result;
    }, {}));

    return <div className="erp-toolbar">{groups.map((group) => <button key={group.code} type="button" className="erp-button erp-button--secondary" onClick={() => onBulk(group)}>{t('mg_ack_all')} · {codeLabel(t, group.code)} ({group.count})</button>)}</div>;
}

export default function DataMigrationRun({ run: initialRun, options: initialOptions = {} }) {
    const { t, can, date, money, dateTime } = usePt();
    const api = usePlatformApi();
    const manage = can('data_migration.manage');
    const execute = can('data_migration.execute');
    const [run, setRun] = useState(initialRun);
    const [options, setOptions] = useState(initialOptions);
    const [busy, setBusy] = useState(null);
    const [error, setError] = useState('');
    const [blockers, setBlockers] = useState({ masters: null, openings: null, execution: null });
    const [preview, setPreview] = useState(null);
    const [reconciliation, setReconciliation] = useState(null);
    const [phase1, setPhase1] = useState(null);
    const [lastResult, setLastResult] = useState(null);
    const [events, setEvents] = useState(null);
    const [dialog, setDialog] = useState(null);
    const [focus, setFocus] = useState(null);
    const [version, setVersion] = useState(0);
    const [policy, setPolicy] = useState({ customer_start_balance_sign: initialRun.policy?.customer_start_balance_sign ?? '', supplier_start_balance_sign: initialRun.policy?.supplier_start_balance_sign ?? '' });
    const current = run.stages.find((stage) => stage.state === 'current' || stage.state === 'blocked') ?? run.stages[run.stages.length - 1];
    const [viewed, setViewed] = useState(current.id);
    useEffect(() => { setViewed(current.id); }, [current.id]);
    const busyRef = useRef(false);
    const base = `/super-admin/data-migrations/${run.id}`;
    const stage = run.stages.find((item) => item.id === viewed) ?? current;
    const isBusy = busy !== null || run.locked;

    const refresh = useCallback(async () => {
        const response = await window.axios.get(`${base}/state`);
        setRun(response.data.run);
        setOptions(response.data.options);
        setVersion((value) => value + 1);

        return response.data;
    }, [base]);

    const request = useCallback(async (method, path, data) => {
        const response = await api.call(method, `${base}${path}`, data);

        return response.data;
    }, [api, base]);

    /** Button actions: one at a time, failures are shown on the page, state is re-read afterwards. */
    const action = useCallback(async (key, method, path, data, onDone) => {
        if (busyRef.current) return null;
        busyRef.current = true;
        setBusy(key);
        setError('');
        try {
            const out = await request(method, path, data);
            onDone?.(out);
            await refresh();

            return out;
        } catch (failure) {
            if (failure?.message !== 'cancelled') {
                setError(explain(failure, t));
                if (failure?.response?.data?.blockers) setBlockers((previous) => ({ ...previous, execution: failure.response.data.blockers }));
            }

            return null;
        } finally {
            busyRef.current = false;
            setBusy(null);
        }
    }, [request, refresh, t]);

    /** Dialog actions: errors propagate so the dialog can show them inline. */
    const dialogAction = useCallback(async (method, path, data, onDone) => {
        let out;
        try {
            out = await request(method, path, data);
        } catch (failure) {
            if (failure?.response?.data?.code) failure.response.data.message = explain(failure, t);
            throw failure;
        }
        onDone?.(out);
        await refresh();

        return out;
    }, [request, refresh, t]);

    useEffect(() => {
        if (['masters_created', 'openings_preflight', 'openings_ready', 'openings_posted', 'documents_ready', 'failed_recoverable', 'executing', 'completed_with_review', 'completed', 'signed_off'].includes(run.status) && viewed === 'openings') {
            let cancelled = false;
            window.axios.get(`${base}/openings/preview`).then((response) => { if (!cancelled) setPreview(response.data.preview); }).catch(() => { if (!cancelled) setPreview(null); });

            return () => { cancelled = true; };
        }

        return undefined;
    }, [base, run.status, viewed, version]);

    useEffect(() => {
        if (viewed === 'execution' && ['documents_ready', 'failed_recoverable', 'completed_with_review', 'executing'].includes(run.status)) {
            let cancelled = false;
            window.axios.get(`${base}/execution/preflight`).then((response) => { if (!cancelled) setBlockers((previous) => ({ ...previous, execution: response.data.blockers })); }).catch(() => undefined);

            return () => { cancelled = true; };
        }

        return undefined;
    }, [base, run.status, viewed, version]);

    const openMapping = (blocker) => {
        setFocus({ entity: blocker.entity, q: blocker.source_key ?? '', nonce: Date.now() });
        setViewed('mapping');
    };
    const saveMapping = (mapping, body) => action(`mapping-${mapping.id}`, 'put', `/mappings/${mapping.id}`, body);
    const records = run.counts?.records ?? {};
    const recordTotal = Object.values(records).reduce((sum, n) => sum + n, 0);
    const next = current.next_action ?? 'none';
    const status = run.status;
    const can_ = (target) => (run.allowed_next ?? []).includes(target);
    const executed = ['executing', 'completed', 'completed_with_review', 'failed_recoverable', 'signed_off', 'archived'].includes(status) || (records.created ?? 0) > 0;
    const phase1Only = run.mode === 'party_openings_phase1';
    const sourceClean = run.integrity?.material_clean;
    const button = (label, onClick, { primary = false, danger = false, disabled = false } = {}) => <button type="button" className={`erp-button ${danger ? 'erp-button--danger' : primary ? 'erp-button--primary' : 'erp-button--secondary'}`} disabled={isBusy || disabled} onClick={onClick}>{busy && !disabled && primary ? t('mg_working') : label}</button>;
    const reasonDialog = (title, impact, label, path, danger, after, method = 'post') => setDialog({ title, impact, label, danger, withReason: true, run: ({ reason }) => dialogAction(method, path, { reason }, after) });
    const loadPhase1 = () => action('phase1-preview', 'get', '/phase1/preview', undefined, (out) => setPhase1(out.phase1));

    const panels = {
        upload: <div className="erp-table-wrap"><table className="erp-table"><tbody>
            <tr><th scope="row">{t('mg_source_file')}</th><td>{run.source_name}</td></tr>
            <tr><th scope="row">{t('mg_hash')}</th><td><code>{run.source_hash}</code></td></tr>
            <tr><th scope="row">{t('mg_size')}</th><td>{Math.round((run.source_bytes ?? 0) / 1024)} KB</td></tr>
            <tr><th scope="row">{t('mg_retention_until')}</th><td>{run.source_retention_until ? date(run.source_retention_until) : '—'}</td></tr>
            {run.gap_reason && <tr><th scope="row">{t('mg_gap_ack')}</th><td>{run.gap_reason}</td></tr>}
        </tbody></table>{run.source_removed && <Notice>{t('mg_source_removed')}</Notice>}</div>,
        parse: <div>
            {run.integrity && Object.keys(run.integrity).length > 0 && <>
                <Notice type={sourceClean ? 'info' : 'error'}>{sourceClean ? t('mg_clean') : t('mg_not_clean')}</Notice>
                <p className="permission-note">{t('mg_statements')}: {run.integrity.statements ?? 0} · {t('mg_uncompressed')}: {Math.round((run.integrity.uncompressed_bytes ?? 0) / 1024)} KB · {t('mg_errors_count')}: {run.integrity.error_count ?? 0}</p>
                <div className="erp-table-wrap"><table className="erp-table">
                    <thead><tr><th>{t('mg_table')}</th><th>{t('mg_rows')}</th><th>{t('mg_staged')}</th><th>{t('mg_rejected')}</th></tr></thead>
                    <tbody>{Object.entries(run.integrity.tables ?? {}).filter(([name]) => name in (run.integrity.staged_rows ?? {})).map(([name, info]) => <tr key={name}><td><code>{name}</code></td><td>{info.rows}</td><td>{run.integrity.staged_rows?.[name] ?? 0}</td><td>{info.rejected}</td></tr>)}</tbody>
                </table></div>
            </>}
            {manage && can_('parsing') && <div className="erp-toolbar">{status === 'uploaded'
                ? button(t('mg_parse_action'), () => action('parse', 'post', '/parse'), { primary: true })
                : button(t('mg_reparse_action'), () => setDialog({ title: t('mg_reparse_action'), impact: t('mg_parse_impact'), label: t('mg_reparse_action'), withReason: false, run: () => dialogAction('post', '/parse') }))}</div>}
        </div>,
        dates: <div>
            <div className="erp-table-wrap"><table className="erp-table"><tbody>
                <tr><th scope="row">{t('mg_cutoff')}</th><td>{date(run.opening_balance_as_of)}</td></tr>
                <tr><th scope="row">{t('mg_from')}</th><td>{date(run.documents_from)}</td></tr>
                <tr><th scope="row">{t('mg_to')}</th><td>{date(run.documents_to)}</td></tr>
            </tbody></table></div>
            <h3>{t('mg_policy')}</h3>
            <div className="posting-form__grid">{Object.entries(SIGNS).map(([name, choices]) => <label key={name} className="field"><span>{t(name === 'customer_start_balance_sign' ? 'mg_cust_sign' : 'mg_supp_sign')}</span>
                <select value={policy[name]} disabled={!manage || isBusy || executed} onChange={(event) => setPolicy({ ...policy, [name]: event.target.value })}><option value="">{t('mg_sign_choose')}</option>{choices.map((choice) => <option key={choice} value={choice}>{t(`mg_sign_${choice}`)}</option>)}</select></label>)}</div>
            <p className="permission-note">{t('mg_policy_impact')}</p>
            {manage && !executed && <div className="erp-toolbar">{button(t('mg_policy_save'), () => action('policy', 'post', '/policy', Object.fromEntries(Object.entries(policy).filter(([, value]) => value))), { primary: true, disabled: !policy.customer_start_balance_sign && !policy.supplier_start_balance_sign })}</div>}
        </div>,
        mapping: <MappingsPanel base={base} options={options} counts={run.counts?.mappings} t={t} busy={isBusy} canManage={manage && ['review', 'masters_ready', 'masters_created', 'openings_ready', 'uploaded', 'parsed'].includes(status)} phase1Bulk={phase1Only && execute && ['review', 'party_openings_preflight', 'party_openings_ready'].includes(status)} focus={focus} version={version}
            onSave={saveMapping} onExclude={(mapping) => setDialog({ title: t('mg_exclude_title'), impact: t('mg_exclude_impact'), label: t('mg_exclude'), danger: true, withReason: true, run: ({ reason }) => dialogAction('put', `/mappings/${mapping.id}`, { decision: 'excluded', reason }) })}
            onApproveSafe={() => setDialog({ title: t('mg_approve_safe'), impact: t('mg_approve_safe_impact'), label: t('mg_approve_safe'), withReason: false, run: () => dialogAction('post', '/mappings/approve-safe', {}) })}
            onCreateAllParties={() => setDialog({ title: t('mg_phase1_create_all_parties'), impact: t('mg_phase1_create_all_parties_impact'), label: t('mg_phase1_create_all_parties'), withReason: false, run: () => dialogAction('post', '/phase1/parties/create-all', {}) })} />,
        masters: <div>
            <div className="erp-toolbar">
                {manage && ['review', 'masters_ready', 'masters_preflight'].includes(status) && button(t('mg_masters_preflight'), () => action('masters-preflight', 'post', '/masters/preflight', {}, (out) => setBlockers((previous) => ({ ...previous, masters: out.preflight.blockers, mastersCounts: out.preflight.counts, mastersOk: out.preflight.ok }))), { primary: true })}
                {execute && status === 'masters_ready' && button(t('mg_masters_create'), () => setDialog({ title: t('mg_masters_create'), impact: t('mg_masters_create_impact'), label: t('mg_masters_create'), withReason: false, run: () => dialogAction('post', '/masters/create', {}) }), { primary: true })}
            </div>
            {blockers.mastersOk && <Notice>{t('mg_masters_ok')}</Notice>}
            {blockers.mastersCounts && <p className="permission-note">{t('mg_masters_counts')}: {Object.entries(blockers.mastersCounts).map(([key, n]) => `${t(`mg_decision_${key}`)}: ${n}`).join(' · ')}</p>}
            {blockers.masters && <BlockerList blockers={blockers.masters} t={t} onOpenMapping={openMapping} />}
        </div>,
        openings: phase1Only ? <div>
            <Notice>{t('mg_phase1_scope_notice')}</Notice>
            <div className="erp-toolbar">
                {manage && ['review', 'party_openings_preflight', 'party_openings_ready'].includes(status) && button(t('mg_phase1_preflight'), () => action('phase1-preflight', 'post', '/phase1/preflight', {}, (out) => setPhase1({ ...(out.phase1?.preview ?? {}), phase1_preflight: out.phase1 })), { primary: true })}
                {execute && status === 'party_openings_ready' && button(t('mg_phase1_create_masters'), () => action('phase1-masters', 'post', '/phase1/masters/create'), { primary: true })}
                {execute && status === 'party_openings_ready' && button(t('mg_phase1_post'), () => setDialog({ title: t('mg_phase1_post'), impact: t('mg_phase1_scope_notice'), label: t('mg_phase1_post'), danger: true, withReason: false, run: () => dialogAction('post', '/phase1/post', {}, (out) => setPhase1(out.result?.reconciliation)) }), { primary: true })}
                {execute && ['party_openings_posted', 'phase1_completed'].includes(status) && button(t('mg_openings_reverse'), () => reasonDialog(t('mg_openings_reverse'), t('mg_phase1_scope_notice'), t('mg_openings_reverse'), '/phase1/reverse', true), { danger: true })}
                {button(t('mg_phase1_reconcile'), loadPhase1)}
            </div>
            {!phase1 && <p className="permission-note">{t('mg_phase1_cutoff_required')}</p>}
            {phase1 && <>
                <p className="permission-note">{t('mg_phase1_cutoff')}: {phase1.cutoff_date} · {t('mg_phase1_effective')}: {phase1.opening_effective_date} · {phase1.timezone}</p>
                <div className="summary-grid">{[['mg_phase1_customer', phase1.counts?.customer_mappings], ['mg_phase1_supplier', phase1.counts?.supplier_mappings], ['mg_phase1_supplier_sales', phase1.counts?.supplier_sales], ['mg_phase1_supplier_sales_review', phase1.counts?.supplier_sales_review], ['mg_phase1_source_positive', phase1.totals?.source_positive], ['mg_phase1_source_negative', phase1.totals?.source_negative]].map(([label, value]) => <div key={label} className="summary-card"><strong>{t(label)}</strong><span>{value ?? 0}</span></div>)}</div>
                {!phase1.ok && <Notice type="error">{t('mg_phase1_issue_unresolved')}</Notice>}
                {phase1.unresolved?.length > 0 && <>
                    <PhaseOneIssueGroups issues={phase1.unresolved} t={t} canManage={manage} onBulk={(group) => setDialog({ phase1BulkIssue: group })} />
                    <BlockerList blockers={phase1.unresolved.map((issue) => ({ ...issue, name: codeLabel(t, issue.code), ack: issue.code }))} t={t} canManage={manage} onAcknowledge={(issue) => setDialog({ phase1Issue: issue })} />
                </>}
                <OpeningsPreview preview={{ totals: { customers_debit: phase1.totals?.customer_debit, customers_credit: phase1.totals?.customer_credit, suppliers_debit: phase1.totals?.supplier_debit, suppliers_credit: phase1.totals?.supplier_credit, inventory_value: 0, cash: 0, bank: 0, equity_balancing: 0 }, stock: [] }} t={t} money={money} />
                <Notice>{t('mg_phase1_no_documents')}</Notice>
            </>}
        </div> : <div>
            <div className="erp-toolbar">
                {manage && ['masters_created', 'openings_ready', 'openings_preflight'].includes(status) && button(t('mg_openings_preflight'), () => action('openings-preflight', 'post', '/openings/preflight', {}, (out) => setBlockers((previous) => ({ ...previous, openings: out.blockers }))), { primary: true })}
                {execute && status === 'openings_ready' && button(t('mg_openings_post'), () => setDialog({ title: t('mg_openings_post'), impact: t('mg_openings_post_impact'), label: t('mg_openings_post'), withReason: false, run: () => dialogAction('post', '/openings/post', {}) }), { primary: true })}
                {execute && status === 'openings_posted' && button(t('mg_openings_reverse'), () => reasonDialog(t('mg_openings_reverse'), t('mg_openings_reverse_impact'), t('mg_openings_reverse'), '/openings/reverse', true), { danger: true })}
            </div>
            {blockers.openings && <BlockerList blockers={blockers.openings} t={t} canManage={manage && !executed} onAcknowledge={(blocker) => setDialog({ title: t('mg_ack_title'), impact: <><p>{codeLabel(t, blocker.code)}</p><p>{t(`mg_ack_hint_${blocker.ack}`)}</p><p>{t('mg_ack_impact')}</p></>, label: t('mg_ack'), withReason: true, run: ({ reason }) => dialogAction('post', '/policy', { ack: blocker.ack, reason }) })} />}
            <OpeningsPreview preview={preview} t={t} money={money} />
        </div>,
        staging: phase1Only ? <Notice>{t('mg_phase1_no_documents')}</Notice> : <div>
            <p className="permission-note">{t('mg_staging_impact')}</p>
            {manage && status === 'openings_posted' && <div className="erp-toolbar">{button(t('mg_staging_action'), () => action('stage', 'post', '/documents/stage'), { primary: true })}</div>}
            {recordTotal > 0 && <p>{t('mg_staged_counts')}: {Object.entries(records).map(([key, n]) => `${t(`mg_record_status_${key}`)}: ${n}`).join(' · ')}</p>}
        </div>,
        review: <RecordsPanel base={base} t={t} money={money} busy={isBusy} canManage={manage && ['documents_ready', 'failed_recoverable', 'completed_with_review'].includes(status)} version={version}
            onExclude={(record) => setDialog({ title: t('mg_exclude_title'), impact: t('mg_exclude_impact'), label: t('mg_exclude'), danger: true, withReason: true, run: ({ reason }) => dialogAction('post', `/records/${record.id}/exclude`, { reason }) })}
            onInclude={(record) => action(`include-${record.id}`, 'post', `/records/${record.id}/include`, {})} />,
        execution: phase1Only ? <Notice>{t('mg_phase1_no_documents')}</Notice> : <div>
            <div className="erp-toolbar">{execute && ['documents_ready', 'failed_recoverable', 'completed_with_review', 'executing'].includes(status) && button(status === 'executing' ? t('mg_execute_resume') : t('mg_execute'), () => setDialog({ title: t('mg_execute'), impact: t('mg_execute_impact'), label: t('mg_execute'), danger: true, withReason: false, run: () => dialogAction('post', '/execute', {}, (out) => setLastResult(out.result)) }), { primary: true, disabled: Boolean(blockers.execution?.length) })}</div>
            {blockers.execution?.length > 0 && <><Notice type="error">{t('mg_exec_blockers')}</Notice><BlockerList blockers={blockers.execution} t={t} onOpenMapping={openMapping} /></>}
            {recordTotal > 0 && <p>{Object.entries(records).map(([key, n]) => `${t(`mg_record_status_${key}`)}: ${n}`).join(' · ')}</p>}
            {lastResult && <p className="permission-note">{t('mg_execute_result')}: {['created', 'already_done', 'failed', 'remaining'].map((key) => `${t(`mg_exec_${key}`)}: ${lastResult[key] ?? 0}`).join(' · ')}</p>}
            {(records.failed ?? 0) > 0 && <Notice type="error">{t('mg_failed_records')}</Notice>}
        </div>,
        reconciliation: <div>
            <div className="erp-toolbar">
                {button(t('mg_load_reconciliation'), () => action('reconciliation', 'get', phase1Only ? '/phase1/reconciliation' : '/reconciliation', undefined, (out) => setReconciliation(out.reconciliation)), { disabled: !executed && !['openings_posted', 'party_openings_posted', 'phase1_completed', 'documents_ready'].includes(status) })}
                {execute && (status === 'completed' || status === 'phase1_completed') && button(t('mg_sign_off'), () => reasonDialog(t('mg_sign_off'), t('mg_sign_off_impact'), t('mg_sign_off'), '/sign-off', false, (out) => setReconciliation(out.reconciliation)), { primary: true })}
            </div>
            {phase1Only ? <PhaseOneReconciliation data={reconciliation} t={t} money={money} /> : <Reconciliation data={reconciliation} t={t} money={money} />}
        </div>,
        archive: <div>
            <p className="permission-note">{t('mg_archive_impact')}</p>
            <div className="erp-toolbar">{execute && ['signed_off', 'failed_terminal'].includes(status) && button(t('mg_archive'), () => reasonDialog(t('mg_archive'), t('mg_archive_impact'), t('mg_archive'), '/archive', true), { danger: true })}</div>
        </div>,
    };

    return <SuperAdminLayout title={t('mg_title')}>
        <PageHeader title={`${t('mg_title')} · ${run.company?.name ?? ''}`} intro={run.source_name} actions={<><a className="erp-button erp-button--secondary" href="/super-admin/data-migrations">{t('mg_back')}</a><button type="button" className="erp-button erp-button--secondary" disabled={busy !== null} onClick={() => refresh().catch((failure) => setError(explain(failure, t)))}>{t('mg_refresh')}</button></>} />
        <section className="erp-section" aria-label={t('mg_current')}>
            <p><StatusBadge status={TONE[status] ?? 'info'} label={t(`mg_status_${status}`)} /> <small>{t('mg_hash')}: <code>{String(run.source_hash).slice(0, 12)}</code> · {date(run.opening_balance_as_of)} → {date(run.documents_to)}</small></p>
            {run.locked && <Notice>{t('mg_state_busy')}</Notice>}
            {error && <Notice type="error">{error}</Notice>}
            <div className="impact-box" role="group" aria-label={t('mg_next_action')}>
                <strong>{t('mg_next_action')}</strong>
                <p>{t(`mg_next_${next}`)}</p>
                <small>{current.irreversible && `${t('mg_irreversible')}. `}{current.stepup && `${t('mg_stepup')}. `}{current.retry && t('mg_retryable')}</small>
            </div>
        </section>
        <nav className="erp-section" aria-label={t('mg_stages')}>
            <ol className="stage-rail">{run.stages.map((item, index) => <li key={item.id} aria-current={item.state === 'current' ? 'step' : undefined}>
                <button type="button" className={`erp-button ${item.id === viewed ? 'erp-button--primary' : 'erp-button--secondary'}`} onClick={() => setViewed(item.id)}>{index + 1}. {t(`mg_stage_${item.id}`)}</button>
                <StatusBadge status={STAGE_TONE[item.state] ?? 'info'} label={t(`mg_state_${item.state}`)} />
            </li>)}</ol>
        </nav>
        <section className="erp-section" aria-label={t(`mg_stage_${stage.id}`)}>
            <h2>{t(`mg_stage_${stage.id}`)}</h2>
            <p className="permission-note">{t(`mg_stage_desc_${stage.id}`)}</p>
            {panels[stage.id]}
        </section>
        <section className="erp-section" aria-label={t('mg_reports')}>
            <h2>{t('mg_reports')}</h2>
            <div className="erp-toolbar">{MIGRATION_REPORTS.map((type) => <a key={type} className="erp-button erp-button--secondary" href={`${base}/reports/${type}`} download>{t(`mg_report_${type}`)} · {t('mg_download')}</a>)}</div>
        </section>
        <section className="erp-section" aria-label={t('mg_events')}>
            <h2>{t('mg_events')}</h2>
            {events === null
                ? <button type="button" className="erp-button erp-button--secondary" disabled={busy !== null} onClick={() => action('events', 'get', '/events', undefined, (out) => setEvents(out.events))}>{t('mg_events')}</button>
                : <div className="erp-table-wrap"><table className="erp-table"><thead><tr><th>{t('mg_event_when')}</th><th>{t('mg_event_action')}</th><th>{t('mg_event_from')}</th><th>{t('mg_event_to')}</th></tr></thead><tbody>{events.map((event) => <tr key={event.id}><td>{dateTime(event.created_at)}</td><td>{event.action}{event.reason && <small className="table-subline">{event.reason}</small>}</td><td>{event.from_state ? t(`mg_status_${event.from_state}`) : '—'}</td><td>{event.to_state ? t(`mg_status_${event.to_state}`) : '—'}</td></tr>)}</tbody></table></div>}
        </section>
        {dialog?.phase1Issue && <PhaseOneIssueDialog issue={dialog.phase1Issue} t={t} onConfirm={({ decision, reason }) => dialogAction('post', '/phase1/issues', { issue_key: dialog.phase1Issue.issue_key, decision, reason }, (out) => setPhase1(out.phase1)).then(() => setDialog(null))} onClose={() => setDialog(null)} />}
        {dialog?.phase1BulkIssue && <PhaseOneBulkIssueDialog group={dialog.phase1BulkIssue} t={t} onConfirm={({ decision, reason }) => dialogAction('post', '/phase1/issues/bulk', { code: dialog.phase1BulkIssue.code, decision, reason }, (out) => setPhase1(out.phase1)).then(() => setDialog(null))} onClose={() => setDialog(null)} />}
        {dialog && !dialog.phase1Issue && <ConfirmActionDialog title={dialog.title} impact={dialog.impact} confirmLabel={dialog.label} danger={dialog.danger} withReason={dialog.withReason} onConfirm={dialog.run} onClose={() => setDialog(null)} resetKey={dialog.title} />}
        {api.stepUpDialog}
    </SuperAdminLayout>;
}
