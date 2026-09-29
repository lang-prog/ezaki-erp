import { Link } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';

export default function UserActivity({ profile, activity }) {
    return <CompanyLayout title={`Activity: ${profile.name}`}>
        <Link href={`/settings/users/${profile.id}`} className="text-sm underline">Back to profile</Link><h1 className="mt-3 text-2xl font-semibold">Activity log · {profile.name}</h1>
        <div className="mt-6 divide-y divide-[#d7ddd5] border-y border-[#d7ddd5]">{activity.data.map((item) => <div key={item.id} className="py-3"><p className="font-medium">{item.event}</p><p className="mt-1 text-xs text-[#637067]">{new Date(item.created_at).toLocaleString()} · {item.subject_type ?? 'System'} {item.subject_id ?? ''}</p></div>)}{activity.data.length === 0 && <p className="py-4 text-sm text-[#637067]">No activity recorded.</p>}</div>
        <nav className="mt-4 flex gap-2 text-sm">{activity.links.map((link, index) => link.url ? <Link key={index} href={link.url} className="border border-[#c9d0c8] px-2 py-1">{link.label.replace(/<[^>]*>/g, '').trim()}</Link> : <span key={index} className="px-2 py-1 text-[#8a928b]">{link.label.replace(/<[^>]*>/g, '').trim()}</span>)}</nav>
    </CompanyLayout>;
}