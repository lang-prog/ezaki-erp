import { Link } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';

export default function UserLoginHistory({ profile, logins }) {
    return <CompanyLayout title={`Login history: ${profile.name}`}>
        <Link href={`/settings/users/${profile.id}`} className="text-sm underline">Back to profile</Link><h1 className="mt-3 text-2xl font-semibold">Login history · {profile.name}</h1>
        <div className="mt-6 overflow-x-auto"><table className="w-full min-w-[650px] text-left text-sm"><thead><tr className="border-y border-[#d7ddd5]"><th className="py-3">Result</th><th className="py-3">Date</th><th className="py-3">IP address</th><th className="py-3">Browser</th></tr></thead><tbody>{logins.data.map((login) => <tr key={login.id} className="border-b border-[#d7ddd5]"><td className="py-3">{login.successful ? 'Success' : 'Failed'}</td><td className="py-3">{new Date(login.created_at).toLocaleString()}</td><td className="py-3">{login.ip_address ?? '-'}</td><td className="py-3">{login.user_agent ?? '-'}</td></tr>)}</tbody></table>{logins.data.length === 0 && <p className="py-4 text-sm text-[#637067]">No login records.</p>}</div>
    </CompanyLayout>;
}