// Subscription duration catalog (Super Admin). Wording lives here; the server supplies categories, units and limits.
const UNIT_FORMS = {
    en: {
        day: { one: '{count} day', other: '{count} days' },
        week: { one: '{count} week', other: '{count} weeks' },
        month: { one: '{count} month', other: '{count} months' },
        year: { one: '{count} year', other: '{count} years' },
    },
    ar: {
        day: { one: 'يوم واحد', two: 'يومان', few: '{count} أيام', many: '{count} يومًا', other: '{count} يوم' },
        week: { one: 'أسبوع واحد', two: 'أسبوعان', few: '{count} أسابيع', many: '{count} أسبوعًا', other: '{count} أسبوع' },
        month: { one: 'شهر واحد', two: 'شهران', few: '{count} أشهر', many: '{count} شهرًا', other: '{count} شهر' },
        year: { one: 'سنة واحدة', two: 'سنتان', few: '{count} سنوات', many: '{count} سنة', other: '{count} سنة' },
    },
};

const LIFETIME = { en: 'Lifetime', ar: 'مدى الحياة' };
const lang = (locale) => (locale === 'ar' ? 'ar' : 'en');

/** "3 months" / "شهران" / "11 شهرًا" for a unit and whole-number value; lifetime has no value. */
export function durationSummary(locale, duration) {
    const unit = duration?.unit;
    if (unit === 'lifetime') return LIFETIME[lang(locale)];
    const forms = UNIT_FORMS[lang(locale)][unit];
    const value = duration?.value;
    if (!forms || !Number.isInteger(value) || value < 1) return '—';
    const form = forms[new Intl.PluralRules(lang(locale)).select(value)] ?? forms.other;

    return form.replace('{count}', String(value));
}

/** Catalog label for the active language, falling back to the other language and finally the stable key. */
export function durationLabel(locale, duration) {
    if (!duration) return '—';
    const first = locale === 'ar' ? duration.name_ar : duration.name_en;
    const second = locale === 'ar' ? duration.name_en : duration.name_ar;

    return first || second || duration.key || '—';
}

export const durationsEn = {
    dur_nav: 'Subscription durations', dur_title: 'Subscription durations', dur_intro: 'Define the period types and lengths plans can use. Archived durations stay valid for plans and subscriptions that already use them.',
    dur_category_trial: 'Trial period', dur_category_test: 'Test / evaluation period', dur_category_paid: 'Paid subscription', dur_category: 'Category',
    dur_unit_day: 'Days', dur_unit_week: 'Weeks', dur_unit_month: 'Months', dur_unit_year: 'Years', dur_unit_lifetime: 'Lifetime', dur_unit: 'Unit', dur_value: 'Value',
    dur_key: 'Stable key', dur_key_hint: 'Optional. Lowercase letters, digits and underscores. It never changes after creation.',
    dur_name_ar: 'Arabic name', dur_name_en: 'English name', dur_desc_ar: 'Arabic help text', dur_desc_en: 'English help text', dur_order: 'Display order',
    dur_system: 'System (protected)', dur_custom: 'Custom', dur_kind: 'Kind', dur_plans: 'Plans (active / all)', dur_subscriptions: 'Subscriptions', dur_interval: 'Interval',
    dur_create: 'Add a duration', dur_create_submit: 'Add duration', dur_created: 'Duration added.', dur_edit: 'Edit', dur_search: 'Search durations', dur_all_categories: 'All categories', dur_all_statuses: 'All statuses',
    dur_status_active: 'Active', dur_status_inactive: 'Archived', dur_empty: 'No durations match your filters.',
    dur_preview: 'Preview', dur_expiry_rule: 'Expiry = start date + {summary}.', dur_no_expiry: 'No expiry date.',
    dur_effect_trial: 'Companies see "Your trial period ends in …" and the trial wording in emails.', dur_effect_test: 'Companies see "Your test period ends in …" and the test wording in emails.',
    dur_effect_paid: 'Companies see "Your subscription ends in …" within the notice window before the end date.', dur_effect_lifetime: 'No expiry and no expiry warnings.',
    dur_immutable_system: 'Protected system duration: category, unit and value cannot change.', dur_immutable_referenced: 'Plans already use this duration, so category, unit and value are locked. Add a new duration instead.',
    dur_editable_note: 'This duration is not used yet, so its interval can still change. The key never changes.',
    dur_deactivate: 'Archive', dur_activate: 'Reactivate', dur_impact_deactivate: 'The duration disappears from the choices for new plans. Existing plans and subscriptions keep working and keep their dates.',
    dur_impact_activate: 'The duration becomes selectable for new plans again.', dur_affected_plans: 'Active plans using this duration', dur_confirm_affected: 'I understand: existing plans and subscriptions are not changed.',
    dur_save: 'Save changes', dur_same_interval: 'Another duration may share this interval only with a different name.',
    dur_manage_link: 'Manage durations', dur_select: 'Duration', dur_no_durations: 'No active durations. Add one first.', dur_current_archived: '(archived)',
    dur_plan_edit: 'Edit plan', dur_plan_locked: 'This plan already has subscriptions, so its duration is locked. Create a new plan to use another duration.', dur_plan_unlocked: 'No subscription uses this plan yet, so its duration can change.',
    dur_plan_save: 'Save plan', dur_plan_impact: 'Existing subscriptions are never changed by a plan edit.',
};

export const durationsAr = {
    dur_nav: 'مدد الاشتراك', dur_title: 'مدد الاشتراك', dur_intro: 'عرّف أنواع الفترات وأطوالها التي يمكن للخطط استخدامها. تبقى المدد المؤرشفة صالحة للخطط والاشتراكات التي تستخدمها بالفعل.',
    dur_category_trial: 'فترة تجريبية', dur_category_test: 'فترة اختبار / تقييم', dur_category_paid: 'اشتراك مدفوع', dur_category: 'النوع',
    dur_unit_day: 'أيام', dur_unit_week: 'أسابيع', dur_unit_month: 'أشهر', dur_unit_year: 'سنوات', dur_unit_lifetime: 'مدى الحياة', dur_unit: 'الوحدة', dur_value: 'القيمة',
    dur_key: 'المفتاح الثابت', dur_key_hint: 'اختياري. حروف إنجليزية صغيرة وأرقام وشرطة سفلية. لا يتغير بعد الإنشاء.',
    dur_name_ar: 'الاسم بالعربية', dur_name_en: 'الاسم بالإنجليزية', dur_desc_ar: 'نص المساعدة بالعربية', dur_desc_en: 'نص المساعدة بالإنجليزية', dur_order: 'ترتيب العرض',
    dur_system: 'نظامية (محمية)', dur_custom: 'مخصصة', dur_kind: 'التصنيف', dur_plans: 'الخطط (نشطة / الكل)', dur_subscriptions: 'الاشتراكات', dur_interval: 'الفترة',
    dur_create: 'إضافة مدة', dur_create_submit: 'إضافة المدة', dur_created: 'تمت إضافة المدة.', dur_edit: 'تعديل', dur_search: 'بحث في المدد', dur_all_categories: 'كل الأنواع', dur_all_statuses: 'كل الحالات',
    dur_status_active: 'نشطة', dur_status_inactive: 'مؤرشفة', dur_empty: 'لا توجد مدد مطابقة للتصفية.',
    dur_preview: 'معاينة', dur_expiry_rule: 'الانتهاء = تاريخ البدء + {summary}.', dur_no_expiry: 'بلا تاريخ انتهاء.',
    dur_effect_trial: 'ترى الشركات «تنتهي الفترة التجريبية خلال …» ونص الفترة التجريبية في البريد.', dur_effect_test: 'ترى الشركات «تنتهي فترة الاختبار خلال …» ونص فترة الاختبار في البريد.',
    dur_effect_paid: 'ترى الشركات «ينتهي اشتراكك خلال …» ضمن نافذة التنبيه قبل تاريخ الانتهاء.', dur_effect_lifetime: 'بلا انتهاء وبلا تنبيهات انتهاء.',
    dur_immutable_system: 'مدة نظامية محمية: لا يمكن تغيير النوع أو الوحدة أو القيمة.', dur_immutable_referenced: 'توجد خطط تستخدم هذه المدة، لذلك النوع والوحدة والقيمة مقفلة. أضف مدة جديدة بدلًا من ذلك.',
    dur_editable_note: 'هذه المدة غير مستخدمة بعد، لذلك يمكن تغيير فترتها. المفتاح لا يتغير أبدًا.',
    dur_deactivate: 'أرشفة', dur_activate: 'إعادة التفعيل', dur_impact_deactivate: 'تختفي المدة من خيارات الخطط الجديدة. تستمر الخطط والاشتراكات القائمة وتحتفظ بتواريخها.',
    dur_impact_activate: 'تصبح المدة قابلة للاختيار للخطط الجديدة مرة أخرى.', dur_affected_plans: 'الخطط النشطة التي تستخدم هذه المدة', dur_confirm_affected: 'أفهم ذلك: لا تتغير الخطط والاشتراكات القائمة.',
    dur_save: 'حفظ التعديلات', dur_same_interval: 'يمكن لمدة أخرى مشاركة هذه الفترة بشرط اسم مختلف.',
    dur_manage_link: 'إدارة المدد', dur_select: 'المدة', dur_no_durations: 'لا توجد مدد نشطة. أضف مدة أولًا.', dur_current_archived: '(مؤرشفة)',
    dur_plan_edit: 'تعديل الخطة', dur_plan_locked: 'لهذه الخطة اشتراكات قائمة، لذلك مدتها مقفلة. أنشئ خطة جديدة لاستخدام مدة أخرى.', dur_plan_unlocked: 'لا يستخدم أي اشتراك هذه الخطة بعد، لذلك يمكن تغيير مدتها.',
    dur_plan_save: 'حفظ الخطة', dur_plan_impact: 'تعديل الخطة لا يغيّر الاشتراكات القائمة أبدًا.',
};
