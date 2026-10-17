import { useState } from 'react';
import { router } from '@inertiajs/react';
import SuperAdminLayout from '../../Layouts/SuperAdminLayout';
import { ConfirmActionDialog, EmptyRow, Notice, PageHeader, StatusBadge, failureMessage, usePlatformApi, usePt } from '../../Components/Platform/ui';
import { durationLabel, durationSummary } from '../../i18n-durations';

const TONE = { trial: 'pending', test: 'grace', paid: 'info' };
const EMPTY = { name_ar: '', name_en: '', description_ar: '', description_en: '', category: 'paid', unit: 'month', value: '1', sort_order: '100', key: '' };

const unitsFor = (meta, category) => (category === 'paid' ? meta.units : meta.units.filter((unit) => meta.non_paid_units.includes(unit)));

function Preview({ draft, t, locale }) {
    const summary = durationSummary(locale, { unit: draft.unit, value: Number(draft.value) });
    const lifetime = draft.unit === 'lifetime';

    return <div className="impact-box" aria-live="polite">
        <strong>{t('dur_preview')}</strong>
        <p>{summary} · {t(`dur_category_${draft.category}`)}</p>
        <p>{lifetime ? t('dur_no_expiry') : t('dur_expiry_rule').replace('{summary}', summary)}</p>
        <p className="permission-note">{t(lifetime ? 'dur_effect_lifetime' : `dur_effect_${draft.category}`)}</p>
    </div>;
}

function Fields({ draft, setDraft, meta, t, locale, locked = null, creating = false }) {
    const set = (key) => (event) => setDraft({ ...draft, [key]: event.target.value });
    const units = unitsFor(meta, draft.category);
    const changeCategory = (event) => {
        const category = event.target.value;
        setDraft({ ...draft, category, unit: unitsFor(meta, category).includes(draft.unit) ? draft.unit : 'day' });
    };
    const frozen = locked !== null;

    return <>
        <div className="posting-form__grid">
            <label className="field"><span>{t('dur_name_ar')}</span><input required maxLength={120} dir="rtl" lang="ar" value={draft.name_ar} onChange={set('name_ar')} /></label>
            <label className="field"><span>{t('dur_name_en')}</span><input required maxLength={120} dir="ltr" lang="en" value={draft.name_en} onChange={set('name_en')} /></label>
            <label className="field"><span>{t('dur_category')}</span><select name="category" value={draft.category} disabled={frozen} onChange={changeCategory}>{meta.categories.map((category) => <option key={category} value={category}>{t(`dur_category_${category}`)}</option>)}</select></label>
            <label className="field"><span>{t('dur_unit')}</span><select name="unit" value={draft.unit} disabled={frozen} onChange={set('unit')}>{units.map((unit) => <option key={unit} value={unit}>{t(`dur_unit_${unit}`)}</option>)}</select></label>
            {draft.unit !== 'lifetime' && <label className="field"><span>{t('dur_value')}</span><input name="value" type="number" required min="1" max={meta.max_value[draft.unit]} step="1" disabled={frozen} value={draft.value} onChange={set('value')} /></label>}
            <label className="field"><span>{t('dur_order')}</span><input type="number" min="0" max="100000" step="1" value={draft.sort_order} onChange={set('sort_order')} /></label>
            {creating && <label className="field"><span>{t('dur_key')}</span><input maxLength={40} pattern="[a-z][a-z0-9_]*" dir="ltr" value={draft.key} onChange={set('key')} /><small>{t('dur_key_hint')}</small></label>}
            <label className="field field--wide"><span>{t('dur_desc_ar')}</span><input maxLength={500} dir="rtl" lang="ar" value={draft.description_ar} onChange={set('description_ar')} /></label>
            <label className="field field--wide"><span>{t('dur_desc_en')}</span><input maxLength={500} dir="ltr" lang="en" value={draft.description_en} onChange={set('description_en')} /></label>
        </div>
        {frozen && <p className="permission-note" role="note">{t(locked === 'system' ? 'dur_immutable_system' : 'dur_immutable_referenced')}</p>}
        {!frozen && !creating && <p className="permission-note" role="note">{t('dur_editable_note')}</p>}
        {creating && <p className="permission-note">{t('dur_same_interval')}</p>}
        <Preview draft={draft} t={t} locale={locale} />
    </>;
}

function payloadOf(draft, locked) {
    const body = { name_ar: draft.name_ar, name_en: draft.name_en, description_ar: draft.description_ar || null, description_en: draft.description_en || null, sort_order: Number(draft.sort_order || 0) };
    if (locked === null) Object.assign(body, { category: draft.category, unit: draft.unit, value: draft.unit === 'lifetime' ? null : Number(draft.value) });

    return body;
}

function DeactivateDialog({ row, onClose, api, t }) {
    const [confirmed, setConfirmed] = useState(false);
    const affected = row.active_plans_count > 0;

    return <ConfirmActionDialog title={`${t('dur_deactivate')} — ${row.name_en}`} danger confirmLabel={t('dur_deactivate')} disabled={affected && !confirmed} onClose={onClose}
        impact={<><p>{t('dur_impact_deactivate')}</p>{affected && <><strong>{t('dur_affected_plans')} ({row.active_plans_count})</strong><ul>{row.active_plans.map((plan) => <li key={plan.id}>{plan.name}</li>)}</ul></>}</>}
        onConfirm={({ reason }) => api.act('post', `/super-admin/durations/${row.id}/deactivate`, { reason, confirm_affected: confirmed })}>
        {affected && <label className="field"><span><input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} /> {t('dur_confirm_affected')}</span></label>}
    </ConfirmActionDialog>;
}

export default function Durations({ durations = [], filters = {}, meta }) {
    const { t, can, locale } = usePt();
    const api = usePlatformApi();
    const [values, setValues] = useState({ q: filters.q ?? '', category: filters.category ?? '', status: filters.status ?? '' });
    const [draft, setDraft] = useState(EMPTY);
    const [message, setMessage] = useState({ type: '', text: '' });
    const [dialog, setDialog] = useState(null);
    const manage = can('plans.manage');
    const apply = (event) => { event.preventDefault(); router.get('/super-admin/durations', Object.fromEntries(Object.entries(values).filter(([, v]) => v)), { preserveState: true }); };

    async function create(event) {
        event.preventDefault();
        setMessage({ type: '', text: '' });
        try {
            await api.call('post', '/super-admin/durations', { ...payloadOf(draft, null), ...(draft.key ? { key: draft.key } : {}) });
            setDraft(EMPTY);
            setMessage({ type: 'success', text: t('dur_created') });
            router.reload({ preserveScroll: true });
        } catch (error) {
            if (error?.message !== 'cancelled') setMessage({ type: 'error', text: failureMessage(error, t) });
        }
    }

    return <SuperAdminLayout title={t('dur_nav')}>
        <PageHeader title={t('dur_title')} intro={t('dur_intro')} />
        {message.text && <Notice type={message.type}>{message.text}</Notice>}
        <section className="erp-section" aria-label={t('dur_title')}>
            <form className="report-filter-bar no-print" onSubmit={apply}>
                <label className="field"><span>{t('dur_search')}</span><input type="search" value={values.q} onChange={(event) => setValues({ ...values, q: event.target.value })} /></label>
                <label className="field"><span>{t('dur_category')}</span><select value={values.category} onChange={(event) => setValues({ ...values, category: event.target.value })}><option value="">{t('dur_all_categories')}</option>{meta.categories.map((category) => <option key={category} value={category}>{t(`dur_category_${category}`)}</option>)}</select></label>
                <label className="field"><span>{t('status')}</span><select value={values.status} onChange={(event) => setValues({ ...values, status: event.target.value })}><option value="">{t('dur_all_statuses')}</option><option value="active">{t('dur_status_active')}</option><option value="inactive">{t('dur_status_inactive')}</option></select></label>
                <div className="erp-toolbar"><button type="submit" className="erp-button erp-button--primary">{t('apply')}</button></div>
            </form>
            <div className="erp-table-wrap"><table className="erp-table">
                <thead><tr><th>{t('pf_name')}</th><th>{t('dur_category')}</th><th>{t('dur_interval')}</th><th>{t('status')}</th><th>{t('dur_kind')}</th><th>{t('dur_order')}</th><th>{t('dur_plans')}</th><th>{t('dur_subscriptions')}</th><th className="no-print">{t('actions')}</th></tr></thead>
                <tbody>
                    {durations.map((row) => <tr key={row.id}>
                        <td><strong>{durationLabel(locale, row)}</strong><small className="table-subline">{locale === 'ar' ? row.name_en : row.name_ar} · <code>{row.key}</code></small></td>
                        <td><StatusBadge status={TONE[row.category] ?? 'info'} label={t(`dur_category_${row.category}`)} /></td>
                        <td>{durationSummary(locale, row)}</td>
                        <td><StatusBadge status={row.is_active ? 'active' : 'disabled'} label={t(row.is_active ? 'dur_status_active' : 'dur_status_inactive')} /></td>
                        <td>{t(row.is_system ? 'dur_system' : 'dur_custom')}</td>
                        <td>{row.sort_order}</td>
                        <td>{row.active_plans_count} / {row.plans_count}</td>
                        <td>{row.subscriptions_count}</td>
                        <td className="no-print">{manage && <div className="row-actions">
                            <button type="button" className="table-action" onClick={() => setDialog({ kind: 'edit', row })}>{t('dur_edit')}</button>
                            <button type="button" className={`table-action ${row.is_active ? 'table-action--danger' : ''}`} onClick={() => setDialog({ kind: row.is_active ? 'deactivate' : 'activate', row })}>{t(row.is_active ? 'dur_deactivate' : 'dur_activate')}</button>
                        </div>}</td>
                    </tr>)}
                    {durations.length === 0 && <tr><td colSpan={9} className="empty-cell">{t('dur_empty')}</td></tr>}
                </tbody>
            </table></div>
        </section>
        {manage && <form className="erp-section posting-form" onSubmit={create} aria-labelledby="create-duration-title">
            <h2 id="create-duration-title">{t('dur_create')}</h2>
            <Fields draft={draft} setDraft={setDraft} meta={meta} t={t} locale={locale} creating />
            <button type="submit" className="erp-button erp-button--primary">{t('dur_create_submit')}</button>
        </form>}
        {dialog?.kind === 'edit' && <EditDialog row={dialog.row} meta={meta} t={t} locale={locale} api={api} onClose={() => setDialog(null)} />}
        {dialog?.kind === 'deactivate' && <DeactivateDialog row={dialog.row} api={api} t={t} onClose={() => setDialog(null)} />}
        {dialog?.kind === 'activate' && <ConfirmActionDialog title={`${t('dur_activate')} — ${dialog.row.name_en}`} confirmLabel={t('dur_activate')} impact={<p>{t('dur_impact_activate')}</p>} onClose={() => setDialog(null)} onConfirm={({ reason }) => api.act('post', `/super-admin/durations/${dialog.row.id}/activate`, { reason })} />}
        {api.stepUpDialog}
    </SuperAdminLayout>;
}

function EditDialog({ row, meta, t, locale, api, onClose }) {
    const [draft, setDraft] = useState({ name_ar: row.name_ar, name_en: row.name_en, description_ar: row.description_ar ?? '', description_en: row.description_en ?? '', category: row.category, unit: row.unit, value: String(row.value ?? ''), sort_order: String(row.sort_order), key: row.key });

    return <ConfirmActionDialog title={`${t('dur_edit')} — ${durationLabel(locale, row)}`} confirmLabel={t('dur_save')} onClose={onClose}
        onConfirm={({ reason }) => api.act('put', `/super-admin/durations/${row.id}`, { ...payloadOf(draft, row.immutable), version: row.version, reason })}>
        <Fields draft={draft} setDraft={setDraft} meta={meta} t={t} locale={locale} locked={row.immutable} />
    </ConfirmActionDialog>;
}
