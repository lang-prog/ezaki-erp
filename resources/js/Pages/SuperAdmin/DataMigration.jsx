import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import SuperAdminLayout from '../../Layouts/SuperAdminLayout';
import { PageHeader, Pagination, StatusBadge, usePt } from '../../Components/Platform/ui';

const TONE = { failed_terminal: 'failed', failed_recoverable: 'warning', completed: 'approved', signed_off: 'approved', archived: 'archived', completed_with_review: 'warning' };

function nextDayOf(value) {
    const base = new Date(`${value}T00:00:00Z`);
    if (Number.isNaN(base.getTime())) return '';
    base.setUTCDate(base.getUTCDate() + 1);

    return base.toISOString().slice(0, 10);
}

export default function DataMigration({ companies = [], runs = { data: [], links: [] }, limits = {} }) {
    const { t, can, date, dateTime } = usePt();
    const manage = can('data_migration.manage');
    const form = useForm({ company_id: '', documents_from: '2026-10-01', documents_to: '2026-10-18', opening_balance_as_of: '2026-09-30', gap_reason: '', customer_start_balance_sign: '', supplier_start_balance_sign: '', source: null });
    const [submitted, setSubmitted] = useState(false);
    const data = form.data;
    const gap = Boolean(data.opening_balance_as_of && data.documents_from && data.documents_from !== nextDayOf(data.opening_balance_as_of));
    const errors = form.errors || {};
    const field = (name) => errors[name] && <span className="field-error" role="alert">{errors[name]}</span>;
    const set = (name) => (event) => form.setData(name, event.target.value);

    function submit(event) {
        event.preventDefault();
        if (form.processing || submitted) return;
        setSubmitted(true);
        form.post('/super-admin/data-migrations', { forceFormData: true, preserveScroll: true, onFinish: () => setSubmitted(false) });
    }

    return <SuperAdminLayout title={t('mg_title')}>
        <PageHeader title={t('mg_title')} intro={t('mg_intro')} />
        {manage && <form className="erp-section posting-form" onSubmit={submit} aria-labelledby="mg-upload-title">
            <h2 id="mg-upload-title">{t('mg_upload_title')}</h2>
            <div className="posting-form__grid">
                <label className="field"><span>{t('mg_company')}</span><select required name="company_id" value={data.company_id} onChange={set('company_id')}><option value="">—</option>{companies.map((company) => <option key={company.id} value={company.id}>{company.name} · #{company.id}</option>)}</select>{field('company_id')}</label>
                <label className="field"><span>{t('mg_cutoff')}</span><input required type="date" name="opening_balance_as_of" value={data.opening_balance_as_of} onChange={set('opening_balance_as_of')} />{field('opening_balance_as_of')}</label>
                <label className="field"><span>{t('mg_from')}</span><input required type="date" name="documents_from" value={data.documents_from} onChange={set('documents_from')} />{field('documents_from')}</label>
                <label className="field"><span>{t('mg_to')}</span><input required type="date" name="documents_to" value={data.documents_to} onChange={set('documents_to')} />{field('documents_to')}</label>
                {gap && <label className="field field--wide"><span>{t('mg_gap_reason')}</span><input required minLength={5} maxLength={500} name="gap_reason" value={data.gap_reason} onChange={set('gap_reason')} /><small>{t('mg_gap_hint')}</small>{field('gap_reason')}</label>}
                <label className="field"><span>{t('mg_cust_sign')}</span><select name="customer_start_balance_sign" value={data.customer_start_balance_sign} onChange={set('customer_start_balance_sign')}><option value="">{t('mg_sign_choose')}</option><option value="debit_positive">{t('mg_sign_debit_positive')}</option><option value="debit_negative">{t('mg_sign_debit_negative')}</option></select>{field('customer_start_balance_sign')}</label>
                <label className="field"><span>{t('mg_supp_sign')}</span><select name="supplier_start_balance_sign" value={data.supplier_start_balance_sign} onChange={set('supplier_start_balance_sign')}><option value="">{t('mg_sign_choose')}</option><option value="credit_positive">{t('mg_sign_credit_positive')}</option><option value="credit_negative">{t('mg_sign_credit_negative')}</option></select>{field('supplier_start_balance_sign')}</label>
                <label className="field field--wide"><span>{t('mg_source_file')}</span><input required type="file" name="source" accept=".sql,.gz" onChange={(event) => form.setData('source', event.target.files?.[0] || null)} /><small>{t('mg_source_hint').replace('{mb}', String(Math.round((limits.max_upload_kb || 0) / 1024))).replace('{days}', String(limits.retention_days ?? ''))}</small>{field('source')}</label>
            </div>
            <p className="permission-note">{t('mg_sign_hint')}</p>
            <button type="submit" disabled={form.processing || submitted} className="erp-button erp-button--primary">{form.processing ? t('mg_working') : t('mg_upload_submit')}</button>
        </form>}
        <section className="erp-section" aria-label={t('mg_runs')}>
            <h2>{t('mg_runs')}</h2>
            <div className="erp-table-wrap"><table className="erp-table">
                <thead><tr><th>{t('mg_company')}</th><th>{t('mg_source_file')}</th><th>{t('mg_period')}</th><th>{t('status')}</th><th>{t('mg_created_by')}</th><th className="no-print">{t('actions')}</th></tr></thead>
                <tbody>
                    {runs.data.length === 0 && <tr><td colSpan={6} className="empty-cell">{t('mg_runs_empty')}</td></tr>}
                    {runs.data.map((run) => <tr key={run.id}>
                        <td>{run.company?.name} · #{run.company?.id}</td>
                        <td>{run.source_name}<small className="table-subline">{dateTime(run.created_at)}</small></td>
                        <td>{date(run.documents_from)} → {date(run.documents_to)}</td>
                        <td><StatusBadge status={TONE[run.status] ?? 'info'} label={t(`mg_status_${run.status}`)} /></td>
                        <td>{run.created_by?.name ?? '—'}</td>
                        <td className="no-print"><a className="table-action" href={`/super-admin/data-migrations/${run.id}`}>{t('mg_open')}</a></td>
                    </tr>)}
                </tbody>
            </table></div>
            <Pagination links={runs.links} />
        </section>
    </SuperAdminLayout>;
}
