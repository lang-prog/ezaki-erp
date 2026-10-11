import { Link, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import { translate } from '../../i18n';
import manualSource from '../../../docs/E-ZAKI-ERP-USER-MANUAL.md?raw';

const slugify = (value) => value
    .toLowerCase()
    .replace(/[^\p{L}\p{N}]+/gu, '-')
    .replace(/^-|-$/g, '');

function parseManual(source) {
    const lines = source.split(/\r?\n/);
    const sections = [];
    let current = null;
    let block = [];
    let mode = 'paragraph';

    const pushBlock = () => {
        if (!current || block.length === 0) return;
        const linesToSave = block;
        if (mode === 'code') current.blocks.push({ type: 'code', value: linesToSave.join('\n') });
        else if (mode === 'table') {
            const rows = linesToSave
                .filter((line) => line.trim() && !/^\|?\s*:?-{2,}/.test(line.replace(/^\|/, '')))
                .map((line) => line.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map((cell) => cell.trim()));
            if (rows.length) current.blocks.push({ type: 'table', rows });
        } else if (mode === 'list') current.blocks.push({ type: 'list', items: linesToSave.map((line) => line.replace(/^\s*(?:[-*]|\d+\.)\s+/, '')) });
        else if (mode === 'quote') current.blocks.push({ type: 'quote', value: linesToSave.join(' ') });
        else {
            const value = linesToSave.join(' ').trim();
            if (value) current.blocks.push({ type: 'paragraph', value });
        }
        block = [];
        mode = 'paragraph';
    };

    lines.forEach((line) => {
        const heading = line.match(/^(#{1,3})\s+(.+)$/);
        if (heading) {
            pushBlock();
            const level = heading[1].length;
            const title = heading[2].replace(/\s+#+$/, '').trim();
            current = { id: slugify(title), level, title, blocks: [] };
            sections.push(current);
            return;
        }
        if (!current || line.trim() === '---') return;
        if (line.trim().startsWith('```')) {
            if (mode === 'code') pushBlock();
            else { pushBlock(); mode = 'code'; }
            return;
        }
        if (mode === 'code') { block.push(line); return; }
        if (/^\s*\|/.test(line)) {
            if (mode !== 'table') pushBlock();
            mode = 'table'; block.push(line); return;
        }
        if (/^\s*(?:[-*]|\d+\.)\s+/.test(line)) {
            if (mode !== 'list') pushBlock();
            mode = 'list'; block.push(line); return;
        }
        if (/^\s*>/.test(line)) {
            if (mode !== 'quote') pushBlock();
            mode = 'quote'; block.push(line.replace(/^\s*>\s?/, '')); return;
        }
        if (!line.trim()) { pushBlock(); return; }
        if (mode !== 'paragraph') pushBlock();
        block.push(line.trim());
    });
    pushBlock();
    return sections;
}

function ManualBlock({ block }) {
    if (block.type === 'code') return <pre className="help-code"><code>{block.value}</code></pre>;
    if (block.type === 'quote') return <div className="help-callout">{block.value}</div>;
    if (block.type === 'list') return <ul className="help-list">{block.items.map((item, index) => <li key={`${item}-${index}`}>{item}</li>)}</ul>;
    if (block.type === 'table') return <div className="help-table-wrap"><table className="help-table"><thead><tr>{block.rows[0]?.map((cell, index) => <th key={`${cell}-${index}`}>{cell}</th>)}</tr></thead><tbody>{block.rows.slice(1).map((row, rowIndex) => <tr key={rowIndex}>{row.map((cell, index) => <td key={`${cell}-${index}`}>{cell}</td>)}</tr>)}</tbody></table></div>;
    return <p className="help-paragraph">{block.value}</p>;
}

export default function HelpCenter() {
    const { locale } = usePage().props;
    const [query, setQuery] = useState('');
    const [activeId, setActiveId] = useState('');
    const [expanded, setExpanded] = useState({});
    const sections = useMemo(() => parseManual(manualSource), []);
    const filtered = useMemo(() => {
        const normalized = query.trim().toLocaleLowerCase();
        if (!normalized) return sections;
        return sections.filter((section) => `${section.title} ${section.blocks.map((block) => JSON.stringify(block)).join(' ')}`.toLocaleLowerCase().includes(normalized));
    }, [query, sections]);
    const t = (key) => translate(locale || 'ar', key);
    const pageTitle = locale === 'en' ? 'Help Center' : 'مركز المساعدة';
    const intro = locale === 'en' ? 'A practical guide to running your company on E-Zaki.' : 'دليل عملي لتشغيل شركتك على نظام E‑Zaki.';
    const quickLinks = [
        { href: '/operations/purchase/create', label: locale === 'en' ? 'New purchase' : 'فاتورة شراء جديدة' },
        { href: '/operations/sales/create', label: locale === 'en' ? 'New sale' : 'فاتورة بيع جديدة' },
        { href: '/inventory', label: locale === 'en' ? 'Inventory' : 'المخزون' },
        { href: '/accounting', label: locale === 'en' ? 'Accounting' : 'المحاسبة' },
        { href: '/settings/access', label: locale === 'en' ? 'Users & access' : 'المستخدمون والصلاحيات' },
    ];
    const mainSections = filtered.filter((section) => section.level === 1 || section.level === 2);

    const focusHash = (hash = window.location.hash) => {
        const id = decodeURIComponent(hash.replace(/^#/, ''));
        if (!id) return;
        const target = document.getElementById(id);
        const section = sections.find((item) => item.id === id);
        if (section) setExpanded((current) => ({ ...current, [id]: true }));
        if (target) {
            setActiveId(id);
            window.requestAnimationFrame(() => target.scrollIntoView({ behavior: 'smooth', block: 'start' }));
        }
    };

    useEffect(() => {
        const onHashChange = () => focusHash();
        window.addEventListener('hashchange', onHashChange);
        const timer = window.setTimeout(() => focusHash(), 0);
        return () => { window.clearTimeout(timer); window.removeEventListener('hashchange', onHashChange); };
    }, [sections]);

    return <CompanyLayout title={pageTitle}>
        <div className="help-center">
            <header className="help-hero">
                <div className="help-hero__copy">
                    <span className="help-eyebrow">E‑Zaki · {locale === 'en' ? 'Company workspace' : 'مساحة عمل الشركة'}</span>
                    <h1>{pageTitle}</h1>
                    <p>{intro}</p>
                </div>
                <div className="help-hero__badge" aria-hidden="true"><span>?</span><small>{locale === 'en' ? 'Guide' : 'دليل'}</small></div>
                <label className="help-search">
                    <span aria-hidden="true">⌕</span>
                    <input type="search" value={query} onChange={(event) => setQuery(event.target.value)} placeholder={locale === 'en' ? 'Search the guide…' : 'ابحث داخل دليل الاستخدام…'} aria-label={locale === 'en' ? 'Search the guide' : 'البحث داخل الدليل'} />
                    {query && <button type="button" onClick={() => setQuery('')} aria-label={locale === 'en' ? 'Clear search' : 'مسح البحث'}>×</button>}
                </label>
            </header>

            <div className="help-quick-links" aria-label={locale === 'en' ? 'Quick actions' : 'إجراءات سريعة'}>
                {quickLinks.map((item) => <Link href={item.href} key={item.href} className="help-quick-link">{item.label}<span aria-hidden="true">↗</span></Link>)}
            </div>

            <div className="help-layout">
                <aside className="help-toc" aria-label={locale === 'en' ? 'Guide contents' : 'محتويات الدليل'}>
                    <div className="help-toc__header"><strong>{locale === 'en' ? 'In this guide' : 'في هذا الدليل'}</strong><span>{filtered.length}</span></div>
                    <nav>{mainSections.map((section) => <a key={section.id} className={activeId === section.id ? 'is-active' : ''} href={`#${section.id}`} onClick={() => setActiveId(section.id)}>{section.title}</a>)}</nav>
                </aside>

                <main className="help-content">
                    {query && <div className="help-result-note">{locale === 'en' ? `Showing ${filtered.length} matching sections` : `عرض ${filtered.length} قسم مطابق للبحث`}</div>}
                    {filtered.length === 0 && <div className="help-empty"><strong>{locale === 'en' ? 'No matching guidance' : 'لا توجد إرشادات مطابقة'}</strong><p>{locale === 'en' ? 'Try a different word, such as invoice, stock, accounting or permissions.' : 'جرّب كلمة أخرى مثل: فاتورة، مخزون، محاسبة، أو صلاحيات.'}</p></div>}
                    {filtered.map((section) => {
                        const isTopLevel = section.level === 1;
                        const isOpen = expanded[section.id] !== false;
                        return <section id={section.id} className={`help-section ${isTopLevel ? 'help-section--major' : ''}`} key={section.id}>
                            <button type="button" className="help-section__heading" onClick={() => setExpanded((current) => ({ ...current, [section.id]: !isOpen }))} aria-expanded={isOpen}>
                                <span className="help-section__number">{isTopLevel ? section.title.match(/^\d+/)?.[0] || '•' : '—'}</span><span><strong>{section.title.replace(/^\d+\.\s*/, '')}</strong><small>{isOpen ? (locale === 'en' ? 'Hide details' : 'إخفاء التفاصيل') : (locale === 'en' ? 'Show details' : 'عرض التفاصيل')}</small></span><b aria-hidden="true">{isOpen ? '−' : '+'}</b>
                            </button>
                            {isOpen && <div className="help-section__body">{section.blocks.map((block, index) => <ManualBlock block={block} key={`${section.id}-${index}`} />)}</div>}
                        </section>;
                    })}
                </main>
            </div>
        </div>
    </CompanyLayout>;
}
