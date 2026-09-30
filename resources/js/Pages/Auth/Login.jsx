import { Link, useForm, usePage } from '@inertiajs/react';
import { translate } from '../../i18n';

export default function Login({ accountType }) {
    const { errors, registrationOpen, locale } = usePage().props;
    const form = useForm({ email: '', password: '', remember: false });
    const platform = accountType === 'super_admin';
    const t = (key) => translate(locale, key);
    function submit(event) { event.preventDefault(); form.post(platform ? '/super-admin/login' : '/login'); }
    return <main className="login-page" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
        <section className="login-decoration" aria-label={platform ? t('platformSignIn') : t('companySignIn')}>
            <div className="login-copy"><Link href="/" className="brand login-brand"><span className="brand-mark" aria-hidden="true">◆</span><span><strong>E‑Zaki</strong><small>ERP / BUSINESS OS</small></span></Link><p className="login-kicker">{platform ? t('platformAdmin') : t('companyWorkspace')}</p><h1>{platform ? 'Platform control.' : 'إدارة أعمالك بثقة.'}</h1><p>{t(platform ? 'platformSignInCopy' : 'companySignInCopy')}</p><div className="login-proof"><span aria-hidden="true">✓</span><span>{platform ? 'إدارة آمنة للشركات والاشتراكات' : 'مساحة عمل آمنة وموحدة لشركتك'}</span></div></div>
        </section>
        <form className="login-card" onSubmit={submit} aria-labelledby="login-title">
            <div className="login-card-head"><div className="eyebrow">● {platform ? t('platformSignIn') : t('companySignIn')}</div><h2 id="login-title">{t('signIn')}</h2><p>{t(platform ? 'platformSignInCopy' : 'companySignInCopy')}</p></div>
            <label className="field" htmlFor="login-email"><span>{t('email')}</span><div className="field-control"><input id="login-email" name="email" required autoComplete="username" type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} aria-invalid={Boolean(errors.email)} /></div></label>
            {errors.email && <div className="login-error" role="alert">{errors.email}</div>}
            <label className="field" htmlFor="login-password"><span>{t('password')}</span><div className="field-control"><input id="login-password" name="password" required autoComplete="current-password" type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} aria-invalid={Boolean(errors.password)} /></div></label>
            {errors.password && <div className="login-error" role="alert">{errors.password}</div>}
            <div className="login-options"><label><input id="remember" name="remember" type="checkbox" checked={form.data.remember} onChange={(event) => form.setData('remember', event.target.checked)} /> <span>{t('rememberMe')}</span></label><span>{locale === 'ar' ? 'دخول محمي' : 'Secure sign in'}</span></div>
            <button disabled={form.processing} className="primary-button login-button" type="submit">{form.processing ? (locale === 'ar' ? 'جارٍ التحقق…' : 'Signing in…') : t('signIn')} <span aria-hidden="true">←</span></button>
            {form.recentlySuccessful && <p className="api-feedback" role="status">{locale === 'ar' ? 'تم تسجيل الدخول.' : 'Signed in successfully.'}</p>}
            {!platform && registrationOpen && <small className="login-foot">{t('newCompany')} <Link href="/register">{t('createRequest')}</Link></small>}
        </form>
    </main>;
}
