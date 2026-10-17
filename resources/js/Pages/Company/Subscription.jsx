import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import CompanyLayout from '../../Layouts/CompanyLayout';
import SubscriptionNotice from '../../Components/SubscriptionNotice';
import { translate } from '../../i18n';
import { formatDate } from '../../format';

export default function Subscription({ subscription }) {
    const { locale, subscriptionNotice: notice } = usePage().props;
    const t = (key) => translate(locale, key);
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });
    const [feedback, setFeedback] = useState(null);
    const [busy, setBusy] = useState(null);
    const state = notice?.state ?? null;
    const lifetime = notice?.category === 'lifetime';
    const blockedByAdmin = ['suspended', 'archived'].includes(state) && ['suspended_admin', 'archived_admin'].includes(notice?.key);

    function send(kind) {
        if (busy) return;
        setBusy(kind);
        setFeedback(null);
        router.post(`/subscription/${kind}`, {}, {
            preserveScroll: true,
            onSuccess: () => setFeedback({ type: 'success', text: t('sub_request_sent') }),
            onError: () => setFeedback({ type: 'error', text: t('sub_request_failed') }),
            onFinish: () => setBusy(null),
        });
    }

    return (
        <CompanyLayout title={t('subscriptionStatus')}>
            <div className="erp-page-header"><div><h1>{t('subscriptionStatus')}</h1></div></div>
            <SubscriptionNotice notice={notice} locale={locale} onSubscriptionPage />
            {feedback && <div role={feedback.type === 'error' ? 'alert' : 'status'} className={`notice-card ${feedback.type}`}>{feedback.text}</div>}
            {notice && <section className="erp-section" aria-labelledby="subscription-details">
                <h2 id="subscription-details">{t('sub_details')}</h2>
                <dl className="impact-list">
                    {subscription?.plan?.name && <div><dt>{t('sub_plan')}</dt><dd>{subscription.plan.name}</dd></div>}
                    {notice.category && <div><dt>{t('sub_type')}</dt><dd>{t(`sub_category_${notice.category}`)}</dd></div>}
                    <div><dt>{t('sub_status')}</dt><dd>{t(`sub_state_${notice.state}`)}</dd></div>
                    <div><dt>{t('sub_ends_on')}</dt><dd>{notice.ends_on ? formatDate(notice.ends_on, locale) : t('sub_no_end')}</dd></div>
                </dl>
                {lifetime && <p className="permission-note">{t('sub_lifetime_note')}</p>}
                {notice.ends_on && <p className="permission-note">{t('sub_timezone')}: {notice.timezone}</p>}
            </section>}
            {!lifetime && !blockedByAdmin && notice && <section className="erp-section" aria-labelledby="subscription-actions">
                <h2 id="subscription-actions">{t('sub_actions')}</h2>
                <div className="erp-toolbar">
                    <button type="button" className="erp-button erp-button--primary" disabled={busy !== null} onClick={() => send('renew')}>{t('sub_request_renewal')}</button>
                    <button type="button" className="erp-button erp-button--secondary" disabled={busy !== null} onClick={() => send('contact')}>{t('sub_contact_support')}</button>
                </div>
            </section>}
            {blockedByAdmin && <section className="erp-section">
                <div className="erp-toolbar"><button type="button" className="erp-button erp-button--secondary" disabled={busy !== null} onClick={() => send('contact')}>{t('sub_contact_support')}</button></div>
            </section>}
            {!['suspended', 'archived'].includes(state) && <section className="erp-section" style={{ maxInlineSize: '36rem' }}>
                <h2>{t('changePassword')}</h2>
                <form onSubmit={(event) => { event.preventDefault(); form.post('/password'); }} className="account-edit-form">
                    <input aria-label={t('currentPassword')} type="password" placeholder={t('currentPassword')} value={form.data.current_password} onChange={(event) => form.setData('current_password', event.target.value)} />
                    <input aria-label={t('newPassword')} type="password" placeholder={t('newPassword')} value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} />
                    <input aria-label={t('confirmPassword')} type="password" placeholder={t('confirmPassword')} value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} />
                    <button type="submit" className="erp-button erp-button--primary">{t('updatePassword')}</button>
                </form>
            </section>}
        </CompanyLayout>
    );
}
