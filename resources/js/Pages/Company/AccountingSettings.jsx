import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';

export default function AccountingSettings({ policy }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const [form, setForm] = useState(policy);
    const [notice, setNotice] = useState({ type: '', message: '' });
    const [processing, setProcessing] = useState(false);

    async function submit(event) {
        event.preventDefault();
        setProcessing(true);
        setNotice({ type: '', message: '' });
        try {
            const response = await fetch('/api/v1/accounting-settings', {
                method: 'PUT', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                body: JSON.stringify(form),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.message || Object.values(payload.errors ?? {}).flat().join(' ') || t('requestFailed'));
            setForm(payload.data);
            setNotice({ type: 'success', message: locale === 'ar' ? 'تم حفظ السياسة المحاسبية.' : 'Accounting policy saved.' });
        } catch (error) {
            setNotice({ type: 'error', message: error.message });
        } finally {
            setProcessing(false);
        }
    }

    return <CompanyLayout title={locale === 'ar' ? 'السياسات المحاسبية' : 'Accounting policies'}>
        <div className="page-header"><div><p className="eyebrow">{locale === 'ar' ? 'إعدادات المالك' : 'Owner settings'}</p><h1>{locale === 'ar' ? 'سياسة الوزن والضريبة' : 'Weight and VAT policy'}</h1><p>{locale === 'ar' ? 'اختر القاعدة التي يطبقها الخادم على كل فاتورة شراء.' : 'Choose the server-enforced policy for every purchase bill.'}</p></div></div>
        <form onSubmit={submit} className="panel policy-form">
            {notice.message && <div role="status" aria-live="polite" className={`notice-card ${notice.type}`}>{notice.message}</div>}
            <fieldset disabled={policy.weight_policy_locked} className="policy-options">
                <legend>{locale === 'ar' ? 'أساس كمية المخزون' : 'Inventory quantity basis'}</legend>
                <label className={`policy-option ${form.purchase_inventory_basis === 'actual_weight' ? 'selected' : ''}`}><input type="radio" name="basis" value="actual_weight" checked={form.purchase_inventory_basis === 'actual_weight'} onChange={(e) => setForm({ ...form, purchase_inventory_basis: e.target.value })} /><span><strong>{locale === 'ar' ? 'الوزن الفعلي لكل سطر — موصى به' : 'Actual weight per line — recommended'}</strong><small>{locale === 'ar' ? 'القيمة بوزن المصنع، وكمية المخزون بما استلمه المخزن فعليًا.' : 'Value uses factory weight; inventory uses actual received weight.'}</small></span></label>
                <label className={`policy-option ${form.purchase_inventory_basis === 'factory_weight' ? 'selected' : ''}`}><input type="radio" name="basis" value="factory_weight" checked={form.purchase_inventory_basis === 'factory_weight'} onChange={(e) => setForm({ ...form, purchase_inventory_basis: e.target.value })} /><span><strong>{locale === 'ar' ? 'وزن المصنع' : 'Factory weight'}</strong><small>{locale === 'ar' ? 'القيمة وكمية المخزون بوزن المصنع؛ الوزن الفعلي إجمالي للمراجعة.' : 'Both value and inventory use factory weight; actual total is review-only.'}</small></span></label>
            </fieldset>
            {policy.weight_policy_locked && <p className="policy-lock" role="note">{locale === 'ar' ? 'تم قفل سياسة الوزن بعد أول فاتورة شراء معتمدة لحماية اتساق الأرصدة.' : 'The weight policy is locked after the first approved purchase to protect balance integrity.'}</p>}
            <div className="section-divider"><label className="toggle-field"><input type="checkbox" checked={Boolean(form.vat_enabled)} onChange={(e) => setForm({ ...form, vat_enabled: e.target.checked })} /><span>{locale === 'ar' ? 'تفعيل ضريبة القيمة المضافة' : 'Enable VAT'}</span></label>{form.vat_enabled && <label className="field"><span>{locale === 'ar' ? 'نسبة الضريبة %' : 'VAT rate %'}</span><input type="number" min="0" max="100" step="0.001" value={form.vat_rate} onChange={(e) => setForm({ ...form, vat_rate: e.target.value })} /></label>}</div>
            <button className="primary-button" disabled={processing}>{processing ? (locale === 'ar' ? 'جارٍ الحفظ…' : 'Saving…') : (locale === 'ar' ? 'حفظ السياسة' : 'Save policy')}</button>
        </form>
    </CompanyLayout>;
}
