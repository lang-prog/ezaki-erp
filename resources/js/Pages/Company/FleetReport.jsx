import CompanyLayout from '../../Layouts/CompanyLayout';
import { Link, usePage } from '@inertiajs/react';
import { translate } from '../../i18n';

const definitions = {
    'vehicle-pl': ['ربحية المركبات', 'Vehicle profit & loss'],
    'trip-cost': ['تكلفة الرحلات', 'Trip cost'],
    fuel: ['استهلاك الوقود', 'Fuel consumption'],
    'driver-performance': ['أداء السائقين', 'Driver performance'],
    'expenses-by-category': ['المصروفات حسب التصنيف', 'Expenses by category'],
    'maintenance-period': ['الصيانة خلال فترة', 'Maintenance in period'],
    'inactive-vehicles': ['المركبات غير النشطة', 'Inactive vehicles'],
    'branch-performance': ['أداء الفروع', 'Branch performance'],
};
const labels = {
    label: ['البيان', 'Label'], date: ['التاريخ', 'Date'], revenue: ['الإيراد', 'Revenue'], cost: ['التكلفة', 'Cost'], profit: ['الربح', 'Profit'],
    distance: ['المسافة', 'Distance'], fuel_cost: ['تكلفة الوقود', 'Fuel cost'], cost_per_distance: ['تكلفة/مسافة', 'Cost / distance'], trips: ['الرحلات', 'Trips'], count: ['العدد', 'Count'], total: ['الإجمالي', 'Total'], type: ['النوع', 'Type'], status: ['الحالة', 'Status'],
};
const columns = {
    'vehicle-pl': ['label', 'revenue', 'cost', 'profit'], 'trip-cost': ['label', 'date', 'revenue', 'cost', 'profit'], fuel: ['label', 'distance', 'fuel_cost', 'cost_per_distance'],
    'driver-performance': ['label', 'trips', 'distance', 'revenue'], 'expenses-by-category': ['label', 'count', 'total'], 'maintenance-period': ['label', 'count', 'total'],
    'inactive-vehicles': ['label', 'type', 'status'], 'branch-performance': ['label', 'trips', 'revenue', 'cost', 'profit'],
};

export default function FleetReport({ report, rows = [], from = '', to = '', branch_id: branchId = '', branches = [], capabilities = {} }) {
    const { locale } = usePage().props;
    const ar = locale === 'ar';
    const title = definitions[report]?.[ar ? 0 : 1] ?? report;
    const reportColumns = columns[report] ?? ['label', 'total'];
    const exportUrl = `/fleet/reports/${report}/export?${new URLSearchParams({ ...(from && { from }), ...(to && { to }), ...(branchId && { branch_id: branchId }) }).toString()}`;

    return <CompanyLayout title={title}><div className="printable-report"><Link href="/fleet" className="no-print text-sm underline">{translate(locale, 'fleet')}</Link><h1 className="mt-2 text-2xl font-semibold">{title}</h1><form method="get" className="no-print mt-5 flex flex-wrap items-end gap-3"><label className="grid gap-1 text-sm"><span>{translate(locale, 'fromDate')}</span><input type="date" name="from" defaultValue={from} className="border px-3 py-2" /></label><label className="grid gap-1 text-sm"><span>{translate(locale, 'toDate')}</span><input type="date" name="to" defaultValue={to} className="border px-3 py-2" /></label><label className="grid gap-1 text-sm"><span>{translate(locale, 'branches')}</span><select name="branch_id" defaultValue={branchId ?? ''} className="border px-3 py-2"><option value="">{translate(locale, 'allWarehouses')}</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label><button className="border border-[#34795c] px-3 py-2">{translate(locale, 'apply')}</button><button type="button" onClick={() => window.print()} className="border border-[#34795c] px-3 py-2">{translate(locale, 'print')}</button>{capabilities['fleet.export'] && <a href={exportUrl} className="border border-[#34795c] px-3 py-2">{translate(locale, 'exportCsv')}</a>}</form><div className="mt-6 overflow-x-auto"><table className="w-full min-w-[650px] text-left text-sm"><thead><tr className="border-y border-[#d7ddd5]">{reportColumns.map((column) => <th key={column} className="py-3">{labels[column]?.[ar ? 0 : 1] ?? column}</th>)}</tr></thead><tbody>{rows.map((row, index) => <tr key={row.id ?? index} className="border-b border-[#d7ddd5]">{reportColumns.map((column) => <td key={column} className="py-3">{row[column] ?? '-'}</td>)}</tr>)}</tbody></table>{rows.length === 0 && <p className="py-4 text-sm text-[#637067]">{translate(locale, 'noRecords')}</p>}</div></div></CompanyLayout>;
}
