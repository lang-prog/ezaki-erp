const moduleLabels = {
    en: { dashboard: 'Dashboard', users: 'Users', roles: 'Roles', subscriptions: 'Subscriptions', branches: 'Branches', warehouses: 'Warehouses', products: 'Products', inventory: 'Inventory', parties: 'Customers & suppliers', accounting: 'Accounting', reports: 'Reports', purchases: 'Purchases', sales: 'Sales', fleet: 'Fleet' },
    ar: { dashboard: 'لوحة التحكم', users: 'المستخدمون', roles: 'الأدوار', subscriptions: 'الاشتراكات', branches: 'الفروع', warehouses: 'المخازن', products: 'الأصناف', inventory: 'المخزون', parties: 'العملاء والموردون', accounting: 'المحاسبة', reports: 'التقارير', purchases: 'المشتريات', sales: 'المبيعات', fleet: 'الأسطول' },
};

const actionLabels = {
    en: { view: 'View', create: 'Create', update: 'Edit', archive: 'Archive', transfer: 'Transfer', export: 'Export', post: 'Post', reverse: 'Reverse', close_period: 'Close periods', approve: 'Approve', update_approved: 'Revise approved documents', cancel: 'Cancel', print: 'Print', deactivate: 'Deactivate', reset_password: 'Reset passwords', view_activity: 'View activity', assign_permissions: 'Assign permissions', renew: 'Renew', settings: 'Manage settings', expenses: 'Manage expenses', maintenance: 'Manage maintenance' },
    ar: { view: 'عرض', create: 'إنشاء', update: 'تعديل', archive: 'أرشفة', transfer: 'تحويل', export: 'تصدير', post: 'ترحيل', reverse: 'عكس', close_period: 'إغلاق الفترات', approve: 'اعتماد', update_approved: 'مراجعة المستندات المعتمدة', cancel: 'إلغاء', print: 'طباعة', deactivate: 'تعطيل', reset_password: 'إعادة تعيين كلمات المرور', view_activity: 'عرض النشاط', assign_permissions: 'تعيين الصلاحيات', renew: 'تجديد', settings: 'إدارة الإعدادات', expenses: 'إدارة المصروفات', maintenance: 'إدارة الصيانة' },
};

const descriptions = {
    en: {
        view: 'Allows opening and reading records in this area.', create: 'Allows creating new records and transactions.', update: 'Allows editing existing draft records.', archive: 'Allows hiding or archiving records without deleting history.', transfer: 'Allows moving stock between warehouses.', export: 'Allows downloading reports or data files.', post: 'Allows posting a financial transaction to the ledger.', reverse: 'Allows reversing a posted transaction with an audit trail.', close_period: 'Allows closing a fiscal period so it cannot receive new postings.', approve: 'Allows approving a document and applying its accounting and stock effect.', update_approved: 'Allows revising an approved document with a reason and audit trail.', cancel: 'Allows cancelling an unposted transaction.', print: 'Allows printing the official document.', deactivate: 'Allows disabling a user account.', reset_password: 'Allows setting a new password for another user.', view_activity: 'Allows reviewing audit and login activity.', assign_permissions: 'Allows changing the permissions granted by a role.', renew: 'Allows requesting or recording a subscription renewal.', settings: 'Allows changing module settings.', expenses: 'Allows recording and editing fleet expenses.', maintenance: 'Allows recording and editing vehicle maintenance.'
    },
    ar: {
        view: 'تتيح فتح السجلات وقراءتها داخل هذا القسم.', create: 'تتيح إنشاء سجلات وعمليات جديدة.', update: 'تتيح تعديل السجلات المسودة الموجودة.', archive: 'تتيح إخفاء السجلات أو أرشفتها دون حذف تاريخها.', transfer: 'تتيح تحويل المخزون بين المخازن.', export: 'تتيح تنزيل التقارير أو ملفات البيانات.', post: 'تتيح ترحيل العملية المالية إلى دفتر الأستاذ.', reverse: 'تتيح عكس عملية مرحّلة مع الاحتفاظ بأثر التدقيق.', close_period: 'تتيح إغلاق الفترة المالية ومنع الترحيل إليها.', approve: 'تتيح اعتماد المستند وتطبيق أثره المحاسبي والمخزني.', update_approved: 'تتيح مراجعة مستند معتمد مع تسجيل السبب وأثر التدقيق.', cancel: 'تتيح إلغاء العملية غير المرحّلة.', print: 'تتيح طباعة المستند الرسمي.', deactivate: 'تتيح تعطيل حساب مستخدم.', reset_password: 'تتيح تعيين كلمة مرور جديدة لمستخدم آخر.', view_activity: 'تتيح مراجعة سجل التدقيق ومحاولات الدخول.', assign_permissions: 'تتيح تغيير الصلاحيات التي يمنحها الدور.', renew: 'تتيح طلب أو تسجيل تجديد الاشتراك.', settings: 'تتيح تغيير إعدادات القسم.', expenses: 'تتيح تسجيل وتعديل مصروفات الأسطول.', maintenance: 'تتيح تسجيل وتعديل صيانة المركبات.'
    },
};

export function permissionParts(name) {
    const [module, ...actionParts] = String(name).split('.');
    return { module, action: actionParts.join('_') || 'view' };
}

export function permissionModule(locale, name) {
    const { module } = permissionParts(name);
    return moduleLabels[locale]?.[module] ?? module;
}

export function permissionLabel(locale, name) {
    const { module, action } = permissionParts(name);
    const actionLabel = actionLabels[locale]?.[action] ?? action.replaceAll('_', ' ');
    return `${moduleLabels[locale]?.[module] ?? module} · ${actionLabel}`;
}

export function permissionDescription(locale, name) {
    const { module, action } = permissionParts(name);
    const base = descriptions[locale]?.[action] ?? descriptions.en[action] ?? 'Controls access to this application capability.';
    return locale === 'ar' ? `${base} القسم: ${moduleLabels.ar[module] ?? module}.` : `${base} Area: ${moduleLabels.en[module] ?? module}.`;
}
