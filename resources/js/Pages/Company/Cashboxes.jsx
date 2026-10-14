import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';
import { useCoreApi } from '../../useCoreApi';

export default function Cashboxes({ cashboxes = [], capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const { notice, processing, send } = useCoreApi();
    const [name, setName] = useState('');
    const canCreate = Boolean(capabilities['accounting.create']);

    return <CompanyLayout title={t('cashboxes')}>
        <div className="erp-page-header"><div><span className="erp-eyebrow"><Link href="/accounting">{t('accounting')}</Link> / {t('cashboxes')}</span><h1>{t('cashboxes')}</h1><p>{t('cashboxesHint')}</p></div></div>
        {notice.message && <div role={notice.type === 'error' ? 'alert' : 'status'} className={`notice-card ${notice.type}`}>{notice.message}</div>}
        <section className="erp-section">
            <div className="erp-table-wrap"><table className="erp-table">
                <thead><tr><th>{t('cashboxName')}</th><th>{t('linkedAccount')}</th></tr></thead>
                <tbody>
                    {cashboxes.map((item) => <tr key={item.id}><td>{item.name}</td><td>{item.account?.code ?? '—'}</td></tr>)}
                    {cashboxes.length === 0 && <tr><td colSpan="2" className="empty-cell">{t('noRecords')}</td></tr>}
                </tbody>
            </table></div>
            {canCreate && <form className="inline-create-form" onSubmit={async (event) => { event.preventDefault(); if (await send('cashboxes', 'POST', { name })) setName(''); }}>
                <input required value={name} onChange={(event) => setName(event.target.value)} placeholder={t('cashboxName')} aria-label={t('cashboxName')} />
                <button disabled={processing} className="erp-button erp-button--primary">{t('createCashbox')}</button>
            </form>}
        </section>
    </CompanyLayout>;
}
