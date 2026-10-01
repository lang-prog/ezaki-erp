import { useMemo, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';

const number = (value) => Number(value || 0);
const dateInput = (value) => value ? String(value).slice(0, 10) : '';

export default function BillForm({ type, document, branches, warehouses, parties, products = [], productTypes = [], diameters = [], vehicles = [], cashboxes = [], banks = [], accountingPolicy = {}, capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const text = (ar, en) => locale === 'ar' ? ar : en;
    const purchase = type === 'purchase';
    const usesActualPurchaseWeight = accountingPolicy.purchase_inventory_basis === 'actual_weight';
    const emptyLine = purchase
        ? { product_id: '', product_type_id: '', diameter_id: '', factory_weight: '', actual_weight: '', packages: '', unit_price: '', notes: '' }
        : { product_id: '', product_type_id: '', diameter_id: '', actual_weight: '', packages: '', unit_price: '', notes: '' };
    const empty = purchase
        ? { branch_id: '', warehouse_id: '', supplier_id: '', supplier_bill_number: '', supplier_bill_date: '', warehouse_entry_date: '', total_actual_weight: '', transport_cost: '', loading_cost: '', extra_cost: '', discount: '', payment_method: 'credit', paid_amount: '', cashbox_id: '', bank_id: '', due_date: '', vehicle_id: '', external_vehicle_plate: '', external_driver_name: '', notes: '', lines: [{ ...emptyLine }] }
        : { branch_id: '', warehouse_id: '', customer_id: '', customer_bill_number: '', bill_date: '', transport_cost: '', loading_cost: '', extra_cost: '', discount: '', payment_method: 'cash', paid_amount: '', cashbox_id: '', bank_id: '', due_date: '', vehicle_id: '', external_vehicle_plate: '', external_driver_name: '', notes: '', lines: [{ ...emptyLine }] };
    const [form, setForm] = useState(() => {
        if (!document) return { ...empty, lines: [{ ...emptyLine }] };

        return {
            ...empty,
            ...document,
            supplier_bill_date: dateInput(document.supplier_bill_date),
            bill_date: dateInput(document.bill_date),
            warehouse_entry_date: dateInput(document.warehouse_entry_date),
            due_date: dateInput(document.due_date),
            lines: document.lines?.length ? document.lines : [{ ...emptyLine }],
        };
    });
    const [notice, setNotice] = useState({ type: '', message: '' });
    const [warning, setWarning] = useState([]);
    const [allocation, setAllocation] = useState(null);
    const [revisionReason, setRevisionReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const endpoint = purchase ? 'purchase-bills' : 'sales-bills';
    const id = document?.id;

    const totals = useMemo(() => {
        const subtotal = form.lines.reduce((sum, line) => sum + number(purchase ? line.factory_weight : line.actual_weight) * number(line.unit_price), 0);
        const factoryWeight = form.lines.reduce((sum, line) => sum + number(line.factory_weight), 0);
        const actualWeight = form.lines.reduce((sum, line) => sum + number(line.actual_weight), 0);
        const packages = form.lines.reduce((sum, line) => sum + number(line.packages), 0);
        const extras = number(form.transport_cost) + number(form.loading_cost) + number(form.extra_cost);
        const base = Math.max(0, subtotal - number(form.discount) + extras);
        const vatRate = accountingPolicy.vat_enabled ? number(accountingPolicy.vat_rate) : 0;
        const vat = base * vatRate / 100;
        return { subtotal, factoryWeight, actualWeight, packages, vatRate, vat, total: base + vat };
    }, [form, purchase, accountingPolicy]);

    const set = (key, value) => setForm((current) => ({ ...current, [key]: value }));
    const setLine = (index, key, value) => setForm((current) => ({ ...current, lines: current.lines.map((line, lineIndex) => lineIndex === index ? { ...line, [key]: value } : line) }));
    const addLine = () => setForm((current) => ({ ...current, lines: [...current.lines, { ...emptyLine }] }));
    const removeLine = (index) => setForm((current) => ({ ...current, lines: current.lines.filter((_, lineIndex) => lineIndex !== index) }));
    const paymentSource = form.cashbox_id ? `cashbox:${form.cashbox_id}` : form.bank_id ? `bank:${form.bank_id}` : '';
    const setPaymentSource = (value) => {
        const [kind, sourceId] = value.split(':');
        setForm((current) => ({ ...current, cashbox_id: kind === 'cashbox' ? sourceId : '', bank_id: kind === 'bank' ? sourceId : '' }));
    };

    async function request(path, method, body) {
        setProcessing(true);
        setNotice({ type: '', message: '' });
        try {
            const response = await fetch(`/api/v1/${path}`, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': globalThis.document.querySelector('meta[name="csrf-token"]')?.content ?? '' }, body: body ? JSON.stringify(body) : undefined });
            const contentType = response.headers.get('content-type') || '';
            const result = contentType.includes('application/json') ? await response.json() : {};
            if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat().join(' ') : result.message || text('تعذر إتمام الطلب.', 'Request failed.'));
            return result;
        } catch (error) {
            setNotice({ type: 'error', message: error.message || text('حدث خطأ في الاتصال.', 'A network error occurred.') });
            return null;
        } finally {
            setProcessing(false);
        }
    }

    function payload() {
        const lines = form.lines.map((line) => {
            const normalized = { ...line };
            if (purchase && !usesActualPurchaseWeight) delete normalized.actual_weight;
            return normalized;
        });
        return {
            ...form,
            lines,
            total_factory_weight: purchase ? totals.factoryWeight : undefined,
            total_actual_weight: purchase ? (usesActualPurchaseWeight ? totals.actualWeight : number(form.total_actual_weight)) : totals.actualWeight,
            total_packages: purchase ? totals.packages : undefined,
            vat_rate: totals.vatRate,
        };
    }

    async function checkWarnings() {
        const party = purchase ? form.supplier_id : form.customer_id;
        const date = purchase ? form.supplier_bill_date : form.bill_date;
        if (!party || !date) return [];
        const result = await request(`${endpoint}/near-duplicates?${purchase ? 'supplier_id' : 'customer_id'}=${party}&${purchase ? 'supplier_bill_date' : 'bill_date'}=${date}${id ? `&id=${id}` : ''}`, 'GET');
        const matches = result?.data ?? [];
        setWarning(matches);
        return matches;
    }

    async function submit(event, approve = false) {
        event.preventDefault();
        const matches = await checkWarnings();
        if (matches.length && !window.confirm(text('عُثر على فواتير مشابهة. هل تريد المتابعة؟', 'Similar bills were found. Continue?'))) return;
        const revision = document?.status === 'approved';
        const body = revision ? { ...payload(), revision_reason: revisionReason } : payload();
        const saved = await request(revision ? `${endpoint}/${id}/revision` : (id ? `${endpoint}/${id}` : endpoint), revision || id ? 'PUT' : 'POST', body);
        if (!saved) return;
        if (approve && !revision) {
            const approved = await request(`${endpoint}/${saved.data.id}/approve`, 'POST');
            if (!approved) return;
        }
        setNotice({ type: 'success', message: text('تم حفظ المستند بنجاح.', 'Document saved successfully.') });
        window.setTimeout(() => { window.location.href = '/operations'; }, 350);
    }

    async function loadAllocation() {
        const result = await request(`bills/${purchase ? 'purchase' : 'sales'}/${id}/transport-allocation`, 'GET');
        if (result) setAllocation(result.data);
    }

    async function reverse() {
        if (!window.confirm(text('سيتم إنشاء قيد عكسي وإلغاء أثر المخزون. متابعة؟', 'A reversing journal and stock reversal will be created. Continue?'))) return;
        const result = await request(`${endpoint}/${id}/reverse`, 'POST', { date: new Date().toISOString().slice(0, 10) });
        if (result) window.location.href = '/operations';
    }

    async function approveDraft() {
        const result = await request(`${endpoint}/${id}/approve`, 'POST');
        if (result) window.location.href = '/operations';
    }

    const needsPaymentSource = form.payment_method === 'cash' || form.payment_method === 'partial';
    const needsDueDate = form.payment_method === 'credit' || form.payment_method === 'partial';

    return <CompanyLayout title={purchase ? t('purchases') : t('sales')}>
        <div className="page-header"><div><Link href="/operations" className="eyebrow">← {t('operations')}</Link><h1>{document ? t('edit') : t('create')} · {purchase ? t('purchases') : t('sales')}</h1><p>{text('احفظ مسودة أولًا أو اعتمد المستند لترحيل المخزون والقيد.', 'Save a draft first, or approve to post inventory and accounting.')}</p></div><span className={`status-pill ${document?.status === 'approved' ? 'success' : 'muted-pill'}`}>{t(document?.status ?? 'draft')}</span></div>
        {notice.message && <div role="status" aria-live="polite" className={`notice-card ${notice.type}`}>{notice.message}</div>}
        {warning.length > 0 && <div role="alert" className="notice-card warning">{text('فواتير مشابهة: ', 'Similar bills: ')}{warning.map((item) => item.internal_number).join(', ')}. {text('تم الاحتفاظ بكل المدخلات.', 'All input has been preserved.')}</div>}
        <form onSubmit={submit} className="bill-layout" aria-busy={processing}>
            <div className="bill-main">
                <section className="panel form-section"><div className="section-title"><span>01</span><div><h2>{text('بيانات المستند', 'Document details')}</h2><p>{text('الفرع والمخزن والطرف وتاريخ المستند.', 'Branch, warehouse, party and document date.')}</p></div></div><div className="form-grid">
                    <label className="field"><span>{t('branch')}</span><select required value={form.branch_id} onChange={(e) => { setForm({ ...form, branch_id: e.target.value, warehouse_id: '' }); }}><option value="">—</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
                    <label className="field"><span>{t('warehouse')}</span><select required value={form.warehouse_id} onChange={(e) => set('warehouse_id', e.target.value)}><option value="">—</option>{warehouses.filter((warehouse) => !form.branch_id || String(warehouse.branch_id) === String(form.branch_id)).map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}</select></label>
                    <label className="field"><span>{purchase ? t('supplier') : t('customer')}</span><select required value={purchase ? form.supplier_id : form.customer_id} onChange={(e) => set(purchase ? 'supplier_id' : 'customer_id', e.target.value)}><option value="">—</option>{parties.map((party) => <option key={party.id} value={party.id}>{party.name}</option>)}</select></label>
                    <label className="field"><span>{purchase ? t('supplierBillNumber') : t('customerBillNumber')}</span><input required value={purchase ? form.supplier_bill_number : form.customer_bill_number} onChange={(e) => set(purchase ? 'supplier_bill_number' : 'customer_bill_number', e.target.value)} /></label>
                    <label className="field"><span>{text('تاريخ الفاتورة', 'Bill date')}</span><input required type="date" value={purchase ? form.supplier_bill_date : form.bill_date} onChange={(e) => set(purchase ? 'supplier_bill_date' : 'bill_date', e.target.value)} /></label>
                    {purchase && <label className="field"><span>{t('warehouseEntryDate')}</span><input type="date" value={form.warehouse_entry_date ?? ''} onChange={(e) => set('warehouse_entry_date', e.target.value)} /></label>}
                    {purchase && !usesActualPurchaseWeight && <label className="field"><span>{t('actualReviewWeight')}</span><input type="number" min="0" step="0.001" value={form.total_actual_weight ?? ''} onChange={(e) => set('total_actual_weight', e.target.value)} /><small>{text('للمراجعة فقط ولا يغيّر رصيد المخزون.', 'Review-only; it does not change stock.')}</small></label>}
                </div></section>

                <section className="panel form-section"><div className="section-title"><span>02</span><div><h2>{t('lines')}</h2><p>{usesActualPurchaseWeight && purchase ? text('القيمة بوزن المصنع والمخزون بالوزن الفعلي.', 'Factory weight values the bill; actual weight updates inventory.') : text('أدخل الصنف والوزن والسعر لكل سطر.', 'Enter product, weight and price for each line.')}</p></div><button type="button" onClick={addLine} className="secondary-button">+ {t('addLine')}</button></div>
                    <div className="bill-lines">{form.lines.map((line, index) => <fieldset key={index} className="bill-line"><legend>{text('سطر', 'Line')} {index + 1}</legend><div className="line-grid">
                        <label className="field"><span>{text('صنف موجود', 'Existing product')}</span><select value={line.product_id ?? ''} onChange={(e) => setLine(index, 'product_id', e.target.value)}><option value="">{text('أو اختر النوع والقطر', 'Or select type and diameter')}</option>{products.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
                        {!line.product_id && <><label className="field"><span>{t('type')}</span><select required value={line.product_type_id ?? ''} onChange={(e) => setLine(index, 'product_type_id', e.target.value)}><option value="">—</option>{productTypes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label className="field"><span>{t('diameter')}</span><select required value={line.diameter_id ?? ''} onChange={(e) => setLine(index, 'diameter_id', e.target.value)}><option value="">—</option>{diameters.map((item) => <option key={item.id} value={item.id}>{item.millimeters} mm</option>)}</select></label></>}
                        {purchase && <label className="field"><span>{t('factoryWeight')}</span><input required type="number" min="0.001" step="0.001" value={line.factory_weight} onChange={(e) => setLine(index, 'factory_weight', e.target.value)} /></label>}
                        {(!purchase || usesActualPurchaseWeight) && <label className="field"><span>{t('actualWeight')}</span><input required type="number" min="0.001" step="0.001" value={line.actual_weight} onChange={(e) => setLine(index, 'actual_weight', e.target.value)} /></label>}
                        <label className="field"><span>{purchase ? t('packages') : t('optionalPackages')}</span><input required={purchase} type="number" min="0" step="0.001" value={line.packages ?? ''} onChange={(e) => setLine(index, 'packages', e.target.value)} /></label>
                        <label className="field"><span>{t('unitPrice')}</span><input required type="number" min="0" step="0.01" value={line.unit_price} onChange={(e) => setLine(index, 'unit_price', e.target.value)} /></label>
                        <label className="field line-notes"><span>{text('ملاحظات السطر', 'Line notes')}</span><input value={line.notes ?? ''} onChange={(e) => setLine(index, 'notes', e.target.value)} /></label>
                        {form.lines.length > 1 && <button type="button" onClick={() => removeLine(index)} className="danger-link">{t('remove')}</button>}
                    </div></fieldset>)}</div>
                </section>

                <section className="panel form-section"><div className="section-title"><span>03</span><div><h2>{text('التكاليف والدفع', 'Costs and payment')}</h2><p>{text('تُطبّق الضريبة من إعدادات الشركة ولا يمكن تجاوزها من الفاتورة.', 'VAT is enforced from company settings and cannot be overridden here.')}</p></div></div><div className="form-grid">
                    <label className="field"><span>{t('transport')}</span><input type="number" min="0" step="0.01" value={form.transport_cost ?? ''} onChange={(e) => set('transport_cost', e.target.value)} /></label>
                    <label className="field"><span>{t('loadingCost')}</span><input type="number" min="0" step="0.01" value={form.loading_cost ?? ''} onChange={(e) => set('loading_cost', e.target.value)} /></label>
                    <label className="field"><span>{t('extras')}</span><input type="number" min="0" step="0.01" value={form.extra_cost ?? ''} onChange={(e) => set('extra_cost', e.target.value)} /></label>
                    <label className="field"><span>{t('discount')}</span><input type="number" min="0" step="0.01" value={form.discount ?? ''} onChange={(e) => set('discount', e.target.value)} /></label>
                    <label className="field"><span>{text('طريقة الدفع', 'Payment method')}</span><select required value={form.payment_method} onChange={(e) => setForm({ ...form, payment_method: e.target.value, paid_amount: '', cashbox_id: '', bank_id: '' })}><option value="cash">{t('cash')}</option><option value="credit">{t('creditPayment')}</option><option value="partial">{t('partialPayment')}</option></select></label>
                    {needsPaymentSource && <label className="field"><span>{text('مصدر الدفع', 'Payment source')}</span><select required value={paymentSource} onChange={(e) => setPaymentSource(e.target.value)}><option value="">—</option><optgroup label={text('الخزائن', 'Cashboxes')}>{cashboxes.map((item) => <option key={`c-${item.id}`} value={`cashbox:${item.id}`}>{item.name}</option>)}</optgroup><optgroup label={text('البنوك', 'Banks')}>{banks.map((item) => <option key={`b-${item.id}`} value={`bank:${item.id}`}>{item.name}</option>)}</optgroup></select></label>}
                    {form.payment_method === 'partial' && <label className="field"><span>{text('المبلغ المدفوع', 'Paid amount')}</span><input required type="number" min="0.01" step="0.01" max={Math.max(0, totals.total - 0.01)} value={form.paid_amount ?? ''} onChange={(e) => set('paid_amount', e.target.value)} /></label>}
                    {needsDueDate && <label className="field"><span>{text('تاريخ الاستحقاق', 'Due date')}</span><input required={!purchase || form.payment_method === 'partial'} type="date" value={form.due_date ?? ''} onChange={(e) => set('due_date', e.target.value)} /></label>}
                    <label className="field"><span>{text('سيارة الشركة', 'Company vehicle')}</span><select value={form.vehicle_id ?? ''} onChange={(e) => set('vehicle_id', e.target.value)}><option value="">{t('externalVehicle')}</option>{vehicles.map((vehicle) => <option key={vehicle.id} value={vehicle.id}>{vehicle.plate}</option>)}</select></label>
                    {!form.vehicle_id && <><label className="field"><span>{t('externalPlate')}</span><input value={form.external_vehicle_plate ?? ''} onChange={(e) => set('external_vehicle_plate', e.target.value)} /></label><label className="field"><span>{t('driverName')}</span><input value={form.external_driver_name ?? ''} onChange={(e) => set('external_driver_name', e.target.value)} /></label></>}
                    <label className="field form-wide"><span>{text('ملاحظات المستند', 'Document notes')}</span><textarea rows="3" value={form.notes ?? ''} onChange={(e) => set('notes', e.target.value)} /></label>
                </div></section>
            </div>

            <aside className="bill-summary panel"><h2>{text('ملخص الفاتورة', 'Bill summary')}</h2><dl><div><dt>{text('الإجمالي الفرعي', 'Subtotal')}</dt><dd>{totals.subtotal.toFixed(2)}</dd></div>{purchase && <div><dt>{t('totalFactoryWeight')}</dt><dd>{totals.factoryWeight.toFixed(3)}</dd></div>}<div><dt>{t('totalActualWeight')}</dt><dd>{(purchase && !usesActualPurchaseWeight ? number(form.total_actual_weight) : totals.actualWeight).toFixed(3)}</dd></div>{purchase && <div><dt>{t('totalPackages')}</dt><dd>{totals.packages.toFixed(3)}</dd></div>}<div><dt>{text('ضريبة القيمة المضافة', 'VAT')} ({totals.vatRate}%)</dt><dd>{totals.vat.toFixed(2)}</dd></div><div className="summary-total"><dt>{text('الإجمالي النهائي', 'Grand total')}</dt><dd>{totals.total.toFixed(2)} EGP</dd></div></dl>
                {accountingPolicy.weight_policy_locked && <p className="summary-note">{text('سياسة الوزن مقفلة لحماية الأرصدة.', 'Weight policy is locked to protect balances.')}</p>}
                {document?.status === 'approved' && <label className="field"><span>{t('revisionReason')}</span><textarea required rows="3" value={revisionReason} onChange={(e) => setRevisionReason(e.target.value)} /></label>}
                <div className="bill-actions">{document?.status === 'approved' ? <>{capabilities[purchase ? 'purchases.update_approved' : 'sales.update_approved'] && <button disabled={processing} className="primary-button">{t('saveRevision')}</button>}{capabilities[purchase ? 'purchases.reverse' : 'sales.reverse'] && <button type="button" disabled={processing} onClick={reverse} className="danger-button">{t('reverse')}</button>}{id && <button type="button" disabled={processing} onClick={loadAllocation} className="secondary-button">{t('allocateExtras')}</button>}</> : <><button disabled={processing} className="secondary-button">{t('saveDraft')}</button>{capabilities[purchase ? 'purchases.approve' : 'sales.approve'] && <button disabled={processing} type="button" onClick={(event) => submit(event, true)} className="primary-button">{t('saveApprove')}</button>}{id && capabilities[purchase ? 'purchases.approve' : 'sales.approve'] && <button disabled={processing} type="button" onClick={approveDraft} className="primary-button">{t('approve')}</button>}</>}</div>
            </aside>
        </form>
        {allocation && <section className="panel allocation-panel"><h2>{t('allocationByTon')}</h2>{allocation.lines.map((line) => <p key={line.line_id}>{t('line')} {line.line_id}: {line.allocated_extra_cost} EGP</p>)}</section>}
    </CompanyLayout>;
}
