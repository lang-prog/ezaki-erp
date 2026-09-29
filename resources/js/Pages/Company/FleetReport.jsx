import CompanyLayout from '../../Layouts/CompanyLayout';
import { Link, usePage } from '@inertiajs/react';
import { translate } from '../../i18n';

export default function FleetReport({ report, rows = [] }) {
    const { locale } = usePage().props;
    const title = report === 'maintenance-period' ? 'Maintenance in period' : 'Inactive vehicles';
    return <CompanyLayout title={title}><Link href="/fleet" className="text-sm underline">{translate(locale, 'fleet')}</Link><h1 className="mt-2 text-2xl font-semibold">{title}</h1><div className="mt-6 overflow-x-auto"><table className="w-full min-w-[500px] text-left text-sm"><thead><tr className="border-y border-[#d7ddd5]"><th className="py-3">Vehicle</th><th className="py-3">{report === 'maintenance-period' ? 'Total cost' : 'Plate'}</th></tr></thead><tbody>{rows.map((row) => <tr key={row.vehicle_id ?? row.id} className="border-b border-[#d7ddd5]"><td className="py-3">{row.vehicle?.plate ?? row.plate ?? row.vehicle_id}</td><td className="py-3">{row.total ?? row.status}</td></tr>)}</tbody></table>{rows.length === 0 && <p className="py-4 text-sm text-[#637067]">{translate(locale, 'noRecords')}</p>}</div></CompanyLayout>;
}
