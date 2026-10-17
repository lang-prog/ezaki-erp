// Pure helpers for the platform plan-change flow; no React so they can be unit-tested directly.
export const sameId = (a, b) => a !== null && a !== undefined && a !== '' && b !== null && b !== undefined && b !== '' && String(a) === String(b);

export const findPlan = (plans, id) => (Array.isArray(plans) ? plans.find((plan) => sameId(plan?.id, id)) ?? null : null);

const isRecord = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

/** Coerces a preview payload into a shape the UI can render without optional chaining everywhere. */
export function normalizePlanPreview(data) {
    if (!isRecord(data)) return null;
    const usage = isRecord(data.usage) ? Object.fromEntries(Object.entries(data.usage).filter(([, row]) => isRecord(row)).map(([key, row]) => [key, { usage: row.usage ?? 0, limit: row.limit ?? null }])) : {};

    return {
        from: data.from ?? null,
        to: data.to ?? null,
        ends_at: data.ends_at ?? null,
        same_plan: Boolean(data.same_plan),
        inactive: Boolean(data.inactive),
        usage,
        over_limit: Array.isArray(data.over_limit) ? data.over_limit : [],
    };
}

/**
 * Single source of truth for what the change-plan dialog may show.
 * kind: empty | choose | stale | loading | error | ready ; blocked disables the confirm button.
 */
export function planChangeView({ planId, plans, preview }) {
    const available = Array.isArray(plans) ? plans : [];
    if (available.length === 0) return { kind: 'empty', plan: null, info: null, blocked: true };
    if (planId === '' || planId === null || planId === undefined) return { kind: 'choose', plan: null, info: null, blocked: true };
    const plan = findPlan(available, planId);
    if (!plan) return { kind: 'stale', plan: null, info: null, blocked: true };
    if (!preview || preview.loading) return { kind: 'loading', plan, info: null, blocked: true };
    if (preview.error) return { kind: 'error', plan, info: null, blocked: true, status: preview.status ?? null };
    const info = normalizePlanPreview(preview.data);
    if (!info) return { kind: 'error', plan, info: null, blocked: true, status: null };

    return { kind: 'ready', plan, info, blocked: info.same_plan || info.inactive || info.over_limit.length > 0 };
}

/** Maps an HTTP status to a localized message key so no response is ever rendered as a page. */
export function failureKey(status) {
    if (status === 403) return 'pf_err_403';
    if (status === 409) return 'pf_err_409';
    if (status === 419) return 'pf_err_419';
    if (status === 422) return 'pf_err_422';
    if (status === 423) return 'pf_err_423';
    if (status === 429) return 'pf_err_429';
    if (typeof status === 'number' && status >= 500) return 'pf_err_500';

    return status ? 'pf_error_generic' : 'pf_err_network';
}
