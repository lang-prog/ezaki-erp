import { Link, useForm, usePage } from '@inertiajs/react';
import { translate } from '../../i18n';

export default function ForgotPassword() {
    const { locale, errors, flash } = usePage().props;
    const t = (key) => translate(locale, key);
    const form = useForm({ email: '' });
    return <main className="auth-simple-page" dir={locale === 'ar' ? 'rtl' : 'ltr'}><section className="auth-simple-card"><Link href="/login" className="brand auth-simple-brand"><span className="brand-mark" aria-hidden="true">◆</span><span><strong>E‑Zaki</strong><small>ERP / BUSINESS OS</small></span></Link><p className="eyebrow">{t('companySignIn')}</p><h1>{t('forgotPassword')}</h1><p className="auth-simple-copy">{t('forgotPasswordIntro')}</p>{flash?.status && <div className="notice-card success" role="status">{t('resetLinkSent')}</div>}{errors?.email && <div className="notice-card error" role="alert">{errors.email}</div>}<form onSubmit={(event) => { event.preventDefault(); form.post('/forgot-password'); }} className="auth-simple-form"><label className="field"><span>{t('email')}</span><input required type="email" autoComplete="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} /></label><button className="primary-button" disabled={form.processing}>{form.processing ? t('saving') : t('sendResetLink')}</button></form><Link href="/login" className="auth-back-link">{t('backToLogin')}</Link></section></main>;
}
