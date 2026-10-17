// Company subscription notices. The server sends a stable key + integer params; wording and plurals live here.
const DAY_FORMS = {
    en: { one: '{count} day', other: '{count} days' },
    ar: { one: 'يوم واحد', two: 'يومين', few: '{count} أيام', many: '{count} يومًا', other: '{count} يوم' },
};

const LESS_THAN_DAY = { en: 'less than one day', ar: 'أقل من يوم واحد' };

const MESSAGES = {
    en: {
        'trial.ends': 'Your trial period ends in {days}.',
        'trial.today': 'Your trial period ends in less than one day.',
        'trial.grace': 'Your trial period has ended and a grace period applies. Full access continues for {days}.',
        'trial.grace_today': 'Your trial period has ended and a grace period applies. Full access continues for less than one day.',
        'trial.read_only': 'Your trial period has ended and the system is currently in read-only mode.',
        'trial.expired': 'Your trial period ended {days} ago.',
        'trial.expired_today': 'Your trial period ended today.',
        'test.ends': 'Your test period ends in {days}.',
        'test.today': 'Your test period ends in less than one day.',
        'test.grace': 'Your test period has ended and a grace period applies. Full access continues for {days}.',
        'test.grace_today': 'Your test period has ended and a grace period applies. Full access continues for less than one day.',
        'test.read_only': 'Your test period has ended and the system is currently in read-only mode.',
        'test.expired': 'Your test period ended {days} ago.',
        'test.expired_today': 'Your test period ended today.',
        'paid.ends': 'Your subscription ends in {days}.',
        'paid.today': 'Your subscription ends in less than one day.',
        'paid.grace': 'Your subscription has expired and a grace period applies. Full access continues for {days}.',
        'paid.grace_today': 'Your subscription has expired and a grace period applies. Full access continues for less than one day.',
        'paid.read_only': 'Your subscription has expired and the system is currently in read-only mode.',
        'paid.expired': 'Your subscription expired {days} ago.',
        'paid.expired_today': 'Your subscription expired today.',
        suspended_admin: 'Your company has been suspended. Contact support to restore access.',
        archived_admin: 'Your company has been archived. Contact support to restore access.',
        cancelled: 'Your subscription has been cancelled. Contact support to continue.',
        unavailable: 'The subscription status is unavailable right now. Try again later.',
    },
    ar: {
        'trial.ends': 'تنتهي الفترة التجريبية خلال {days}.',
        'trial.today': 'تنتهي الفترة التجريبية خلال أقل من يوم واحد.',
        'trial.grace': 'انتهت الفترة التجريبية، وتوجد فترة سماح يستمر خلالها الوصول الكامل لمدة {days}.',
        'trial.grace_today': 'انتهت الفترة التجريبية، وتوجد فترة سماح يستمر خلالها الوصول الكامل لمدة أقل من يوم واحد.',
        'trial.read_only': 'انتهت الفترة التجريبية، والنظام يعمل حاليًا في وضع القراءة فقط.',
        'trial.expired': 'انتهت الفترة التجريبية منذ {days}.',
        'trial.expired_today': 'انتهت الفترة التجريبية اليوم.',
        'test.ends': 'تنتهي فترة الاختبار خلال {days}.',
        'test.today': 'تنتهي فترة الاختبار خلال أقل من يوم واحد.',
        'test.grace': 'انتهت فترة الاختبار، وتوجد فترة سماح يستمر خلالها الوصول الكامل لمدة {days}.',
        'test.grace_today': 'انتهت فترة الاختبار، وتوجد فترة سماح يستمر خلالها الوصول الكامل لمدة أقل من يوم واحد.',
        'test.read_only': 'انتهت فترة الاختبار، والنظام يعمل حاليًا في وضع القراءة فقط.',
        'test.expired': 'انتهت فترة الاختبار منذ {days}.',
        'test.expired_today': 'انتهت فترة الاختبار اليوم.',
        'paid.ends': 'ينتهي اشتراكك خلال {days}.',
        'paid.today': 'ينتهي اشتراكك خلال أقل من يوم واحد.',
        'paid.grace': 'انتهى الاشتراك، وتوجد فترة سماح يستمر خلالها الوصول الكامل لمدة {days}.',
        'paid.grace_today': 'انتهى الاشتراك، وتوجد فترة سماح يستمر خلالها الوصول الكامل لمدة أقل من يوم واحد.',
        'paid.read_only': 'انتهى الاشتراك، والنظام يعمل حاليًا في وضع القراءة فقط.',
        'paid.expired': 'انتهى الاشتراك منذ {days}.',
        'paid.expired_today': 'انتهى الاشتراك اليوم.',
        suspended_admin: 'تم إيقاف الشركة. تواصل مع الدعم لاستعادة الوصول.',
        archived_admin: 'تمت أرشفة الشركة. تواصل مع الدعم لاستعادة الوصول.',
        cancelled: 'تم إلغاء الاشتراك. تواصل مع الدعم للمتابعة.',
        unavailable: 'حالة الاشتراك غير متاحة الآن. حاول مرة أخرى لاحقًا.',
    },
};

const lang = (locale) => (locale === 'ar' ? 'ar' : 'en');

export function pluralCategory(locale, count) {
    return new Intl.PluralRules(lang(locale)).select(count);
}

/** "3 days" / "يومين" / "11 يومًا" with the right grammatical form for the count. */
export function dayPhrase(locale, count) {
    const forms = DAY_FORMS[lang(locale)];
    const whole = Number.isInteger(count) && count >= 0 ? count : 0;
    const form = forms[pluralCategory(locale, whole)] ?? forms.other;

    return form.replace('{count}', String(whole));
}

/** Localized sentence for a server notice, or null when nothing should be shown. */
export function subscriptionNoticeMessage(locale, notice) {
    if (!notice || notice.show !== true || typeof notice.key !== 'string') return null;
    const template = MESSAGES[lang(locale)][notice.key];
    if (!template) return MESSAGES[lang(locale)].unavailable;
    if (!template.includes('{days}')) return template;
    const days = notice.params?.days;
    if (!Number.isInteger(days) || days < 1) return MESSAGES[lang(locale)].unavailable;

    return template.replace('{days}', dayPhrase(locale, days));
}

export const lessThanOneDay = (locale) => LESS_THAN_DAY[lang(locale)];

export const subscriptionEn = {
    sub_category_trial: 'Trial period', sub_category_test: 'Test period', sub_category_paid: 'Paid subscription', sub_category_lifetime: 'Lifetime subscription',
    sub_state_active: 'Active', sub_state_grace: 'Grace period', sub_state_read_only: 'Read-only', sub_state_suspended: 'Suspended', sub_state_archived: 'Archived', sub_state_cancelled: 'Cancelled',
    sub_view: 'View subscription', sub_request_renewal: 'Request renewal', sub_contact_support: 'Contact support', sub_request_sent: 'Your request was sent to the platform team.', sub_request_failed: 'The request could not be sent. Try again.',
    sub_plan: 'Plan', sub_type: 'Type', sub_status: 'Status', sub_ends_on: 'End date', sub_no_end: 'No end date', sub_timezone: 'Calendar days follow this timezone', sub_loading: 'Loading subscription status…',
    sub_lifetime_note: 'This is a lifetime subscription with no end date.', sub_notice_label: 'Subscription notice', sub_details: 'Subscription details', sub_actions: 'Need to continue?',
};

export const subscriptionAr = {
    sub_category_trial: 'فترة تجريبية', sub_category_test: 'فترة اختبار', sub_category_paid: 'اشتراك مدفوع', sub_category_lifetime: 'اشتراك مدى الحياة',
    sub_state_active: 'نشط', sub_state_grace: 'فترة سماح', sub_state_read_only: 'قراءة فقط', sub_state_suspended: 'موقوف', sub_state_archived: 'مؤرشف', sub_state_cancelled: 'ملغى',
    sub_view: 'عرض الاشتراك', sub_request_renewal: 'طلب التجديد', sub_contact_support: 'التواصل مع الدعم', sub_request_sent: 'تم إرسال طلبك إلى فريق المنصة.', sub_request_failed: 'تعذر إرسال الطلب. حاول مرة أخرى.',
    sub_plan: 'الخطة', sub_type: 'النوع', sub_status: 'الحالة', sub_ends_on: 'تاريخ الانتهاء', sub_no_end: 'بلا تاريخ انتهاء', sub_timezone: 'تُحسب الأيام التقويمية وفق هذه المنطقة الزمنية', sub_loading: 'جارٍ تحميل حالة الاشتراك…',
    sub_lifetime_note: 'هذا اشتراك مدى الحياة بلا تاريخ انتهاء.', sub_notice_label: 'تنبيه الاشتراك', sub_details: 'تفاصيل الاشتراك', sub_actions: 'هل تحتاج إلى المتابعة؟',
};
