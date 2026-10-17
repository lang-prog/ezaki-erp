import './bootstrap';
import React from 'react';
import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import ErrorBoundary from './Components/ErrorBoundary';
import { csrfHeaders } from './csrf';
import { translate } from './i18n';

const pages = import.meta.glob('./Pages/**/*.jsx');

// Final safety net only: a render failure shows a localized, recoverable card instead of an empty root.
function AppCrashFallback({ retry }) {
    const locale = document.documentElement.lang || 'en';
    return <main className="notice-card error" role="alert" style={{ margin: '2rem auto', maxInlineSize: '36rem' }}>
        <h1>{translate(locale, 'pf_boundary_title')}</h1>
        <p>{translate(locale, 'pf_boundary_copy')}</p>
        <div className="erp-toolbar">
            <button type="button" className="erp-button erp-button--primary" onClick={retry}>{translate(locale, 'pf_boundary_retry')}</button>
            <button type="button" className="erp-button erp-button--secondary" onClick={() => window.location.reload()}>{translate(locale, 'pf_boundary_reload')}</button>
        </div>
    </main>;
}

function AppBoundary({ children }) {
    const [navigations, setNavigations] = React.useState(0);
    React.useEffect(() => router.on('navigate', () => setNavigations((count) => count + 1)), []);

    return <ErrorBoundary resetKey={navigations} fallback={AppCrashFallback}>{children}</ErrorBoundary>;
}

function GlobalRequestErrors({ children }) {
    const [message, setMessage] = React.useState('');
    React.useEffect(() => {
        const handleError = (event) => {
            const locale = document.documentElement.lang || 'en';
            const status = event.detail?.status;
            const key = status === 419 ? 'sessionExpired' : status === 403 ? 'permissionDenied' : status === 429 ? 'tooManyRequests' : status === 422 ? 'requestInvalid' : status >= 500 ? 'serverError' : 'networkError';
            setMessage(event.detail?.message || translate(locale, key));
        };
        window.addEventListener('ezaki:request-error', handleError);
        return () => window.removeEventListener('ezaki:request-error', handleError);
    }, []);

    return <>{children}{message && <div role="alert" className="notice-card error no-print" style={{ position: 'fixed', insetInlineEnd: '1rem', bottom: '1rem', zIndex: 1000, maxWidth: 'min(32rem, calc(100vw - 2rem))' }}><span>{message}</span><button type="button" className="erp-link-button" onClick={() => setMessage('')} aria-label={translate(document.documentElement.lang || 'en', 'close')}>×</button></div>}</>;
}

const arabicText = /[\u0600-\u06ff]/;
const legacyArabic = {
    Branch: 'الفرع', Warehouse: 'المخزن', Supplier: 'المورد', Customer: 'العميل', Lines: 'البنود', 'Type / brand': 'النوع / العلامة', Diameter: 'القطر', 'Factory weight': 'وزن المصنع', Packages: 'العبوات', 'Packages (optional)': 'العبوات (اختياري)', 'Actual weight': 'الوزن الفعلي', 'Unit price': 'سعر الوحدة', Transport: 'النقل', Loading: 'التحميل', Extras: 'إضافات', 'Discount': 'الخصم', 'VAT %': 'ضريبة القيمة المضافة %', 'External vehicle': 'مركبة خارجية', 'External plate': 'لوحة خارجية', 'Driver name': 'اسم السائق', 'Save as draft': 'حفظ كمسودة', 'Save & Approve': 'حفظ واعتماد', Approve: 'اعتماد', Reverse: 'عكس', 'Revision reason': 'سبب التعديل', Vehicles: 'المركبات', Drivers: 'السائقون', Trips: 'الرحلات', Expenses: 'المصروفات', Maintenance: 'الصيانة', 'New vehicle': 'مركبة جديدة', 'New driver': 'سائق جديد', 'Save vehicle': 'حفظ المركبة', 'Save driver': 'حفظ السائق', 'New trip': 'رحلة جديدة', 'New expense': 'مصروف جديد', 'Vehicle': 'المركبة', 'Trip type': 'نوع الرحلة', Origin: 'من', Destination: 'إلى', 'New maintenance': 'صيانة جديدة', 'Maintenance report': 'تقرير الصيانة', 'Inactive vehicles': 'المركبات غير النشطة', 'Total cost': 'إجمالي التكلفة', Plate: 'اللوحة', Type: 'النوع', 'Make / model': 'الماركة / الطراز', Company: 'الشركة', Rented: 'مؤجرة', External: 'خارجية', 'Save trip': 'حفظ الرحلة', 'Save expense': 'حفظ المصروف', 'Save maintenance': 'حفظ الصيانة', 'Start date': 'تاريخ البدء', Issue: 'العطل', Cost: 'التكلفة', 'Preventive': 'وقائية', 'In service': 'في الخدمة', 'Add line': 'إضافة بند', '+ line': '+ بند', 'Account number': 'رقم الحساب', Journal: 'اليومية', Source: 'المصدر', 'From date': 'من تاريخ', 'To date': 'إلى تاريخ', Apply: 'تطبيق', Print: 'طباعة', View: 'عرض', Statement: 'كشف الحساب', Edit: 'تعديل', Save: 'حفظ', Create: 'إنشاء', 'Select a plan': 'اختر خطة', Plan: 'الخطة', Email: 'البريد الإلكتروني', Password: 'كلمة المرور', Name: 'الاسم', Phone: 'الهاتف', Address: 'العنوان', 'Search...': 'بحث...'
};

function localizeLegacyArabic() {
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach((node) => {
        const value = node.nodeValue.trim();
        if (legacyArabic[value]) node.nodeValue = node.nodeValue.replace(value, legacyArabic[value]);
    });
    document.querySelectorAll('input, select, textarea, button').forEach((control) => {
        ['placeholder', 'title', 'aria-label'].forEach((attribute) => {
            const value = control.getAttribute(attribute);
            if (value && legacyArabic[value]) control.setAttribute(attribute, legacyArabic[value]);
        });
    });
}

function enhanceControls(locale) {
    const fallback = locale === 'ar' ? 'حقل إدخال' : 'Input field';
    document.querySelectorAll('input, select, textarea').forEach((control) => {
        const hint = control.getAttribute('aria-label') || control.getAttribute('placeholder') || control.getAttribute('name');
        if (!control.title || (locale === 'ar' && hint && !arabicText.test(control.title))) {
            control.title = locale === 'ar' && (!hint || !arabicText.test(hint)) ? fallback : (hint || fallback);
        }
        // Never inject DOM into React-owned dialogs: a later reconcile of that subtree could throw and blank the page.
        if (control.tagName === 'SELECT' && control.options.length > 7 && !control.dataset.searchEnhanced && !control.closest('[role="dialog"]')) {
            const search = document.createElement('input');
            search.type = 'search';
            search.className = 'mb-1 block w-full border border-[#c9d0c8] bg-white px-3 py-2 text-sm';
            search.placeholder = locale === 'ar' ? 'بحث...' : 'Search...';
            search.title = search.placeholder;
            search.setAttribute('aria-label', search.placeholder);
            search.addEventListener('input', () => {
                const value = search.value.trim().toLocaleLowerCase();
                Array.from(control.options).forEach((option) => {
                    option.hidden = Boolean(value) && !option.text.toLocaleLowerCase().includes(value);
                });
            });
            control.parentNode?.insertBefore(search, control);
            control.dataset.searchEnhanced = 'true';
        }
    });
    if (locale === 'ar') localizeLegacyArabic();
}

createInertiaApp({
    title: (title) => `${title} · E-Zaki ERP`,
    resolve: async (name) => {
        const page = pages[`./Pages/${name}.jsx`];
        if (!page) throw new Error(`Inertia page not found: ${name}`);
        return page();
    },
    setup({ el, App, props }) {
        router.on('before', (event) => {
            event.detail.visit.headers = { ...event.detail.visit.headers, ...csrfHeaders() };
        });
        router.on('invalid', (event) => {
            const status = event.detail.response?.status;
            event.preventDefault();
            window.dispatchEvent(new CustomEvent('ezaki:request-error', { detail: { status } }));
        });
        const syncDocumentLanguage = (locale = 'en') => {
            document.documentElement.lang = locale;
            document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr';
        };
        syncDocumentLanguage(props.initialPage.props.locale);
        enhanceControls(props.initialPage.props.locale);
        router.on('navigate', (event) => syncDocumentLanguage(event.detail.page.props.locale));
        router.on('navigate', (event) => enhanceControls(event.detail.page.props.locale));
        createRoot(el).render(<GlobalRequestErrors><AppBoundary><App {...props} /></AppBoundary></GlobalRequestErrors>);
        const observer = new MutationObserver(() => enhanceControls(document.documentElement.lang));
        observer.observe(el, { childList: true, subtree: true });
        queueMicrotask(() => enhanceControls(props.initialPage.props.locale));
    },
});
