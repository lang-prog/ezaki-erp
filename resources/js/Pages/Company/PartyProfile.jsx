import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import PartyMoneyModal from '../../Components/PartyMoneyModal';
import { translate } from '../../i18n';

export default function PartyProfile({ party, kind, cashboxes = [], banks = [], capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const [moneyModal, setMoneyModal] = useState(false);
    const customer = kind === 'customer';

    return <CompanyLayout title={party.name}>
        <Link href={customer ? '/customers' : '/suppliers'} className="text-sm underline">{customer ? t('customers') : t('suppliers')}</Link>
        <div className="erp-page-header"><div><p className="eyebrow">{customer ? t('customer') : t('supplier')}</p><h1>{party.name}</h1><p>{party.account?.code ?? '—'} · {t('accountStatement')}</p></div><span className={`party-kind party-kind--${customer ? 'customer' : 'supplier'}`}>{customer ? t('isCustomer') : t('isSupplier')}</span></div>
        <section className="party-profile-card"><div className="party-profile-facts"><div><span>{t('accountCode')}</span><strong>{party.account?.code ?? '—'}</strong></div><div><span>{t('email')}</span><strong>{party.email ?? '—'}</strong></div><div><span>{t('phone')}</span><strong>{party.phone ?? '—'}</strong></div><div><span>{t('address')}</span><strong>{party.address ?? '—'}</strong></div></div><div className="row-actions">{capabilities['accounting.view'] && <Link className="erp-button erp-button--secondary" href={`/reports/account-statement?account_id=${party.account_id}`}>{t('accountStatement')}</Link>}{capabilities['accounting.post'] && <button type="button" className={`erp-button ${customer ? 'erp-button--primary' : 'erp-button--danger'}`} onClick={() => setMoneyModal(true)}>{customer ? t('postReceipt') : t('postVoucher')}</button>}</div></section>
        {moneyModal && <PartyMoneyModal party={party} kind={kind} cashboxes={cashboxes} banks={banks} locale={locale} onClose={() => setMoneyModal(false)} />}
    </CompanyLayout>;
}
