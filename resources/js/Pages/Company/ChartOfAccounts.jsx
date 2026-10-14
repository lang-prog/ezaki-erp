import { useMemo, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import ModalShell from '../../Components/ModalShell';
import { translate } from '../../i18n';
import { useCoreApi } from '../../useCoreApi';
import { accountLabel, accountName } from '../../format';

const FILTERS = ['all', 'system', 'custom', 'inactive'];

function descendantIds(accounts, id) {
    const found = new Set();
    const walk = (parentId) => accounts.filter((account) => Number(account.parent_id) === Number(parentId)).forEach((child) => { found.add(Number(child.id)); walk(child.id); });
    walk(id);

    return found;
}

export default function ChartOfAccounts({ accounts = [], capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const { notice, processing, send } = useCoreApi();
    const canCreate = Boolean(capabilities['accounting.create']);
    const canReport = Boolean(capabilities['reports.view']);
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState('all');
    const [expanded, setExpanded] = useState(() => new Set(accounts.filter((account) => !account.parent_id).map((account) => Number(account.id))));
    const [editing, setEditing] = useState(null);
    const [child, setChild] = useState({ parent_id: '', name_ar: '', name_en: '' });

    const byParent = useMemo(() => {
        const map = new Map();
        accounts.forEach((account) => {
            const key = account.parent_id ? Number(account.parent_id) : 0;
            map.set(key, [...(map.get(key) ?? []), account]);
        });

        return map;
    }, [accounts]);

    const matches = (account) => {
        const term = query.trim().toLowerCase();
        const textOk = !term || [account.code, account.name, account.name_ar, account.name_en].some((value) => String(value ?? '').toLowerCase().includes(term));
        const filterOk = filter === 'all' || (filter === 'system' && account.is_system) || (filter === 'custom' && !account.is_system) || (filter === 'inactive' && account.is_active === false);

        return textOk && filterOk;
    };

    // A node is shown when it matches or any descendant matches, so the path to a hit stays visible.
    const visibleIds = useMemo(() => {
        const visible = new Set();
        const visit = (account) => {
            const kids = byParent.get(Number(account.id)) ?? [];
            const childHit = kids.map(visit).some(Boolean);
            const hit = matches(account) || childHit;
            if (hit) visible.add(Number(account.id));

            return hit;
        };
        (byParent.get(0) ?? []).forEach(visit);
        // Orphans whose parent is not in the list are treated as roots.
        accounts.filter((account) => account.parent_id && !accounts.some((parent) => Number(parent.id) === Number(account.parent_id))).forEach(visit);

        return visible;
    }, [accounts, byParent, query, filter]);

    const searching = query.trim() !== '' || filter !== 'all';
    const toggle = (id) => setExpanded((current) => { const next = new Set(current); if (next.has(id)) next.delete(id); else next.add(id); return next; });
    const expandAll = () => setExpanded(new Set(accounts.map((account) => Number(account.id))));
    const collapseAll = () => setExpanded(new Set());
    const typeLabel = (type) => t(`accountType_${type}`);

    const renderNode = (account, level = 0) => {
        if (!visibleIds.has(Number(account.id))) return null;
        const kids = (byParent.get(Number(account.id)) ?? []).filter((item) => visibleIds.has(Number(item.id)));
        const open = searching || expanded.has(Number(account.id));
        const other = locale === 'ar' ? account.name_en : account.name_ar;
        const description = account.description ? (locale === 'ar' ? account.description.desc_ar : account.description.desc_en) : null;

        return <div key={account.id} className={`account-tree-node ${account.is_system ? 'account-tree-row--system' : ''}`}>
            <div className="account-tree-row" style={{ '--account-level': level }}>
                <div className="account-tree-main">
                    {kids.length > 0 ? <button type="button" className="account-tree-toggle" onClick={() => toggle(Number(account.id))} aria-expanded={open} aria-label={`${open ? t('collapse') : t('expand')} ${accountName(account, locale)}`}>{open ? '▾' : '▸'}</button> : <span className="account-tree-toggle account-tree-toggle--empty" aria-hidden="true" />}
                    <span className="account-tree-code">{account.code}</span>
                    <span className="account-tree-name">
                        <strong>{accountName(account, locale)}</strong>
                        {other && other !== accountName(account, locale) && <small lang={locale === 'ar' ? 'en' : 'ar'}>{other}</small>}
                        {description && <small className="account-tree-description">{description}</small>}
                    </span>
                </div>
                <div className="account-tree-badges">
                    <span className="account-tree-type">{typeLabel(account.account_type)}</span>
                    {account.is_system ? <span className="status-pill muted-pill" title={t('protectedHint')}>{t('systemAccount')}</span> : <span className="status-pill success">{t('customAccount')}</span>}
                    {account.is_active === false && <span className="status-pill muted-pill">{t('inactive')}</span>}
                </div>
                <div className="account-tree-actions">
                    {canReport && <Link href={`/reports/account-statement?account_id=${account.id}`} className="erp-link">{t('accountStatement')}</Link>}
                    {canCreate && <button type="button" className="erp-link-button" onClick={() => setEditing({ id: account.id, code: account.code, is_system: account.is_system, name_ar: account.name_ar ?? '', name_en: account.name_en ?? '', parent_id: account.parent_id ?? '' })}>{t('edit')}</button>}
                </div>
            </div>
            {open && kids.map((item) => renderNode(item, level + 1))}
        </div>;
    };

    const roots = [...(byParent.get(0) ?? []), ...accounts.filter((account) => account.parent_id && !accounts.some((parent) => Number(parent.id) === Number(account.parent_id)))];
    const shown = visibleIds.size;
    const blockedParents = editing ? new Set([Number(editing.id), ...descendantIds(accounts, editing.id)]) : new Set();

    const saveAccount = async (event) => {
        event.preventDefault();
        const payload = { name_ar: editing.name_ar, name_en: editing.name_en };
        if (!editing.is_system) payload.parent_id = editing.parent_id === '' ? null : Number(editing.parent_id);
        const result = await send(`accounts/${editing.id}`, 'PUT', payload);
        if (result) setEditing(null);
    };
    const createAccount = async (event) => {
        event.preventDefault();
        const result = await send('accounts', 'POST', child);
        if (result) setChild({ parent_id: '', name_ar: '', name_en: '' });
    };

    return <CompanyLayout title={t('chart')}>
        <div className="erp-page-header"><div><span className="erp-eyebrow"><Link href="/accounting">{t('accounting')}</Link> / {t('chart')}</span><h1>{t('chart')}</h1><p>{t('chartIntro')}</p></div>
            <div className="erp-toolbar no-print">{canCreate && accounts.length === 0 && <button type="button" className="erp-button erp-button--primary" onClick={() => send('accounts/initialize', 'POST')}>{t('initializeChart')}</button>}</div></div>
        {notice.message && <div role={notice.type === 'error' ? 'alert' : 'status'} className={`notice-card ${notice.type}`}>{notice.message}</div>}

        {accounts.length === 0 ? <div className="erp-empty"><h3>{t('emptyChartTitle')}</h3><p>{t('emptyChartCopy')}</p></div> : <section className="erp-section">
            <div className="chart-toolbar no-print">
                <label className="field chart-search"><span className="sr-only">{t('searchAccounts')}</span><input type="search" value={query} placeholder={t('searchAccounts')} onChange={(event) => setQuery(event.target.value)} /></label>
                <div className="chart-filters" role="group" aria-label={t('filter')}>{FILTERS.map((key) => <button key={key} type="button" aria-pressed={filter === key} className={`erp-button ${filter === key ? 'erp-button--primary' : 'erp-button--secondary'}`} onClick={() => setFilter(key)}>{t(`chartFilter_${key}`)}</button>)}</div>
                <div className="chart-expanders"><button type="button" className="erp-link-button" onClick={expandAll}>{t('expandAll')}</button><button type="button" className="erp-link-button" onClick={collapseAll}>{t('collapseAll')}</button></div>
            </div>
            <p className="permission-note">{t('protectedHint')} · {shown} {t('accountsShown')}</p>
            {shown === 0 ? <div className="erp-empty erp-empty--compact"><strong>{t('noAccountMatches')}</strong></div> : <div className="account-tree" role="tree">{roots.map((account) => renderNode(account))}</div>}
            {canCreate && <form className="account-create-form" onSubmit={createAccount}>
                <div><h3>{t('newAccount')}</h3><p>{t('newAccountHint')}</p></div>
                <select required value={child.parent_id} onChange={(event) => setChild({ ...child, parent_id: event.target.value })} aria-label={t('parentAccount')}><option value="">{t('parentAccount')}</option>{accounts.map((account) => <option key={account.id} value={account.id}>{accountLabel(account, locale)}</option>)}</select>
                <input value={child.name_ar} maxLength={255} placeholder={t('arabicName')} onChange={(event) => setChild({ ...child, name_ar: event.target.value })} aria-label={t('arabicName')} dir="rtl" />
                <input value={child.name_en} maxLength={255} placeholder={t('englishName')} onChange={(event) => setChild({ ...child, name_en: event.target.value })} aria-label={t('englishName')} dir="ltr" />
                <button disabled={processing || (!child.name_ar.trim() && !child.name_en.trim())} className="erp-button erp-button--primary">{t('create')}</button>
            </form>}
        </section>}

        {editing && <ModalShell titleId="account-edit-title" eyebrow={editing.code} title={t('editAccount')} subtitle={editing.is_system ? t('protectedHint') : t('customAccountHint')} closeLabel={t('close')} onClose={() => setEditing(null)} footer={<><button type="button" className="erp-button erp-button--secondary" onClick={() => setEditing(null)}>{t('cancel')}</button><button type="submit" form="account-edit-form" disabled={processing || (!editing.name_ar.trim() && !editing.name_en.trim())} className="erp-button erp-button--primary">{t('saveChanges')}</button></>}>
            {notice.type === 'error' && notice.message && <div role="alert" className="notice-card error">{notice.message}</div>}
            <form id="account-edit-form" className="account-edit-form" onSubmit={saveAccount}>
                <label className="field"><span>{t('accountCode')}</span><input value={editing.code} readOnly disabled /></label>
                <label className="field"><span>{t('arabicName')}</span><input dir="rtl" maxLength={255} value={editing.name_ar} onChange={(event) => setEditing({ ...editing, name_ar: event.target.value })} /></label>
                <label className="field"><span>{t('englishName')}</span><input dir="ltr" maxLength={255} value={editing.name_en} onChange={(event) => setEditing({ ...editing, name_en: event.target.value })} /></label>
                {!editing.is_system && <label className="field"><span>{t('parentAccount')}</span><select value={editing.parent_id ?? ''} onChange={(event) => setEditing({ ...editing, parent_id: event.target.value })}><option value="">{t('rootAccount')}</option>{accounts.filter((account) => !blockedParents.has(Number(account.id))).map((account) => <option key={account.id} value={account.id}>{accountLabel(account, locale)}</option>)}</select></label>}
                {editing.is_system && <p className="permission-note">{t('systemLockedFields')}</p>}
            </form>
        </ModalShell>}
    </CompanyLayout>;
}
