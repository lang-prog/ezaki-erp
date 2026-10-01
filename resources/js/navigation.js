// Navigation is data, not a second access-control layer. Laravel routes remain authoritative.
// A group with no permitted destinations is removed rather than displayed empty.
export const companyNavigation = [
    { id: 'overview', label: 'navOverview', icon: 'home', expanded: true, items: [
        { id: 'dashboard', label: 'overview', href: '/dashboard', permission: 'dashboard.view', icon: 'dashboard' },
        { id: 'operations', label: 'operations', href: '/operations', any: ['sales.view', 'purchases.view', 'fleet.view'], icon: 'activity' },
    ] },
    { id: 'sales', label: 'sales', icon: 'sales', expanded: true, items: [
        { id: 'sales-list', label: 'salesInvoices', href: '/operations#sales', permission: 'sales.view', icon: 'list' },
        { id: 'sales-new', label: 'newSalesInvoice', href: '/operations/sales/create', permission: 'sales.create', icon: 'add' },
    ] },
    { id: 'purchases', label: 'purchases', icon: 'purchases', expanded: true, items: [
        { id: 'purchases-list', label: 'purchaseInvoices', href: '/operations#purchases', permission: 'purchases.view', icon: 'list' },
        { id: 'purchases-new', label: 'newPurchaseInvoice', href: '/operations/purchase/create', permission: 'purchases.create', icon: 'add' },
    ] },
    { id: 'stock', label: 'inventory', icon: 'stock', expanded: true, items: [
        { id: 'inventory', label: 'stockOverview', href: '/inventory', permission: 'inventory.view', icon: 'stock' },
        { id: 'branches', label: 'branches', href: '/branches', permission: 'branches.view', icon: 'branches' },
    ] },
    { id: 'parties', label: 'parties', icon: 'people', items: [
        { id: 'customers', label: 'customers', href: '/customers', permission: 'parties.view', icon: 'person' },
        { id: 'suppliers', label: 'suppliers', href: '/suppliers', permission: 'parties.view', icon: 'truck' },
        { id: 'all-parties', label: 'allParties', href: '/parties', permission: 'parties.view', icon: 'people' },
    ] },
    { id: 'accounting', label: 'accounting', icon: 'ledger', items: [
        { id: 'accounting-main', label: 'accountingWorkspace', href: '/accounting', permission: 'accounting.view', icon: 'ledger' },
    ] },
    { id: 'financial-reports', label: 'financialReports', icon: 'chart', items: [
        { id: 'report-journal', label: 'journal', href: '/reports/journal', permission: 'reports.view', icon: 'document' },
        { id: 'report-ledger', label: 'ledger', href: '/reports/ledger', permission: 'reports.view', icon: 'document' },
        { id: 'report-statement', label: 'accountStatement', href: '/reports/account-statement', permission: 'reports.view', icon: 'document' },
        { id: 'report-trial', label: 'trialBalance', href: '/reports/trial-balance', permission: 'reports.view', icon: 'chart' },
        { id: 'report-income', label: 'incomeStatement', href: '/reports/income-statement', permission: 'reports.view', icon: 'chart' },
        { id: 'report-balance', label: 'balanceSheet', href: '/reports/balance-sheet', permission: 'reports.view', icon: 'chart' },
        { id: 'report-debtors', label: 'debtors', href: '/reports/debtors', permission: 'reports.view', icon: 'people' },
        { id: 'report-receivables', label: 'receivablesReport', href: '/reports/receivables', permission: 'reports.view', icon: 'people' },
        { id: 'report-payables', label: 'payablesReport', href: '/reports/payables', permission: 'reports.view', icon: 'people' },
        { id: 'report-creditors', label: 'creditorsReport', href: '/reports/creditors', permission: 'reports.view', icon: 'people' },
    ] },
    { id: 'fleet', label: 'fleet', icon: 'truck', items: [
        { id: 'fleet-main', label: 'fleetWorkspace', href: '/fleet', permission: 'fleet.view', icon: 'truck' },
    ] },
    { id: 'fleet-reports', label: 'fleetReports', icon: 'chart', items: [
        { id: 'fleet-vehicle-pl', label: 'vehicleProfitReport', href: '/fleet/reports/vehicle-pl', permission: 'fleet.view', icon: 'chart' },
        { id: 'fleet-trip-cost', label: 'tripCostReport', href: '/fleet/reports/trip-cost', permission: 'fleet.view', icon: 'chart' },
        { id: 'fleet-fuel', label: 'fuelReport', href: '/fleet/reports/fuel', permission: 'fleet.view', icon: 'chart' },
        { id: 'fleet-driver', label: 'driverReport', href: '/fleet/reports/driver-performance', permission: 'fleet.view', icon: 'chart' },
        { id: 'fleet-expenses', label: 'expenseReport', href: '/fleet/reports/expenses-by-category', permission: 'fleet.view', icon: 'chart' },
        { id: 'fleet-maintenance', label: 'maintenanceReport', href: '/fleet/reports/maintenance-period', permission: 'fleet.view', icon: 'chart' },
        { id: 'fleet-inactive', label: 'inactiveVehiclesReport', href: '/fleet/reports/inactive-vehicles', permission: 'fleet.view', icon: 'chart' },
        { id: 'fleet-branches', label: 'branchPerformanceReport', href: '/fleet/reports/branch-performance', permission: 'fleet.view', icon: 'chart' },
    ] },
    { id: 'management', label: 'navCompany', icon: 'settings', items: [
        { id: 'users-access', label: 'usersAccess', href: '/settings/access', permission: 'users.view', icon: 'people' },
        { id: 'accounting-policy', label: 'accountingPolicies', href: '/settings/accounting', permission: 'company.owner', icon: 'settings' },
        { id: 'subscription', label: 'subscriptionStatus', href: '/subscription', permission: 'subscriptions.view', icon: 'document' },
        { id: 'my-activity', label: 'activityLog', href: '/settings/users/{userId}/activity', permission: 'users.view_activity', icon: 'activity' },
        { id: 'my-logins', label: 'loginHistory', href: '/settings/users/{userId}/logins', permission: 'users.view_activity', icon: 'activity' },
    ] },
];

export const platformNavigation = [
    { id: 'platform-home', label: 'navOverview', icon: 'home', expanded: true, items: [
        { id: 'platform-dashboard', label: 'platformOverview', href: '/super-admin', icon: 'dashboard' },
    ] },
    { id: 'platform-onboarding', label: 'registrationRequests', icon: 'people', expanded: true, items: [
        { id: 'platform-registration-settings', label: 'selfRegistration', href: '/super-admin#registration-settings', icon: 'settings' },
        { id: 'platform-registrations', label: 'registrationRequests', href: '/super-admin#registrations', icon: 'list' },
        { id: 'platform-new-company', label: 'createCompany', href: '/super-admin#new-company', icon: 'add' },
    ] },
    { id: 'platform-companies', label: 'companies', icon: 'branches', expanded: true, items: [
        { id: 'platform-company-list', label: 'companies', href: '/super-admin#companies', icon: 'branches' },
        { id: 'platform-subscriptions', label: 'subscriptionsPayments', href: '/super-admin#subscriptions', icon: 'document' },
    ] },
    { id: 'platform-catalog', label: 'platformCatalog', icon: 'settings', expanded: true, items: [
        { id: 'platform-plans', label: 'plans', href: '/super-admin#plans', icon: 'document' },
        { id: 'platform-coupons', label: 'coupons', href: '/super-admin#coupons', icon: 'ticket' },
    ] },
];

export function visibleNavigation(groups, capabilities = {}, userId, search = '', translate = (key) => key) {
    const query = search.trim().toLocaleLowerCase();
    return groups.map((group) => {
        const groupMatch = translate(group.label).toLocaleLowerCase().includes(query);
        const items = group.items.filter((item) => {
            if (item.permission && !capabilities[item.permission]) return false;
            if (item.any && !item.any.some((permission) => capabilities[permission])) return false;
            if (item.href.includes('{userId}') && !userId) return false;
            return !query || groupMatch || translate(item.label).toLocaleLowerCase().includes(query);
        }).map((item) => ({ ...item, href: item.href.replace('{userId}', String(userId ?? '')) }));
        return { ...group, items };
    }).filter((group) => group.items.length);
}

export function isActiveNavigation(item, currentUrl) {
    const target = new URL(item.href, 'http://ezaki.local');
    const current = new URL(currentUrl || '/', 'http://ezaki.local');
    if (target.pathname === current.pathname) {
        return target.hash ? target.hash === current.hash : !current.hash;
    }
    // A saved invoice has no permanent sidebar URL; return users to its parent list.
    if (item.id === 'sales-list') return /^\/operations\/sales\/[^/]+\/edit$/.test(current.pathname);
    if (item.id === 'purchases-list') return /^\/operations\/purchase\/[^/]+\/edit$/.test(current.pathname);
    if (item.id === 'users-access') return /^\/settings\/users\/[^/]+(?:\/activity|\/logins)?$/.test(current.pathname);
    if (item.id === 'customers') return /^\/customers\/[^/]+$/.test(current.pathname);
    if (item.id === 'suppliers') return /^\/suppliers\/[^/]+$/.test(current.pathname);
    return false;
}
