import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';
import { formatMoney } from '../../format';

const REPORTS = [['journal', 'journal'], ['ledger', 'ledger'], ['trial-balance', 'trialBalance'], ['income-statement', 'incomeStatement'], ['balance-sheet', 'balanceSheet'], ['debtors', 'debtors'], ['receivables', 'receivablesReport'], ['payables', 'payablesReport'], ['invoice-settlements', 'invoiceSettlements']];

export default function Accounting({ stats = {}, capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const cards = [
        { href: '/accounting/chart', title: 'chart', copy: 'hubChartCopy', stat: stats.accounts, statLabel: 'statAccounts' },
        { href: '/accounting/periods', title: 'fiscalPeriods', copy: 'hubPeriodsCopy', stat: stats.open_periods, statLabel: 'statOpenPeriods' },
        { href: '/accounting/cashboxes', title: 'cashboxes', copy: 'hubCashboxesCopy', stat: stats.cashboxes, statLabel: 'statActive' },
        { href: '/accounting/banks', title: 'banks', copy: 'hubBanksCopy', stat: stats.banks, statLabel: 'statActive' },
        { href: '/accounting/receipts', title: 'receipts', copy: 'hubReceiptsCopy', stat: stats.receipts, statLabel: 'statDocuments' },
        { href: '/accounting/payment-vouchers', title: 'vouchers', copy: 'hubVouchersCopy', stat: stats.vouchers, statLabel: 'statDocuments' },
    ];
    if (capabilities['reports.view']) {
        cards.push({ href: '/reports/invoice-settlements', title: 'invoiceSettlements', copy: 'hubSettlementsCopy' });
    }

    return <CompanyLayout title={t('accounting')}>
        <div className="erp-page-header"><div><span className="erp-eyebrow">{t('navCompany')} / {t('accounting')}</span><h1>{t('accounting')}</h1><p>{t('accountingPageIntro')}</p></div></div>

        <section className="erp-section" aria-labelledby="accounting-hub-title">
            <div className="erp-section__header"><div><h2 id="accounting-hub-title">{t('accountingWorkspace')}</h2><p>{t('hubIntro')}</p></div></div>
            <div className="hub-card-grid">
                {cards.map((card) => <Link key={card.href} href={card.href} className="hub-card">
                    <strong className="hub-card__title">{t(card.title)}</strong>
                    <span className="hub-card__copy">{t(card.copy)}</span>
                    {card.stat !== undefined && <span className="hub-card__stat"><b>{formatMoney(card.stat, locale, 0)}</b> {t(card.statLabel)}</span>}
                    <span className="hub-card__cta">{t('openPage')} <span aria-hidden="true">↗</span></span>
                </Link>)}
            </div>
        </section>

        {capabilities['reports.view'] && <section className="erp-section accounting-reports">
            <div className="erp-section__header"><div><h2>{t('reports')}</h2><p>{t('reportsHint')}</p></div></div>
            <div className="report-link-grid">{REPORTS.map(([slug, key]) => <Link key={slug} href={`/reports/${slug}`} className="report-link-card"><span>{t(key)}</span><strong aria-hidden="true">↗</strong></Link>)}</div>
        </section>}
    </CompanyLayout>;
}
