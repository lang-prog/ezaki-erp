import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';

const emptyPurchase = { branch_id: '', warehouse_id: '', supplier_id: '', supplier_bill_number: '', supplier_bill_date: '', warehouse_entry_date: '', total_factory_weight: 0, total_actual_weight: 0, total_packages: 0, transport_cost: 0, loading_cost: 0, extra_cost: 0, vat_rate: '', vehicle_id: '', external_vehicle_plate: '', external_driver_name: '', lines: [{ product_id: '', product_type_id: '', diameter_id: '', factory_weight: 0, packages: 0, unit_price: 0, notes: '' }] };
const emptySales = { branch_id: '', warehouse_id: '', customer_id: '', customer_bill_number: '', bill_date: '', total_actual_weight: 0, transport_cost: 0, loading_cost: 0, extra_cost: 0, discount: 0, vat_rate: '', payment_method: 'cash', due_date: '', vehicle_id: '', external_vehicle_plate: '', external_driver_name: '', lines: [{ product_id: '', product_type_id: '', diameter_id: '', actual_weight: 0, packages: '', unit_price: 0, notes: '' }] };

export default function BillForm({ type, document, branches, warehouses, parties, products, productTypes = [], diameters = [], vehicles, capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const purchase = type === 'purchase';
    const initial = document ? { ...document, lines: document.lines ?? [] } : (purchase ? emptyPurchase : emptySales);
    const [form, setForm] = useState(initial);
    const [notice, setNotice] = useState({ type: '', message: '' });
    const [warning, setWarning] = useState([]);
    const [allocation, setAllocation] = useState(null);
    const [revisionReason, setRevisionReason] = useState('');
    const [processing, setProcessing] = useState(false);

    const set = (key, value) => setForm({ ...form, [key]: value });
    const setLine = (index, key, value) => setForm({ ...form, lines: form.lines.map((line, lineIndex) => lineIndex === index ? { ...line, [key]: value } : line) });
    const addLine = () => setForm({ ...form, lines: [...form.lines, purchase ? { product_id: '', product_type_id: '', diameter_id: '', factory_weight: 0, packages: 0, unit_price: 0, notes: '' } : { product_id: '', product_type_id: '', diameter_id: '', actual_weight: 0, packages: '', unit_price: 0, notes: '' }] });
    const removeLine = (index) => setForm({ ...form, lines: form.lines.filter((_, lineIndex) => lineIndex !== index) });
    const endpoint = purchase ? 'purchase-bills' : 'sales-bills';
    const id = document?.id;

    async function request(path, method, body) {
        setProcessing(true); setNotice({ type: '', message: '' });
        try {
            const response = await fetch(`/api/v1/${path}`, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': globalThis.document.querySelector('meta[name="csrf-token"]')?.content ?? '' }, body: body ? JSON.stringify(body) : undefined });
            const result = await response.json();
            if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat().join(' ') : result.message || 'Request failed.');
            return result;
        } catch (error) { setNotice({ type: 'error', message: error.message }); return null; } finally { setProcessing(false); }
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
        if (matches.length && !window.confirm('Similar bills were found. Continue with this form?')) return;
        const revision = document?.status === 'approved';
        const body = revision ? { ...form, revision_reason: revisionReason } : form;
        const saved = await request(revision ? `${endpoint}/${id}/revision` : (id ? `${endpoint}/${id}` : endpoint), revision || id ? 'PUT' : 'POST', body);
        if (!saved) return;
        if (approve && !revision) {
            const approved = await request(`${endpoint}/${saved.data.id}/approve`, 'POST');
            if (!approved) return;
        }
        window.location.href = '/operations';
    }

    async function loadAllocation() {
        const result = await request(`bills/${purchase ? 'purchase' : 'sales'}/${id}/transport-allocation`, 'GET');
        if (result) setAllocation(result.data);
    }

    async function reverse() {
        const result = await request(`${endpoint}/${id}/reverse`, 'POST', { date: new Date().toISOString().slice(0, 10) });
        if (result) window.location.href = '/operations';
    }

    async function approveDraft() {
        const result = await request(`${endpoint}/${id}/approve`, 'POST');
        if (result) window.location.href = '/operations';
    }

    return <CompanyLayout title={purchase ? t('purchases') : t('sales')}>
        <div className="flex items-center justify-between gap-3"><div><Link href="/operations" className="text-sm underline">{t('operations')}</Link><h1 className="mt-2 text-2xl font-semibold">{document ? 'Edit ' : 'Create '}{purchase ? t('purchases') : t('sales')}</h1></div><span className="text-sm">{document?.status ?? 'draft'}</span></div>
        {notice.message && <p role="alert" className={`mt-4 border-l-4 bg-white p-3 text-sm ${notice.type === 'error' ? 'border-red-700' : 'border-[#34795c]'}`}>{notice.message}</p>}
        {warning.length > 0 && <div role="status" className="mt-4 border-l-4 border-[#d7a94f] bg-white p-3 text-sm">Similar bills found: {warning.map((item) => item.internal_number).join(', ')}. Your form is preserved.</div>}
        <form onSubmit={submit} className="mt-6 grid gap-5">
            <section className="grid gap-3 border-t border-[#d7ddd5] pt-5 sm:grid-cols-3">
                <select required value={form.branch_id} onChange={(event) => set('branch_id', event.target.value)} className="border p-2"><option value="">Branch</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select>
                <select required value={form.warehouse_id} onChange={(event) => set('warehouse_id', event.target.value)} className="border p-2"><option value="">Warehouse</option>{warehouses.filter((warehouse) => !form.branch_id || String(warehouse.branch_id) === String(form.branch_id)).map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}</select>
                <select required value={purchase ? form.supplier_id : form.customer_id} onChange={(event) => set(purchase ? 'supplier_id' : 'customer_id', event.target.value)} className="border p-2"><option value="">{purchase ? 'Supplier' : 'Customer'}</option>{parties.map((party) => <option key={party.id} value={party.id}>{party.name}</option>)}</select>
                <input required placeholder={purchase ? 'Supplier bill number' : 'Customer bill number'} value={purchase ? form.supplier_bill_number : form.customer_bill_number} onChange={(event) => set(purchase ? 'supplier_bill_number' : 'customer_bill_number', event.target.value)} className="border p-2" />
                <input required type="date" value={purchase ? form.supplier_bill_date : form.bill_date} onChange={(event) => set(purchase ? 'supplier_bill_date' : 'bill_date', event.target.value)} className="border p-2" />
                {purchase && <input type="date" value={form.warehouse_entry_date ?? ''} onChange={(event) => set('warehouse_entry_date', event.target.value)} className="border p-2" />}
                {!purchase && <select required value={form.payment_method} onChange={(event) => set('payment_method', event.target.value)} className="border p-2"><option value="cash">Cash</option><option value="credit">Credit</option><option value="partial">Partial</option></select>}
                {!purchase && form.payment_method === 'credit' && <input required type="date" value={form.due_date ?? ''} onChange={(event) => set('due_date', event.target.value)} className="border p-2" />}
                <select value={form.vehicle_id ?? ''} onChange={(event) => set('vehicle_id', event.target.value)} className="border p-2"><option value="">External vehicle</option>{vehicles.map((vehicle) => <option key={vehicle.id} value={vehicle.id}>{vehicle.plate}</option>)}</select>
                {!form.vehicle_id && <><input placeholder="External plate" value={form.external_vehicle_plate ?? ''} onChange={(event) => set('external_vehicle_plate', event.target.value)} className="border p-2" /><input placeholder="Driver name" value={form.external_driver_name ?? ''} onChange={(event) => set('external_driver_name', event.target.value)} className="border p-2" /></>}
            </section>
            <section className="border-t border-[#d7ddd5] pt-5"><div className="flex items-center justify-between"><h2 className="font-semibold">Lines</h2><button type="button" onClick={addLine} className="border px-3 py-2">+ line</button></div>{form.lines.map((line, index) => <div key={index} className="mt-3 grid gap-2 border border-[#d7ddd5] bg-white p-3 sm:grid-cols-6"><select required value={line.product_type_id ?? ''} onChange={(event) => setLine(index, 'product_type_id', event.target.value)} className="border p-2"><option value="">Type / brand</option>{productTypes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select><select required value={line.diameter_id ?? ''} onChange={(event) => setLine(index, 'diameter_id', event.target.value)} className="border p-2"><option value="">Diameter</option>{diameters.map((item) => <option key={item.id} value={item.id}>{item.millimeters} mm</option>)}</select>{purchase ? <><input required type="number" min="0" step="0.001" placeholder="Factory weight" value={line.factory_weight} onChange={(event) => setLine(index, 'factory_weight', event.target.value)} className="border p-2" /><input required type="number" min="0" step="0.001" placeholder="Packages" value={line.packages} onChange={(event) => setLine(index, 'packages', event.target.value)} className="border p-2" /></> : <><input required type="number" min="0" step="0.001" placeholder="Actual weight" value={line.actual_weight} onChange={(event) => setLine(index, 'actual_weight', event.target.value)} className="border p-2" /><input type="number" min="0" step="0.001" placeholder="Packages (optional)" value={line.packages ?? ''} onChange={(event) => setLine(index, 'packages', event.target.value)} className="border p-2" /></>}<input required type="number" min="0" step="0.01" placeholder="Unit price" value={line.unit_price} onChange={(event) => setLine(index, 'unit_price', event.target.value)} className="border p-2" />{form.lines.length > 1 && <button type="button" onClick={() => removeLine(index)} className="text-left text-red-700 underline">Remove</button>}</div>)}</section>
            <section className="grid gap-3 border-t border-[#d7ddd5] pt-5 sm:grid-cols-4">{purchase && <input required type="number" min="0" step="0.001" placeholder="Total factory weight" value={form.total_factory_weight ?? ''} onChange={(event) => set('total_factory_weight', event.target.value)} className="border p-2" />}<input required type="number" min="0" step="0.001" placeholder="Total actual weight" value={form.total_actual_weight ?? ''} onChange={(event) => set('total_actual_weight', event.target.value)} className="border p-2" />{purchase && <input required type="number" min="0" step="0.001" placeholder="Total packages" value={form.total_packages ?? ''} onChange={(event) => set('total_packages', event.target.value)} className="border p-2" />}<input type="number" min="0" step="0.01" placeholder="Transport" value={form.transport_cost ?? 0} onChange={(event) => set('transport_cost', event.target.value)} className="border p-2" /><input type="number" min="0" step="0.01" placeholder="Loading" value={form.loading_cost ?? 0} onChange={(event) => set('loading_cost', event.target.value)} className="border p-2" /><input type="number" min="0" step="0.01" placeholder="Extras" value={form.extra_cost ?? 0} onChange={(event) => set('extra_cost', event.target.value)} className="border p-2" />{!purchase && <input type="number" min="0" step="0.01" placeholder="Discount" value={form.discount ?? 0} onChange={(event) => set('discount', event.target.value)} className="border p-2" />}<input type="number" min="0" step="0.01" placeholder="VAT %" value={form.vat_rate ?? ''} onChange={(event) => set('vat_rate', event.target.value)} className="border p-2" /></section>
            {document?.status === 'approved' && <><input required placeholder="Revision reason" value={revisionReason} onChange={(event) => setRevisionReason(event.target.value)} className="border p-2" />{capabilities[purchase ? 'purchases.update_approved' : 'sales.update_approved'] && <button disabled={processing} className="border border-[#34795c] px-4 py-2">Save revision</button>}{capabilities[purchase ? 'purchases.reverse' : 'sales.reverse'] && <button type="button" disabled={processing} onClick={reverse} className="bg-[#a64b36] px-4 py-2 text-white">Reverse</button>}{id && <button type="button" disabled={processing} onClick={loadAllocation} className="border px-4 py-2">Allocate extras by ton</button>}</>}
            {document?.status !== 'approved' && <div className="flex flex-wrap gap-3"><button disabled={processing} className="border border-[#34795c] px-4 py-2">Save as draft</button>{capabilities[purchase ? 'purchases.approve' : 'sales.approve'] && <button disabled={processing} type="button" onClick={(event) => submit(event, true)} className="bg-[#34795c] px-4 py-2 text-white">Save &amp; Approve</button>}{id && capabilities[purchase ? 'purchases.approve' : 'sales.approve'] && <button disabled={processing} type="button" onClick={approveDraft} className="bg-[#a64b36] px-4 py-2 text-white">Approve</button>}</div>}
        </form>
        {allocation && <section className="mt-6 border-t border-[#d7ddd5] pt-4"><h2 className="font-semibold">Display-only allocation by ton</h2>{allocation.lines.map((line) => <p key={line.line_id} className="mt-2 text-sm">Line {line.line_id}: {line.allocated_extra_cost} EGP</p>)}</section>}
    </CompanyLayout>;
}
