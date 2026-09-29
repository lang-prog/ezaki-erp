import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';

export default function PartyProfile({ party, kind }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const supplier = kind === 'supplier';

    return <CompanyLayout title={party.name}>
        <Link href={supplier ? '/suppliers' : '/customers'} className="text-sm underline">{supplier ? t('suppliers') : t('customers')}</Link>
        <h1 className="mt-3 text-2xl font-semibold">{party.name}</h1>
        <div className="mt-5 grid gap-3 border-y border-[#d7ddd5] py-5 text-sm"><p>{t('accountCode')}: {party.account?.code}</p><p>{t('email')}: {party.email ?? '-'}</p><p>{t('phone')}: {party.phone ?? '-'}</p><p>{t('address')}: {party.address ?? '-'}</p></div>
        <div className="mt-5 flex flex-wrap gap-3"><Link className="border border-[#34795c] px-3 py-2" href={`/reports/account-statement?account_id=${party.account_id}`}>{t('accountStatement')}</Link><Link className="border border-[#34795c] px-3 py-2" href={`/accounting?counterparty_type=${supplier ? 'supplier' : 'customer'}&party_id=${party.id}`}>{supplier ? t('postVoucher') : t('postReceipt')}</Link></div>
    </CompanyLayout>;
}
