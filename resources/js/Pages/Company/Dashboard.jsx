import CompanyLayout from '../../Layouts/CompanyLayout';
import { usePage } from '@inertiajs/react';
import { translate } from '../../i18n';

export default function Dashboard({ metrics = {}, capabilities = {}, daily_sales = [], payments_receipts = {}, top_sold_products = [], sales_by_customer = [] }) {
    const { locale } = usePage().props;
    const number = (value) => Number(value ?? 0).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-EG');
    const cards = [
        capabilities['branches.view'] && ['branches', metrics.branches, '/branches'],
        capabilities['branches.view'] && ['warehouses', metrics.warehouses, '/branches'],
        capabilities['products.view'] && ['products', metrics.products, '/inventory'],
        capabilities['inventory.view'] && ['stock_quantity', metrics.stock_quantity, '/inventory'],
        capabilities['parties.view'] && ['parties', metrics.parties, '/parties'],
        capabilities['accounting.view'] && ['debtors', metrics.debtors, '/reports/debtors'],
        capabilities['sales.view'] && ['monthly_sales', metrics.monthly_sales, '/operations#sales'],
        capabilities['purchases.view'] && ['monthly_purchases', metrics.monthly_purchases, '/operations#purchases'],
    ].filter(Boolean);

    return <CompanyLayout title={translate(locale, 'companyOverview')}>
        <h1 className="text-2xl font-semibold">{translate(locale, 'companyOverview')}</h1>
        <p className="mt-2 text-sm text-[#637067]">{translate(locale, 'companyWorkspace')}</p>
        <div className="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{cards.map(([key, value, href]) => <a key={key} href={href} className="border-t-2 border-[#34795c] bg-white px-4 py-4 hover:bg-[#f8faf7]"><p className="text-xs uppercase text-[#637067]">{translate(locale, key)}</p><p className="mt-2 text-2xl font-semibold">{number(value)}</p></a>)}</div>
        {(capabilities['sales.view'] || capabilities['purchases.view']) && <div className="mt-8 grid gap-5 lg:grid-cols-2"><section className="border-t border-[#d7ddd5] bg-white p-4"><h2 className="font-semibold">{translate(locale, 'dailySales')}</h2><div className="mt-3 space-y-2">{daily_sales.map((item) => <div key={item.bill_date} className="flex items-center gap-3 text-sm"><span className="w-24">{item.bill_date}</span><span className="h-2 bg-[#34795c]" style={{ width: `${Math.max(4, Math.min(100, Number(item.total) / 10))}%` }} /><strong>{number(item.total)}</strong></div>)}{daily_sales.length === 0 && <p className="text-sm text-[#637067]">{translate(locale, 'noRecords')}</p>}</div></section><section className="border-t border-[#d7ddd5] bg-white p-4"><h2 className="font-semibold">{translate(locale, 'paymentsReceipts')}</h2><p className="mt-3 text-sm">{translate(locale, 'payments')}: {number(payments_receipts.payments)}</p><p className="text-sm">{translate(locale, 'receipts')}: {number(payments_receipts.receipts)}</p></section></div>}
        {(capabilities['sales.view']) && <div className="mt-5 grid gap-5 lg:grid-cols-2"><section className="border-t border-[#d7ddd5] bg-white p-4"><h2 className="font-semibold">{translate(locale, 'topSoldProducts')}</h2>{top_sold_products.map((item) => <p key={item.name} className="mt-2 flex justify-between text-sm"><span>{item.name}</span><strong>{number(item.quantity)}</strong></p>)}</section><section className="border-t border-[#d7ddd5] bg-white p-4"><h2 className="font-semibold">{translate(locale, 'salesByCustomer')}</h2>{sales_by_customer.map((item) => <p key={item.name} className="mt-2 flex justify-between text-sm"><span>{item.name}</span><strong>{number(item.total)}</strong></p>)}</section></div>}
    </CompanyLayout>;
}