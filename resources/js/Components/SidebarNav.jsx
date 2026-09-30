import { Link, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { translate } from '../i18n';

function isActiveUrl(href, currentUrl) {
    const target = new URL(href, 'http://ezaki.local');
    const current = new URL(currentUrl || '/', 'http://ezaki.local');
    if (target.pathname !== current.pathname) return false;
    if (target.hash) return target.hash === current.hash;
    return !current.hash;
}

export default function SidebarNav({ id, open, onClose, groups, locale, capabilities = {}, platform = false, user, workspaceLabel, workspaceHint }) {
    const { url } = usePage();
    const currentUrl = typeof window !== 'undefined' ? `${url || window.location.pathname}${window.location.hash}` : url;

    useEffect(() => {
        if (!open) return undefined;
        const handleKeyDown = (event) => {
            if (event.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', handleKeyDown);
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', handleKeyDown);
            document.body.style.overflow = previousOverflow;
        };
    }, [open, onClose]);

    const initials = (user?.name || (platform ? 'SA' : 'EZ')).split(' ').slice(0, 2).map((part) => part[0]).join('').toUpperCase();

    return <>
        <div className={`sidebar-backdrop ${open ? 'is-visible' : ''}`} onClick={onClose} aria-hidden="true" />
        <aside id={id} className={`sidebar ${platform ? 'sidebar--platform' : ''} ${open ? 'open' : ''}`} aria-label={workspaceLabel}>
            <div className="brand">
                <div className="brand-mark" aria-hidden="true">◆</div>
                <div><strong>E‑Zaki</strong><span>{platform ? 'PLATFORM CONTROL' : 'ERP / BUSINESS OS'}</span></div>
                <button type="button" className="mobile-close" onClick={onClose} aria-label={translate(locale, 'closeMenu')}>×</button>
            </div>
            <div className="workspace">
                <div className="workspace-logo" aria-hidden="true">{platform ? 'SA' : 'EZ'}</div>
                <div><strong>{workspaceLabel}</strong><small>{workspaceHint}</small></div>
            </div>
            <nav aria-label={translate(locale, 'primaryNavigation')}>
                {groups.map((group) => <div className="nav-group" key={group.labelKey}>
                    <div className="nav-label">{translate(locale, group.labelKey)}</div>
                    {group.items.map(([permission, labelKey, href, icon]) => capabilities[permission] && <Link
                        key={href}
                        href={href}
                        className={`nav-item ${isActiveUrl(href, currentUrl) ? 'active' : ''}`}
                        aria-current={isActiveUrl(href, currentUrl) ? 'page' : undefined}
                        onClick={onClose}
                    ><b aria-hidden="true">{icon}</b><span>{translate(locale, labelKey)}</span></Link>)}
                </div>)}
            </nav>
            <div className="sidebar-footer">
                {!platform && <div className="help-card"><div className="help-icon" aria-hidden="true">?</div><div><strong>{translate(locale, 'needHelp')}</strong><span>{translate(locale, 'contactSupport')}</span></div></div>}
                <div className="profile-mini"><div className={`avatar ${platform ? 'avatar-purple' : 'avatar-blue'}`}>{initials}</div><div><strong>{user?.name || (platform ? translate(locale, 'platformAdminShort') : '')}</strong><span>{platform ? translate(locale, 'platformAdminShort') : translate(locale, 'companyUser')}</span></div></div>
            </div>
        </aside>
    </>;
}
