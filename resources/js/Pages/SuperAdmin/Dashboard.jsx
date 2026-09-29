import { useForm, usePage } from '@inertiajs/react';
import SuperAdminLayout from '../../Layouts/SuperAdminLayout';
import { translate } from '../../i18n';

export default function Dashboard({ registrationOpen, registrations = [], plans = [], subscriptions = [], companies = { data: [], links: [] } }) {
    const { locale } = usePage().props;
    const t = (key) => translate(locale, key);
    const registrationForm = useForm({ enabled: registrationOpen });
    const companyForm = useForm({ name: '', trade_name: '', tax_number: '', phone: '', country: '', city: '', address: '', owner_name: '', owner_email: '', owner_password: '', owner_password_confirmation: '', plan_id: '', coupon_code: '', notes: '' });
    const planForm = useForm({ code: '', name: '', duration: 'monthly', duration_days: '', price: '0', limits: { users: '', branches: '', warehouses: '' }, features: {} });
    const couponForm = useForm({ code: '', discount_type: 'percent', discount_value: '', max_redemptions: '', starts_at: '', ends_at: '' });

    function submit(event, form, url) {
        event.preventDefault();
        form.post(url, { preserveScroll: true, onSuccess: () => form.reset() });
    }

    function postCompany(companyId, action, data = {}) {
        window.axios.post(`/super-admin/companies/${companyId}/${action}`, data).then(() => window.location.reload());
    }

    function updateAddons(companyId, event) {
        event.preventDefault();
        const formData = new FormData(event.currentTarget);
        window.axios.put(`/super-admin/companies/${companyId}/limit-addons`, Object.fromEntries(formData.entries())).then(() => window.location.reload());
    }

    return (
        <SuperAdminLayout title="Platform overview">
            <h1 className="text-2xl font-semibold">{t('platformOverview')}</h1>
            <section className="mt-8 border-y border-[#ded8cf] py-5">
                <h2 className="font-semibold">{t('selfRegistration')}</h2>
                <form onSubmit={(event) => submit(event, registrationForm, '/super-admin/registration-setting')} className="mt-3 flex items-center gap-4">
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={registrationForm.data.enabled} onChange={(event) => registrationForm.setData('enabled', event.target.checked)} />{t('openRequests')}</label>
                    <button className="border border-[#aaa197] px-3 py-2 text-sm">{t('save')}</button>
                </form>
            </section>
            <section id="registrations" className="mt-8">
                <h2 className="font-semibold">{t('registrationRequests')}</h2>
                <div className="mt-3 divide-y divide-[#ded8cf] border-y border-[#ded8cf]">
                    {registrations.filter((request) => request.status === 'pending').map((request) => <div key={request.id} className="flex flex-wrap items-center justify-between gap-3 py-4">
                        <div><p className="font-medium">{request.company_name}</p><p className="text-sm text-[#68645f]">{request.owner_name} · {request.email}</p></div>
                        <div className="flex gap-2"><button onClick={() => window.confirm('Approve this verified registration?') && window.axios.post(`/super-admin/registrations/${request.id}/approve`).then(() => window.location.reload())} className="bg-[#34795c] px-3 py-2 text-sm text-white">{t('approve')}</button><button onClick={() => window.axios.post(`/super-admin/registrations/${request.id}/reject`).then(() => window.location.reload())} className="border border-[#b7aaa0] px-3 py-2 text-sm">{t('reject')}</button></div>
                    </div>)}
                    {!registrations.some((request) => request.status === 'pending') && <p className="py-4 text-sm text-[#68645f]">{t('noPending')}</p>}
                </div>
            </section>
            <section id="companies" className="mt-8">
                <h2 className="font-semibold">Create company manually</h2>
                <form onSubmit={(event) => submit(event, companyForm, '/super-admin/companies')} className="mt-3 grid gap-3 border-y border-[#ded8cf] py-5 sm:grid-cols-2 lg:grid-cols-3">
                    {[
                        ['name', 'Company name'], ['trade_name', 'Trade name'], ['tax_number', 'Tax number'],
                        ['phone', 'Phone'], ['country', 'Country'], ['city', 'City'],
                        ['owner_name', 'Owner name'], ['owner_email', 'Owner email'],
                    ].map(([name, label]) => <label key={name} className="text-xs font-medium text-[#68645f]">{label}<input required={['name', 'owner_name', 'owner_email'].includes(name)} type={name === 'owner_email' ? 'email' : 'text'} value={companyForm.data[name]} onChange={(event) => companyForm.setData(name, event.target.value)} className="mt-1 w-full border border-[#c8c0b7] bg-white px-3 py-2 text-sm" />{companyForm.errors[name] && <span className="mt-1 block text-red-700">{companyForm.errors[name]}</span>}</label>)}
                    <label className="text-xs font-medium text-[#68645f]">Owner password<input required minLength="8" autoComplete="new-password" type="password" value={companyForm.data.owner_password} onChange={(event) => companyForm.setData('owner_password', event.target.value)} className="mt-1 w-full border border-[#c8c0b7] bg-white px-3 py-2 text-sm" />{companyForm.errors.owner_password && <span className="mt-1 block text-red-700">{companyForm.errors.owner_password}</span>}</label>
                    <label className="text-xs font-medium text-[#68645f]">Confirm owner password<input required minLength="8" autoComplete="new-password" type="password" value={companyForm.data.owner_password_confirmation} onChange={(event) => companyForm.setData('owner_password_confirmation', event.target.value)} className="mt-1 w-full border border-[#c8c0b7] bg-white px-3 py-2 text-sm" /></label>
                    <label className="text-xs font-medium text-[#68645f]">Plan<select required value={companyForm.data.plan_id} onChange={(event) => companyForm.setData('plan_id', event.target.value)} className="mt-1 w-full border border-[#c8c0b7] bg-white px-3 py-2 text-sm"><option value="">Select a plan</option>{plans.filter((plan) => plan.is_active).map((plan) => <option key={plan.id} value={plan.id}>{plan.name} · {plan.duration} · {plan.price} EGP</option>)}</select>{companyForm.errors.plan_id && <span className="mt-1 block text-red-700">{companyForm.errors.plan_id}</span>}</label>
                    <label className="text-xs font-medium text-[#68645f]">Coupon code (optional)<input value={companyForm.data.coupon_code} onChange={(event) => companyForm.setData('coupon_code', event.target.value)} className="mt-1 w-full border border-[#c8c0b7] bg-white px-3 py-2 text-sm" />{companyForm.errors.coupon_code && <span className="mt-1 block text-red-700">{companyForm.errors.coupon_code}</span>}</label>
                    <label className="text-xs font-medium text-[#68645f] sm:col-span-2 lg:col-span-3">Address<textarea rows="2" value={companyForm.data.address} onChange={(event) => companyForm.setData('address', event.target.value)} className="mt-1 w-full border border-[#c8c0b7] bg-white px-3 py-2 text-sm" /></label>
                    <label className="text-xs font-medium text-[#68645f] sm:col-span-2 lg:col-span-3">Admin notes<textarea rows="2" value={companyForm.data.notes} onChange={(event) => companyForm.setData('notes', event.target.value)} className="mt-1 w-full border border-[#c8c0b7] bg-white px-3 py-2 text-sm" /></label>
                    <button disabled={companyForm.processing} className="bg-[#34795c] px-4 py-2.5 text-sm text-white disabled:opacity-60 lg:col-span-3">Create company and owner</button>
                    {companyForm.errors.owner_email && <p className="text-sm text-red-700 lg:col-span-3">{companyForm.errors.owner_email}</p>}
                </form>
            </section>
            <section id="plans" className="mt-8">
                <h2 className="font-semibold">Companies</h2>
                <div className="mt-3 divide-y divide-[#ded8cf] border-y border-[#ded8cf]">
                    {companies.data.map((company) => {
                        const subscription = company.subscription;
                        const additions = subscription?.overrides?.limit_addons ?? {};
                        return <article key={company.id} className="py-5">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div><p className="font-medium">{company.name}</p><p className="mt-1 text-sm text-[#68645f]">{company.status} · {subscription?.plan?.name ?? 'No plan'} · {subscription?.ends_at ?? (subscription ? 'Lifetime' : 'No subscription')}</p></div>
                                <div className="flex flex-wrap gap-2">
                                    {company.status === 'archived' ? <button onClick={() => postCompany(company.id, 'restore')} className="border border-[#a64b36] px-3 py-2 text-sm">Restore</button> : <>
                                        {company.status !== 'active' && <button onClick={() => postCompany(company.id, 'activate')} className="border border-[#34795c] px-3 py-2 text-sm">Activate</button>}
                                        {company.status === 'active' && <button onClick={() => postCompany(company.id, 'suspend')} className="border border-[#a64b36] px-3 py-2 text-sm">Suspend</button>}
                                        <button onClick={() => window.confirm(`Archive ${company.name}?`) && postCompany(company.id, 'archive')} className="border border-[#aaa197] px-3 py-2 text-sm">Archive</button>
                                    </>}
                                </div>
                            </div>
                            {subscription && <div className="mt-4 grid gap-4 lg:grid-cols-2">
                                <form onSubmit={(event) => { event.preventDefault(); postCompany(company.id, 'plan', { plan_id: event.currentTarget.plan_id.value }); }} className="flex flex-wrap gap-2">
                                    <select name="plan_id" aria-label={`Plan for ${company.name}`} defaultValue={subscription.plan_id} className="min-w-40 border border-[#c8c0b7] bg-white px-2 py-2">{plans.map((plan) => <option key={plan.id} value={plan.id}>{plan.name} · {plan.duration}</option>)}</select>
                                    <button className="border border-[#a64b36] px-3 py-2 text-sm">Change plan</button>
                                </form>
                                <form onSubmit={(event) => { event.preventDefault(); postCompany(company.id, 'renew', { amount: event.currentTarget.amount.value, reference: event.currentTarget.reference.value }); }} className="flex flex-wrap gap-2">
                                    <input name="amount" aria-label="Renewal amount" type="number" min="0" step="0.01" required placeholder="Paid amount" className="w-32 border border-[#c8c0b7] bg-white px-2 py-2" />
                                    <input name="reference" aria-label="Renewal reference" placeholder="Reference" className="w-32 border border-[#c8c0b7] bg-white px-2 py-2" />
                                    <button className="bg-[#34795c] px-3 py-2 text-sm text-white">Renew</button>
                                </form>
                                <form onSubmit={(event) => updateAddons(company.id, event)} className="grid gap-2 sm:grid-cols-4 lg:col-span-2">
                                    <label className="text-xs text-[#68645f]">Extra users<input name="users" type="number" min="0" defaultValue={additions.users ?? 0} className="mt-1 w-full border border-[#c8c0b7] bg-white px-2 py-2 text-sm" /></label>
                                    <label className="text-xs text-[#68645f]">Extra branches<input name="branches" type="number" min="0" defaultValue={additions.branches ?? 0} className="mt-1 w-full border border-[#c8c0b7] bg-white px-2 py-2 text-sm" /></label>
                                    <label className="text-xs text-[#68645f]">Extra warehouses<input name="warehouses" type="number" min="0" defaultValue={additions.warehouses ?? 0} className="mt-1 w-full border border-[#c8c0b7] bg-white px-2 py-2 text-sm" /></label>
                                    <button className="self-end border border-[#a64b36] px-3 py-2 text-sm">Raise limits</button>
                                </form>
                            </div>}
                        </article>;
                    })}
                    {companies.data.length === 0 && <p className="py-4 text-sm text-[#68645f]">No companies registered.</p>}
                </div>
                <nav className="mt-3 flex gap-2 text-sm">{companies.links.map((link, index) => link.url ? <a key={index} href={link.url} className="border border-[#c8c0b7] px-2 py-1">{link.label.replace(/<[^>]*>/g, '').trim()}</a> : null)}</nav>
            </section>
            <section id="coupons" className="mt-8">
                <h2 className="font-semibold">{t('plans')}</h2>
                <div className="mt-3 divide-y divide-[#ded8cf] border-y border-[#ded8cf]">{plans.map((plan) => <p key={plan.id} className="py-3 text-sm">{plan.name} <span className="text-[#68645f]">· {plan.duration} · {plan.price} EGP</span></p>)}</div>
                <form onSubmit={(event) => submit(event, planForm, '/super-admin/plans')} className="mt-4 grid gap-3 border-b border-[#ded8cf] pb-6 sm:grid-cols-2 lg:grid-cols-5">
                    <input aria-label={t('planCode')} placeholder={t('planCode')} value={planForm.data.code} onChange={(event) => planForm.setData('code', event.target.value)} className="border border-[#c8c0b7] bg-white px-3 py-2" />
                    <input aria-label={t('planName')} placeholder={t('planName')} value={planForm.data.name} onChange={(event) => planForm.setData('name', event.target.value)} className="border border-[#c8c0b7] bg-white px-3 py-2" />
                    <select aria-label={t('duration')} value={planForm.data.duration} onChange={(event) => planForm.setData('duration', event.target.value)} className="border border-[#c8c0b7] bg-white px-3 py-2"><option value="monthly">{t('monthly')}</option><option value="six_months">{t('sixMonths')}</option><option value="yearly">{t('yearly')}</option><option value="trial">{t('trial')}</option><option value="lifetime">{t('lifetime')}</option></select>
                    <input aria-label={t('days')} type="number" min="1" placeholder={t('days')} value={planForm.data.duration_days} onChange={(event) => planForm.setData('duration_days', event.target.value)} className="border border-[#c8c0b7] bg-white px-3 py-2" />
                    <input aria-label={t('price')} type="number" min="0" step="0.01" placeholder={t('price')} value={planForm.data.price} onChange={(event) => planForm.setData('price', event.target.value)} className="border border-[#c8c0b7] bg-white px-3 py-2" />
                    <input aria-label={locale === 'ar' ? 'حد المستخدمين' : 'Active user limit'} type="number" min="1" placeholder={locale === 'ar' ? 'المستخدمون النشطون' : 'Active users'} value={planForm.data.limits.users} onChange={(event) => planForm.setData('limits', { ...planForm.data.limits, users: event.target.value })} className="border border-[#c8c0b7] bg-white px-3 py-2" />
                    <input aria-label={locale === 'ar' ? 'حد الفروع' : 'Branch limit'} type="number" min="1" placeholder={locale === 'ar' ? 'الفروع' : 'Branches'} value={planForm.data.limits.branches} onChange={(event) => planForm.setData('limits', { ...planForm.data.limits, branches: event.target.value })} className="border border-[#c8c0b7] bg-white px-3 py-2" />
                    <input aria-label={locale === 'ar' ? 'حد المخازن' : 'Warehouse limit'} type="number" min="1" placeholder={locale === 'ar' ? 'المخازن' : 'Warehouses'} value={planForm.data.limits.warehouses} onChange={(event) => planForm.setData('limits', { ...planForm.data.limits, warehouses: event.target.value })} className="border border-[#c8c0b7] bg-white px-3 py-2" />
                    <button className="bg-[#a64b36] px-3 py-2 text-white sm:col-span-2 lg:col-span-5">{t('createPlan')}</button>
                </form>
            </section>
            <section className="mt-8">
                <h2 className="font-semibold">{t('coupons')}</h2>
                <form onSubmit={(event) => submit(event, couponForm, '/super-admin/coupons')} className="mt-3 grid gap-3 border-b border-[#ded8cf] pb-6 sm:grid-cols-2 lg:grid-cols-5">
                    <input aria-label="Coupon code" placeholder="Code" value={couponForm.data.code} onChange={(event) => couponForm.setData('code', event.target.value)} className="border border-[#c8c0b7] bg-white px-3 py-2" />
                    <select aria-label="Discount type" value={couponForm.data.discount_type} onChange={(event) => couponForm.setData('discount_type', event.target.value)} className="border border-[#c8c0b7] bg-white px-3 py-2"><option value="percent">Percent</option><option value="fixed">Fixed EGP</option></select>
                    <input aria-label={t('discount')} type="number" min="0.01" step="0.01" placeholder={t('discount')} value={couponForm.data.discount_value} onChange={(event) => couponForm.setData('discount_value', event.target.value)} className="border border-[#c8c0b7] bg-white px-3 py-2" />
                    <input aria-label={t('maxUses')} type="number" min="1" placeholder={t('maxUses')} value={couponForm.data.max_redemptions} onChange={(event) => couponForm.setData('max_redemptions', event.target.value)} className="border border-[#c8c0b7] bg-white px-3 py-2" />
                    <button className="bg-[#a64b36] px-3 py-2 text-white">{t('createCoupon')}</button>
                </form>
            </section>
            <section className="mt-8">
                <h2 className="font-semibold">{t('subscriptionsPayments')}</h2>
                <div className="mt-3 divide-y divide-[#ded8cf] border-y border-[#ded8cf]">{subscriptions.map((subscription) => <div key={subscription.id} className="flex flex-wrap items-center justify-between gap-4 py-4">
                    <div><p className="font-medium">{subscription.company?.name}</p><p className="text-sm text-[#68645f]">{subscription.plan?.name} · {subscription.status} · {subscription.ends_at ?? 'Lifetime'}</p></div>
                    <form onSubmit={(event) => { event.preventDefault(); window.axios?.post(`/super-admin/subscriptions/${subscription.id}/payments`, { amount: event.currentTarget.amount.value, status: event.currentTarget.status.value, reference: event.currentTarget.reference.value }).then(() => window.location.reload()); }} className="flex flex-wrap gap-2">
                        <input name="amount" aria-label={t('amount')} type="number" min="0" step="0.01" placeholder={t('amount')} className="w-28 border border-[#c8c0b7] bg-white px-2 py-2" />
                        <select name="status" aria-label="Payment status" className="border border-[#c8c0b7] bg-white px-2 py-2"><option value="paid">Paid</option><option value="pending">Pending</option><option value="failed">Failed</option></select>
                        <input name="reference" aria-label={t('reference')} placeholder={t('reference')} className="w-32 border border-[#c8c0b7] bg-white px-2 py-2" />
                        <button className="border border-[#a64b36] px-3 py-2 text-sm">{t('record')}</button>
                    </form>
                </div>)}</div>
            </section>
        </SuperAdminLayout>
    );
}