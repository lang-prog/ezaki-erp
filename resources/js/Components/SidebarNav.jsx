import { Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { translate } from '../i18n';
import { isActiveNavigation, visibleNavigation } from '../navigation';
import NavigationIcon from './NavigationIcon';

const MOBILE_QUERY = '(max-width: 900px)';

function currentAddress(url) {
    if (typeof window === 'undefined') return url || '/';
    return `${window.location.pathname}${window.location.search}${window.location.hash}`;
}

export default function SidebarNav({ id, open, onClose, groups, locale, capabilities = {}, platform = false, user, workspaceLabel, workspaceHint }) {
    const { url } = usePage();
    const [address, setAddress] = useState(() => currentAddress(url));
    const [mobile, setMobile] = useState(() => typeof window !== 'undefined' && window.matchMedia(MOBILE_QUERY).matches);
    const [search, setSearch] = useState('');
    const [expanded, setExpanded] = useState({});
    const asideRef = useRef(null);
    const navigationRef = useRef(null);
    const previousFocus = useRef(null);
    const t = useCallback((key) => translate(locale, key), [locale]);

    useEffect(() => {
        const update = () => setAddress(currentAddress(url));
        update();
        window.addEventListener('hashchange', update);
        window.addEventListener('popstate', update);
        return () => {
            window.removeEventListener('hashchange', update);
            window.removeEventListener('popstate', update);
        };
    }, [url]);

    useEffect(() => {
        const media = window.matchMedia(MOBILE_QUERY);
        const update = () => {
            setMobile(media.matches);
            if (!media.matches) onClose();
        };
        update();
        media.addEventListener('change', update);
        return () => media.removeEventListener('change', update);
    }, [onClose]);

    useEffect(() => {
        if (!mobile || !open) return undefined;
        previousFocus.current = document.activeElement;
        asideRef.current?.querySelector('.ez-nav-close')?.focus();
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        const handleKey = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                onClose();
            }
            if (event.key !== 'Tab') return;
            const controls = [...asideRef.current.querySelectorAll('a,button,input')]
                .filter((element) => !element.disabled && element.getClientRects().length);
            if (!controls.length) return;
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };
        document.addEventListener('keydown', handleKey);
        return () => {
            document.body.style.overflow = overflow;
            document.removeEventListener('keydown', handleKey);
            previousFocus.current?.focus?.();
        };
    }, [mobile, open, onClose]);

    const visible = useMemo(() => visibleNavigation(groups, capabilities, user?.id, search, t), [groups, capabilities, user?.id, search, t]);
    const allItems = useMemo(() => visible.flatMap((group) => group.items), [visible]);
    // Prefer the exact destination over contextual parent links such as a user's profile.
    const exact = allItems.find((item) => {
        const target = new URL(item.href, 'http://ezaki.local');
        const current = new URL(address || '/', 'http://ezaki.local');
        return target.pathname === current.pathname && target.hash === current.hash;
    });
    const activeId = exact?.id ?? allItems.find((item) => isActiveNavigation(item, address))?.id;
    const initials = (user?.name || (platform ? 'SA' : 'EZ')).split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();

    useEffect(() => {
        if (!navigationRef.current) return;
        if (mobile) {
            if (open) navigationRef.current.scrollTop = 0;
        } else if (!search) {
            const selected = navigationRef.current.querySelector('[aria-current="page"]');
            if (selected) navigationRef.current.scrollTop = Math.max(0, selected.offsetTop - navigationRef.current.offsetTop - navigationRef.current.clientHeight / 2);
        }
    }, [activeId, search, mobile, open]);

    return <>
        {mobile && open && <button type="button" className="ez-nav-backdrop" onClick={onClose} aria-label={t('closeMenu')} tabIndex={-1} />}
        <aside ref={asideRef} id={id} className={`ez-sidebar ${platform ? 'ez-sidebar--platform' : ''} ${open ? 'is-open' : ''}`} dir={locale === 'ar' ? 'rtl' : 'ltr'} aria-label={workspaceLabel} inert={mobile && !open ? true : undefined}>
            <div className="ez-nav-brand">
                <span className="ez-nav-brandmark" aria-hidden="true">E</span>
                <div className="ez-nav-brandcopy"><strong>E-Zaki</strong><small>ERP · {platform ? 'PLATFORM' : 'WORKSPACE'}</small></div>
                <button className="ez-nav-close" type="button" onClick={onClose} aria-label={t('closeMenu')}><NavigationIcon name="close" size={19} /></button>
            </div>
            <div className="ez-nav-workspace" title={workspaceLabel}>
                <span className="ez-nav-workspace-mark" aria-hidden="true">{platform ? 'SA' : 'EZ'}</span>
                <span className="ez-nav-workspace-copy"><strong>{workspaceLabel}</strong><small>{workspaceHint}</small></span>
            </div>
            <div className="ez-nav-search">
                <NavigationIcon name="search" size={17} />
                <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder={t('searchNavigation')} aria-label={t('searchNavigation')} />
                {search && <button type="button" onClick={() => setSearch('')} aria-label={t('clearSearch')}><NavigationIcon name="close" size={16} /></button>}
            </div>
            <nav ref={navigationRef} className="ez-nav-scroll" aria-label={t('primaryNavigation')}>
                {visible.map((group) => {
                    const containsActive = group.items.some((item) => item.id === activeId);
                    const isExpanded = search ? true : expanded[group.id] ?? (containsActive || Boolean(group.expanded));
                    return <section className="ez-nav-section" key={group.id}>
                        <button className={`ez-nav-group ${containsActive ? 'has-active' : ''}`} type="button" aria-expanded={isExpanded} aria-controls={`${id}-${group.id}`} onClick={() => setExpanded((value) => ({ ...value, [group.id]: !isExpanded }))}>
                            <NavigationIcon name={group.icon} size={19} />
                            <span>{t(group.label)}</span>
                            <small aria-hidden="true">{group.items.length}</small>
                            <NavigationIcon name="chevron" size={16} className={`ez-nav-chevron ${isExpanded ? 'rotated' : ''}`} />
                        </button>
                        {isExpanded && <div className="ez-nav-submenu" id={`${id}-${group.id}`}>
                            {group.items.map((item) => {
                                const selected = activeId === item.id;
                                const content = <><NavigationIcon name={item.icon} size={16} /><span>{t(item.label)}</span></>;
                                return item.href.includes('#')
                                    ? <a key={item.id} href={item.href} onClick={onClose} className={`ez-nav-link ${selected ? 'is-active' : ''}`} aria-current={selected ? 'page' : undefined}>{content}</a>
                                    : <Link key={item.id} href={item.href} onClick={onClose} className={`ez-nav-link ${selected ? 'is-active' : ''}`} aria-current={selected ? 'page' : undefined}>{content}</Link>;
                            })}
                        </div>}
                    </section>;
                })}
                {!visible.length && <p className="ez-nav-empty" role="status">{t('noNavigationMatches')}</p>}
            </nav>
            <div className="ez-nav-footer">
                {!platform && user?.id && <Link href={`/settings/users/${user.id}`} onClick={onClose} className={`ez-nav-profile ${address.split('?')[0].split('#')[0] === `/settings/users/${user.id}` ? 'is-active' : ''}`}>
                    <span className="ez-nav-avatar" aria-hidden="true">{initials}</span>
                    <span className="ez-nav-profile-copy"><strong>{user.name}</strong><small>{t('myProfile')}</small></span>
                    <NavigationIcon name="person" size={17} />
                </Link>}
                {platform && <div className="ez-nav-profile"><span className="ez-nav-avatar" aria-hidden="true">{initials}</span><span className="ez-nav-profile-copy"><strong>{user?.name || t('platformAdmin')}</strong><small>{t('platformAdminShort')}</small></span></div>}
            </div>
        </aside>
    </>;
}
