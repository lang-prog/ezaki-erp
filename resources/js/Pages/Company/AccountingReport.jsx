import { Link, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';
import { useCoreApi } from '../../useCoreApi';

const titles = { journal: 'journal', ledger: 'ledger', 'account-statement': 'accountStatement', 'trial-balance': 'trialBalance', 'income-statement': 'incomeStatement', 'balance-sheet': 'balanceSheet', debtors: 'debtors', receivables: 'receivablesReport', payables: 'payablesReport', creditors: 'creditorsReport' };

export default function AccountingReport({ report, rows = [], entries, lines, account, availableAccounts = [], opening_balance: openingBalance, threshold, from = '', to = '', capabilities = {} }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const { notice, send } = useCoreApi();
    const reportRows = rows?.data ?? entries?.data ?? lines?.data ?? rows ?? [];
    return <CompanyLayout title={t(titles[report] ?? 'reports')}>
        <div className="printable-report">
        <Link href="/accounting" className="no-print text-sm underline">{t('accounting')}</Link>
        <h1 className="mt-3 text-2xl font-semibold">{t(titles[report] ?? 'reports')}</h1>
        <button type="button" onClick={() => window.print()} className="no-print mt-4 border border-[#34795c] px-3 py-2 text-sm">{t('print')}</button>
        <form method="get" className="no-print mt-4 flex flex-wrap items-end gap-3 border-y border-[#d7ddd5] py-4">{report === 'account-statement' && <label className="grid gap-1 text-sm"><span>{t('selectAccount')}</span><select name="account_id" required defaultValue={account?.id ?? ''} className="max-w-xs border px-3 py-2"><option value="">{t('selectAccount')}</option>{availableAccounts.map((item) => <option key={item.id} value={item.id}>{item.code} · {item.name}</option>)}</select></label>}<label className="grid gap-1 text-sm"><span>{t('fromDate')}</span><input type="date" name="from_date" defaultValue={from} className="border px-3 py-2" /></label><label className="grid gap-1 text-sm"><span>{t('toDate')}</span><input type="date" name="to_date" defaultValue={to} className="border px-3 py-2" /></label><button className="border border-[#34795c] px-3 py-2 text-sm">{t('apply')}</button></form>
        {account && <p className="mt-2 text-sm">{account.code} · {account.name} · {t('openingAmount')}: {openingBalance}</p>}
        {threshold !== undefined && <p className="mt-2 text-sm text-[#637067]">{t('minimum')}: {threshold} EGP</p>}
        {notice.message && <p role="status" className="mt-4 border-l-4 border-red-700 bg-white p-3 text-sm">{notice.message}</p>}
        <div className="mt-6 overflow-x-auto"><table className="w-full min-w-[700px] text-left text-sm"><thead><tr className="border-y border-[#d7ddd5]"><th className="py-3">{t('entryDate')}</th><th className="py-3">{report === 'journal' ? t('journal') : t('accountCode')}</th><th className="py-3">{t('accountName')}</th><th className="py-3">{t('debit')}</th><th className="py-3">{t('credit')}</th><th className="py-3">{t('balance')}</th>{report === 'journal' && <th className="no-print py-3">{t('reverse')}</th>}</tr></thead><tbody>{reportRows.map((row, index) => <tr key={row.id ?? row.entry_number ?? `${row.code}-${index}`} className="border-b border-[#d7ddd5]"><td className="py-3">{row.entry_date ?? row.date ?? '-'}</td><td className="py-3">{row.account_code ?? row.code ?? row.entry_number ?? '-'}</td><td className="py-3">{row.account_name ?? row.name ?? row.description ?? row.line_description ?? '-'}{row.source_url && <a href={row.source_url} className="no-print ms-2 underline">{t('source')}</a>}</td><td className="py-3">{row.debit ?? row.debit_total ?? '-'}</td><td className="py-3">{row.credit ?? row.credit_total ?? '-'}</td><td className="py-3">{row.running_balance ?? row.balance ?? row.balance_due ?? '-'}</td>{report === 'journal' && <td className="no-print py-3">{capabilities['accounting.reverse'] && <button onClick={() => send(`journals/${row.id}/reverse`, 'POST', { date: new Date().toISOString().slice(0, 10)})} className="underline">{t('reverse')}</button>}</td>}</tr>)}</tbody></table>{reportRows.length === 0 && <p className="py-4 text-sm text-[#637067]">{t('noRecords')}</p>}</div>
        </div>
    </CompanyLayout>;
}
