import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';

const value = (item, key) => item?.[key] ?? '—';
const money = (item) => `${Number(item ?? 0).toFixed(2)} EGP`;
const weight = (item) => Number(item ?? 0).toFixed(3);

export default function BillView({ type, document, revisions = [], capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const text = (ar, en) => locale === 'ar' ? ar : en;
    const purchase = type === 'purchase';
    const party = purchase ? document.supplier : document.customer;
    const editPermission = purchase
        ? (document.status === 'approved' ? 'purchases.update_approved' : 'purchases.update')
        : (document.status === 'approved' ? 'sales.update_approved' : 'sales.update');
    const date = (item) => item ? String(item).slice(0, 10) : '—';
    const status = ({ draft: t('draft'), approved: t('approved'), reversed: t('reversed') })[document.status] ?? document.status;
    const changedFields = (revision) => {
        const before = revision.before_data || {};
        const after = revision.after_data || {};
        return Object.keys({ ...before, ...after }).filter((key) => JSON.stringify(before[key]) !== JSON.stringify(after[key]));
    };

    return <CompanyLayout title={`${t('view')} · ${document.internal_number}`}>
        <div className="erp-page-header">
            <div><Link href="/operations" className="erp-eyebrow">← {t('operations')}</Link><h1>{document.internal_number}</h1><p>{purchase ? t('purchases') : t('sales')} · {text('عرض تفصيلي للمستند دون إمكانية تغيير بياناته.', 'Read-only document view with the complete operational and accounting details.')}</p></div>
            <span className={`status-pill ${document.status === 'approved' ? 'success' : 'muted-pill'}`}>{status}</span>
        </div>
        <div className="document-actions">
            {capabilities[editPermission] && <Link href={`/operations/${purchase ? 'purchase' : 'sales'}/${document.id}/edit`} className="erp-button erp-button--primary">{t('edit')}</Link>}
            {capabilities[purchase ? 'purchases.print' : 'sales.print'] && <><a className="erp-button erp-button--secondary" href={`/operations/${purchase ? 'purchase' : 'sales'}/${document.id}/print?mode=invoice`}>{t('printInvoice')}</a><a className="erp-button erp-button--secondary" href={`/operations/${purchase ? 'purchase' : 'sales'}/${document.id}/print?mode=delivery`}>{t('printOperational')}</a></>}
        </div>

        <section className="erp-section document-hero-grid">
            <div className="document-reference"><span>{text('رقم المستند الداخلي', 'Internal document number')}</span><strong>{document.internal_number}</strong><small>{text('الحالة الحالية', 'Current status')}: {status}</small></div>
            <div className="document-reference"><span>{purchase ? t('supplier') : t('customer')}</span><strong>{value(party, 'name')}</strong><small>{purchase ? value(document, 'supplier_bill_number') : value(document, 'customer_bill_number')}</small></div>
            <div className="document-reference"><span>{text('تاريخ المستند', 'Document date')}</span><strong>{date(purchase ? document.supplier_bill_date : document.bill_date)}</strong><small>{purchase ? `${t('warehouseEntryDate')}: ${date(document.warehouse_entry_date)}` : text('تاريخ فاتورة العميل', 'Customer bill date')}</small></div>
        </section>

        <section className="erp-section"><div className="erp-section__header"><div><h2>{text('البيانات التشغيلية', 'Operational details')}</h2><p>{text('بيانات الفرع والمخزن والنقل والجهات المرتبطة بالمستند.', 'Branch, warehouse, transport and related-party details.')}</p></div></div><div className="document-facts-grid">
            {[[text('الفرع', 'Branch'), value(document.branch, 'name')], [t('warehouse'), value(document.warehouse, 'name')], [text('المركبة', 'Vehicle'), document.vehicle?.plate || document.external_vehicle_plate || '—'], [text('السائق', 'Driver'), document.external_driver_name || '—'], [text('طريقة الدفع', 'Payment method'), value(document, 'payment_method')], [text('تاريخ الاستحقاق', 'Due date'), date(document.due_date)], [text('أنشأه', 'Created by'), value(document.created_by, 'name') || value(document.createdBy, 'name')], [text('اعتمده', 'Approved by'), value(document.approved_by, 'name') || value(document.approvedBy, 'name')]].map(([label, content]) => <div className="document-fact" key={label}><span>{label}</span><strong>{content}</strong></div>)}
        </div>{(document.notes || document.trip_origin || document.trip_destination) && <div className="document-notes"><strong>{t('notes')}</strong><p>{document.notes || '—'}</p>{(document.trip_origin || document.trip_destination) && <small>{document.trip_origin || '—'} → {document.trip_destination || '—'}</small>}</div>}</section>

        <section className="erp-section"><div className="erp-section__header"><div><h2>{t('lines')}</h2><p>{text('تفاصيل كل صنف كما تم إدخالها في البوليصة.', 'Every line exactly as entered on the document.')}</p></div></div><div className="erp-table-wrap"><table className="erp-table document-lines-table"><thead><tr><th>#</th><th>{t('productName')}</th><th>{t('type')}</th><th>{t('diameter')}</th>{purchase && <th>{t('factoryWeight')}</th>}<th>{t('actualWeight')}</th><th>{t('packages')}</th><th>{t('unitPrice')}</th><th>{t('total')}</th><th>{t('notes')}</th></tr></thead><tbody>{(document.lines || []).map((line, index) => <tr key={line.id ?? index}><td>{index + 1}</td><td>{line.product?.name || '—'}</td><td>{line.product?.type?.name || '—'}</td><td>{line.product?.diameter?.millimeters ? `${line.product.diameter.millimeters} mm` : '—'}</td>{purchase && <td>{weight(line.factory_weight)}</td>}<td>{weight(line.actual_weight)}</td><td>{weight(line.packages)}</td><td>{money(line.unit_price)}</td><td>{money(line.line_total)}</td><td>{line.notes || '—'}</td></tr>)}</tbody></table></div></section>

        <section className="erp-section document-totals"><div className="erp-section__header"><div><h2>{text('الملخص المالي والوزني', 'Financial and weight summary')}</h2></div></div><div className="document-summary-grid">{[[t('subtotal'), money(document.subtotal)], [t('transport'), money(document.transport_cost)], [t('loading'), money(document.loading_cost)], [t('extras'), money(document.extra_cost)], [t('discount'), money(document.discount)], [text('الضريبة', 'VAT'), `${money(document.vat_amount)} (${value(document, 'vat_rate')}%)`], [t('totalFactoryWeight'), weight(document.total_factory_weight)], [t('totalActualWeight'), weight(document.total_actual_weight)], [t('totalPackages'), weight(document.total_packages)], [t('paidAmount'), money(document.paid_amount)], [t('total'), money(document.total)]].map(([label, content]) => <div className={label === t('total') ? 'document-total-card is-total' : 'document-total-card'} key={label}><span>{label}</span><strong>{content}</strong></div>)}</div></section>

        <section className="erp-section"><div className="erp-section__header"><div><h2>{text('سجل التعديلات', 'Revision history')}</h2><p>{text('كل تعديل على مستند معتمد يجب أن يحمل سببًا واضحًا ويتم حفظه للمراجعة.', 'Every revision to an approved document is retained with its stated reason.')}</p></div></div>{revisions.length === 0 ? <div className="erp-empty"><h3>{text('لا توجد تعديلات مسجلة', 'No revisions recorded')}</h3><p>{text('هذا المستند لم يُعدّل بعد الاعتماد.', 'This document has not been revised after approval.')}</p></div> : <div className="erp-table-wrap"><table className="erp-table revision-table"><thead><tr><th>{text('التاريخ', 'Date')}</th><th>{text('بواسطة', 'By')}</th><th>{text('سبب التعديل', 'Reason')}</th><th>{text('الحقول المتغيرة', 'Changed fields')}</th></tr></thead><tbody>{revisions.map((revision) => <tr key={revision.id}><td>{date(revision.created_at)}</td><td>{revision.user?.name || revision.user_id || '—'}</td><td>{revision.reason || '—'}</td><td><div className="revision-chips">{changedFields(revision).slice(0, 8).map((field) => <span key={field}>{field}</span>)}</div></td></tr>)}</tbody></table></div>}</section>
    </CompanyLayout>;
}
