import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';
import { useCoreApi } from '../../useCoreApi';

export default function Banks({ banks = [], capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const { notice, processing, send } = useCoreApi();
    const [bank, setBank] = useState({ name: '', account_number: '' });
    const canCreate = Boolean(capabilities['accounting.create']);

    return <CompanyLayout title={t('banks')}>
        <div className="erp-page-header"><div><span className="erp-eyebrow"><Link href="/accounting">{t('accounting')}</Link> / {t('banks')}</span><h1>{t('banks')}</h1><p>{t('banksHint')}</p></div></div>
        {notice.message && <div role={notice.type === 'error' ? 'alert' : 'status'} className={`notice-card ${notice.type}`}>{notice.message}</div>}
        <section className="erp-section">
            <div className="erp-table-wrap"><table className="erp-table">
                <thead><tr><th>{t('bankName')}</th><th>{t('accountNumber')}</th><th>{t('linkedAccount')}</th></tr></thead>
                <tbody>
                    {banks.map((item) => <tr key={item.id}><td>{item.name}</td><td>{item.account_number || '—'}</td><td>{item.account?.code ?? '—'}</td></tr>)}
                    {banks.length === 0 && <tr><td colSpan="3" className="empty-cell">{t('noRecords')}</td></tr>}
                </tbody>
            </table></div>
            {canCreate && <form className="inline-create-form inline-create-form--wide" onSubmit={async (event) => { event.preventDefault(); if (await send('banks', 'POST', bank)) setBank({ name: '', account_number: '' }); }}>
                <input required value={bank.name} onChange={(event) => setBank({ ...bank, name: event.target.value })} placeholder={t('bankName')} aria-label={t('bankName')} />
                <input value={bank.account_number} onChange={(event) => setBank({ ...bank, account_number: event.target.value })} placeholder={t('accountNumber')} aria-label={t('accountNumber')} />
                <button disabled={processing} className="erp-button erp-button--primary">{t('createBank')}</button>
            </form>}
        </section>
    </CompanyLayout>;
}
