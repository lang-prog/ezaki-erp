const paths = {
    home: <><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/><path d="M9 21v-8h6v8"/></>,
    dashboard: <><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></>,
    activity: <path d="M3 12h4l3-7 4 14 3-7h4"/>,
    sales: <><path d="M4 17 18 3"/><path d="M8 3h10v10"/><path d="M4 5v15h15"/></>,
    purchases: <><path d="M4 7 18 21"/><path d="M8 21h10V11"/><path d="M4 19V4h15"/></>,
    stock: <><path d="m12 2 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 17l9 5 9-5"/></>,
    branches: <><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 21V9h6v12M3 9h18M6 6h.01M18 6h.01"/></>,
    people: <><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M16 3a4 4 0 0 1 0 8M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/></>,
    person: <><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></>,
    ledger: <><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 2v20M12 7h5M12 12h5M12 17h5"/></>,
    chart: <><path d="M4 20V12m5 8V8m5 12v-5m5 5V4M2 20h20"/></>,
    truck: <><path d="M3 5h12v11H3zM15 9h4l3 4v3h-7z"/><circle cx="7.5" cy="18" r="2"/><circle cx="18" cy="18" r="2"/></>,
    settings: <><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-1.8 1.8-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.06 1.56V20H9v-.1A1.7 1.7 0 0 0 7.94 18.34a1.7 1.7 0 0 0-1.88.34l-.06.06-1.8-1.8.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.56-1.06H3v-3.88h.04A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.34-1.88L4.2 7.06l1.8-1.8.06.06A1.7 1.7 0 0 0 7.94 5 1.7 1.7 0 0 0 9 3.44V3h6v.44A1.7 1.7 0 0 0 16.06 5a1.7 1.7 0 0 0 1.88.34l.06-.06 1.8 1.8-.06.06A1.7 1.7 0 0 0 19.4 9a1.7 1.7 0 0 0 1.56 1.06H21v3.88h-.04A1.7 1.7 0 0 0 19.4 15Z"/></>,
    document: <><path d="M6 2h8l4 4v16H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z"/><path d="M14 2v5h5M8 12h8M8 16h8"/></>,
    list: <><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></>,
    add: <path d="M12 5v14M5 12h14"/>,
    ticket: <><path d="M4 4h16v5a3 3 0 0 0 0 6v5H4v-5a3 3 0 0 0 0-6zM12 4v16"/></>,
    search: <><circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/></>,
    chevron: <path d="m6 9 6 6 6-6"/>,
    menu: <path d="M4 7h16M4 12h16M4 17h16"/>,
    close: <path d="M5 5 19 19M19 5 5 19"/>,
    logout: <><path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4M14 8l4 4-4 4M9 12h9"/></>,
};

export default function NavigationIcon({ name, size = 20, ...props }) {
    return <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" focusable="false" {...props}>{paths[name] || paths.document}</svg>;
}
