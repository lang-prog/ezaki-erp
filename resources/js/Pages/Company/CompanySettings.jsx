import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';

export default function CompanySettings({ company }) {
    const { locale, errors, flash } = usePage().props;
    const t = (key) => translate(locale, key);
    const form = useForm({ _method: 'put', name: company.name ?? '', trade_name: company.trade_name ?? '', tax_number: company.tax_number ?? '', phone: company.phone ?? '', country: company.country ?? '', city: company.city ?? '', address: company.address ?? '', notes: company.notes ?? '', print_background: null, remove_print_background: false });
    const [fileKey, setFileKey] = useState(0);
    const [requestError, setRequestError] = useState('');
    const update = (key, value) => form.setData(key, value);
    const submit = (event) => {
        event.preventDefault();
        setRequestError('');
        form.post('/settings/company', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.setData((data) => ({ ...data, print_background: null, remove_print_background: false }));
                setFileKey((key) => key + 1);
            },
            onError: (errors) => { if (!Object.keys(errors).length) setRequestError(t('requestFailed')); },
        });
    };

    return <CompanyLayout title={t('companySettings')}>
        <div className="erp-page-header"><div><p className="eyebrow">{t('navCompany')}</p><h1>{t('companySettings')}</h1><p>{t('companySettingsIntro')}</p></div><div className="owner-badge">{t('ownerOnly')}</div></div>
        <section className="erp-section company-settings-card"><div className="erp-section__header"><div><h2>{t('companyIdentity')}</h2><p>{t('companySettingsHint')}</p></div></div><form className="company-settings-form" onSubmit={submit}>
            {flash?.status && <div className="notice-card success" role="status">{t('companySaved')}</div>}
            {requestError && <div className="notice-card error" role="alert">{requestError}</div>}
            {errors && Object.keys(errors).length > 0 && <div className="notice-card error" role="alert">{Object.values(errors).flat().join(' ')}</div>}
            <div className="form-grid"><label className="field"><span>{t('companyName')}</span><input required value={form.data.name} onChange={(event) => update('name', event.target.value)} /></label><label className="field"><span>{t('tradeName')}</span><input value={form.data.trade_name} onChange={(event) => update('trade_name', event.target.value)} /></label><label className="field"><span>{t('taxNumber')}</span><input value={form.data.tax_number} onChange={(event) => update('tax_number', event.target.value)} /></label><label className="field"><span>{t('phone')}</span><input value={form.data.phone} onChange={(event) => update('phone', event.target.value)} /></label><label className="field"><span>{t('country')}</span><input value={form.data.country} onChange={(event) => update('country', event.target.value)} /></label><label className="field"><span>{t('city')}</span><input value={form.data.city} onChange={(event) => update('city', event.target.value)} /></label><label className="field full-field"><span>{t('address')}</span><input value={form.data.address} onChange={(event) => update('address', event.target.value)} /></label><label className="field full-field"><span>{t('notes')}</span><textarea rows="4" value={form.data.notes} onChange={(event) => update('notes', event.target.value)} /></label></div>
            <div className="print-branding-card"><div><h3>{t('printBackground')}</h3><p>{t('printBackgroundHint')}</p><small>{t('printBackgroundFormats')}</small></div>{company.print_background_url && <img src={company.print_background_url} alt={t('printBackgroundPreview')} className="print-background-preview" />}<div className="print-background-actions"><label className="field"><span>{t('uploadPrintBackground')}</span><input key={fileKey} type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => update('print_background', event.target.files?.[0] ?? null)} /></label>{company.print_background_url && <label className="checkbox-line"><input type="checkbox" checked={form.data.remove_print_background} onChange={(event) => update('remove_print_background', event.target.checked)} />{t('removePrintBackground')}</label>}<a href="/demo/backgrounda4.webp" target="_blank" rel="noreferrer" className="text-link">{t('downloadDemoBackground')}</a></div></div>
            <div className="form-actions"><button type="submit" className="primary-button" disabled={form.processing}>{form.processing ? t('saving') : t('saveChanges')}</button></div>
        </form></section>
    </CompanyLayout>;
}
