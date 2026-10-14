const DATE_ONLY = /^(\d{4})-(\d{2})-(\d{2})/;

const intlLocale = (locale) => (locale === 'ar' ? 'ar-EG' : 'en-GB');

/** Date-only values never show a time and never show an ISO string. Returns '—' for empty values. */
export function formatDate(value, locale = 'en') {
    if (!value) return '—';
    const match = DATE_ONLY.exec(String(value));
    if (!match) return '—';
    const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
    if (Number.isNaN(date.getTime())) return '—';

    return new Intl.DateTimeFormat(intlLocale(locale), { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'UTC' }).format(date);
}

/** For real timestamps (created_at, approved_at) where the time is meaningful. */
export function formatDateTime(value, locale = 'en') {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return formatDate(value, locale);

    return new Intl.DateTimeFormat(intlLocale(locale), { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }).format(date);
}

export function formatMoney(value, locale = 'en', fraction = 2) {
    return Number(value ?? 0).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', { minimumFractionDigits: fraction, maximumFractionDigits: fraction });
}

/** Localized account name with a graceful fallback chain. */
export function accountName(account, locale = 'en') {
    if (!account) return '';
    const primary = locale === 'ar' ? account.name_ar : account.name_en;
    const secondary = locale === 'ar' ? account.name_en : account.name_ar;

    return primary || secondary || account.name || '';
}

export function accountLabel(account, locale = 'en') {
    if (!account) return '—';

    return [account.code, accountName(account, locale)].filter(Boolean).join(' · ');
}

/** Party name, or "code · name" of the other account. Never an id. */
export function counterpartyLabel(document, locale = 'en') {
    if (document?.party?.name) return document.party.name;
    if (document?.other_account || document?.otherAccount) return accountLabel(document.other_account ?? document.otherAccount, locale);

    return '—';
}
