import { Link } from '@inertiajs/react';
import { translate } from '../i18n';
import { subscriptionNoticeMessage } from '../i18n-subscription';

const SEVERITY = {
    info: { tone: 'success', icon: 'i' },
    warning: { tone: 'warning', icon: '▲' },
    danger: { tone: 'error', icon: '✕' },
};

/**
 * Server-authored subscription notice. The text always comes from the notice key/params the server derived
 * from the authoritative subscription; with no notice, a loading state or a failed load it never claims a count.
 */
export default function SubscriptionNotice({ notice, locale, loading = false, failed = false, canView = false, onSubscriptionPage = false, actions = null }) {
    const t = (key) => translate(locale, key);
    if (loading) return <div className="notice-card subscription-notice" role="status" aria-busy="true">{t('sub_loading')}</div>;
    const effective = failed ? { show: true, key: 'unavailable', severity: 'warning', category: null, state: 'active' } : notice;
    const message = subscriptionNoticeMessage(locale, effective);
    if (!message) return null;
    const { tone, icon } = SEVERITY[effective.severity] ?? SEVERITY.info;
    const badge = effective.category && effective.category !== 'lifetime' ? t(`sub_category_${effective.category}`) : null;
    const stateLabel = effective.state && effective.state !== 'active' && effective.state !== 'none' ? t(`sub_state_${effective.state}`) : null;

    return <div className={`notice-card ${tone} subscription-notice`} role={tone === 'error' ? 'alert' : 'status'} aria-label={t('sub_notice_label')} data-subscription-state={effective.state}>
        <span className="subscription-notice__badge"><span aria-hidden="true">{icon}</span> {stateLabel ?? badge ?? t('sub_notice_label')}</span>
        <span className="subscription-notice__text">{message}</span>
        {(canView && !onSubscriptionPage) && <Link className="subscription-notice__action erp-link" href="/subscription">{t('sub_view')}</Link>}
        {actions}
    </div>;
}
