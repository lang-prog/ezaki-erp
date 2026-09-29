import './bootstrap';
import React from 'react';
import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

const pages = import.meta.glob('./Pages/**/*.jsx');

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
        if (control.tagName === 'SELECT' && control.options.length > 7 && !control.dataset.searchEnhanced) {
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
        const syncDocumentLanguage = (locale = 'en') => {
            document.documentElement.lang = locale;
            document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr';
        };
        syncDocumentLanguage(props.initialPage.props.locale);
        enhanceControls(props.initialPage.props.locale);
        router.on('navigate', (event) => syncDocumentLanguage(event.detail.page.props.locale));
        router.on('navigate', (event) => enhanceControls(event.detail.page.props.locale));
        createRoot(el).render(<App {...props} />);
        const observer = new MutationObserver(() => enhanceControls(document.documentElement.lang));
        observer.observe(el, { childList: true, subtree: true });
        queueMicrotask(() => enhanceControls(props.initialPage.props.locale));
    },
});