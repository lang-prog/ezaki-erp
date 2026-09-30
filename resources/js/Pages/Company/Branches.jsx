import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';
import { useCoreApi } from '../../useCoreApi';

export default function Branches({ branches, capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const { notice, processing, send } = useCoreApi();
    const [form, setForm] = useState({ code: '', name: '', phone: '', address: '', warehouse_code: '', warehouse_name: '' });
    const [editing, setEditing] = useState(null);

    function submit(event) {
        event.preventDefault();
        send('branches', 'POST', form);
    }

    return <CompanyLayout title={t('branches')}>
        <h1 className="text-2xl font-semibold">{t('branches')}</h1>
        {notice.message && <p role="status" className={`mt-4 border-l-4 bg-white p-3 text-sm ${notice.type === 'error' ? 'border-red-700' : 'border-[#34795c]'}`}>{notice.message}</p>}
        <div className="mt-7 divide-y divide-[#d7ddd5] border-y border-[#d7ddd5]">
            {branches.data.map((branch) => <article key={branch.id} className="py-4">
                <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="font-semibold">{branch.name}</h2><p className="mt-1 text-xs text-[#637067]">{branch.code} · {branch.phone ?? ''}</p></div>
                    <div className="flex gap-3 text-sm">{capabilities['branches.update'] && <button onClick={() => setEditing({ id: branch.id, name: branch.name, phone: branch.phone ?? '', address: branch.address ?? '' })} className="underline">{t('edit')}</button>}{capabilities['branches.archive'] && <button onClick={() => window.confirm(`${t('archive')} ${branch.name}?`) && send(`branches/${branch.id}/archive`, 'POST')} className="underline">{t('archive')}</button>}</div></div>
                <div className="mt-3 grid gap-2 sm:grid-cols-2">{branch.warehouses.map((warehouse) => <div key={warehouse.id} className="flex items-center justify-between border-l-2 border-[#d7a94f] bg-white px-3 py-2 text-sm"><span>{warehouse.name} <span className="text-xs text-[#637067]">{warehouse.code}</span></span><span className="flex gap-3">{capabilities['warehouses.update'] && <button onClick={() => send(`warehouses/${warehouse.id}`, 'PUT', { name: window.prompt(t('warehouseName'), warehouse.name) || warehouse.name, details: warehouse.details })} className="underline">{t('edit')}</button>}{capabilities['warehouses.archive'] && <button onClick={() => window.confirm(`${t('archive')} ${warehouse.name}?`) && send(`warehouses/${warehouse.id}/archive`, 'POST')} className="underline">{t('archive')}</button>}</span></div>)}</div>
            </article>)}
            {branches.data.length === 0 && <p className="py-5 text-sm text-[#637067]">{t('noBranches')}</p>}
        </div>
        <nav className="mt-3 flex gap-2 text-sm">{branches.links.map((link, index) => link.url ? <a key={index} href={link.url} className="border border-[#c9d0c8] px-2 py-1">{link.label.replace(/<[^>]*>/g, '').trim()}</a> : null)}</nav>
        {capabilities['branches.create'] && <section className="mt-8 max-w-3xl border-t border-[#d7ddd5] pt-5"><h2 className="font-semibold">{t('createBranch')}</h2><form onSubmit={submit} className="mt-4 grid gap-3 sm:grid-cols-2">
            <input required aria-label={t('branchCode')} placeholder={t('branchCode')} value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} className="border border-[#c9d0c8] bg-white px-3 py-2" />
            <input required aria-label={t('branchName')} placeholder={t('branchName')} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} className="border border-[#c9d0c8] bg-white px-3 py-2" />
            <input aria-label={t('phone')} placeholder={t('phone')} value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} className="border border-[#c9d0c8] bg-white px-3 py-2" />
            <input aria-label={t('address')} placeholder={t('address')} value={form.address} onChange={(event) => setForm({ ...form, address: event.target.value })} className="border border-[#c9d0c8] bg-white px-3 py-2" />
            <input aria-label={t('warehouseName')} placeholder={t('warehouseName')} value={form.warehouse_name} onChange={(event) => setForm({ ...form, warehouse_name: event.target.value })} className="border border-[#c9d0c8] bg-white px-3 py-2" />
            <button disabled={processing} className="bg-[#34795c] px-4 py-2 text-white disabled:opacity-60">{t('createBranch')} + {t('warehouse')}</button>
        </form></section>}
        {editing && <div className="fixed inset-0 z-20 grid place-items-center bg-black/40 p-4"><form onSubmit={(event) => { event.preventDefault(); send(`branches/${editing.id}`, 'PUT', editing); }} className="w-full max-w-lg bg-white p-6"><h2 className="text-lg font-semibold">{t('edit')} {t('branches')}</h2>{['name', 'phone', 'address'].map((field) => <label key={field} className="mt-3 block text-sm">{t(field)}<input value={editing[field] ?? ''} onChange={(event) => setEditing({ ...editing, [field]: event.target.value })} className="mt-1 w-full border border-[#c9d0c8] px-3 py-2" /></label>)}<div className="mt-5 flex gap-3"><button className="bg-[#34795c] px-4 py-2 text-white">{t('saveChanges')}</button><button type="button" onClick={() => setEditing(null)} className="underline">{t('cancel')}</button></div></form></div>}
    </CompanyLayout>;
}
