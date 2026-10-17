<?php

declare(strict_types=1);

namespace App\Support;

/** Platform (Super Admin) permissions and the fixed role catalogue. Company users never hold these. */
final class PlatformPermissions
{
    public const ALL = [
        'dashboard.view', 'companies.view', 'companies.create', 'companies.lifecycle', 'registrations.manage',
        'subscriptions.view', 'subscriptions.payments', 'subscriptions.plan_change', 'subscriptions.limits', 'subscription_requests.manage',
        'plans.manage', 'coupons.manage', 'licenses.view', 'licenses.manage', 'admins.view', 'admins.manage',
        'settings.view', 'settings.manage', 'reports.view', 'audit.view',
    ];

    public const ROLES = [
        'owner' => self::ALL,
        'billing' => [
            'dashboard.view', 'companies.view', 'subscriptions.view', 'subscriptions.payments', 'subscriptions.plan_change', 'subscriptions.limits',
            'subscription_requests.manage', 'plans.manage', 'coupons.manage', 'reports.view', 'settings.view',
        ],
        'support' => [
            'dashboard.view', 'companies.view', 'companies.create', 'companies.lifecycle', 'registrations.manage', 'subscriptions.view',
            'subscription_requests.manage', 'licenses.view', 'reports.view', 'settings.view',
        ],
        'auditor' => [
            'dashboard.view', 'companies.view', 'subscriptions.view', 'licenses.view', 'admins.view', 'settings.view', 'reports.view', 'audit.view',
        ],
    ];

    public static function forRole(?string $role): array
    {
        return self::ROLES[$role] ?? [];
    }

    public static function roles(): array
    {
        return array_keys(self::ROLES);
    }
}
