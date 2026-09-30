<?php

namespace App\Providers;

use App\Contracts\PaymentGatewayInterface;
use App\Models\User;
use App\Services\ManualPaymentGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, ManualPaymentGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute((int) config('security.auth.login_ip_rate_limit', 12))->by('login-ip:'.$request->ip()),
                Limit::perMinute((int) config('security.auth.login_account_rate_limit', 8))->by('login-account:'.hash('sha256', $email)),
            ];
        });

        foreach ([
            'api.read' => 'api.read_rate_limit',
            'api.write' => 'api.write_rate_limit',
            'api.reports' => 'api.report_rate_limit',
            'api.exports' => 'api.export_rate_limit',
        ] as $name => $configKey) {
            RateLimiter::for($name, function (Request $request) use ($name, $configKey): Limit {
                $user = $request->user();
                $identity = $user?->getAuthIdentifier() ?? $request->ip();
                $company = $user?->company_id ?? 'public';

                return Limit::perMinute((int) config('security.api.'.$configKey, 60))
                    ->by($name.':'.$company.':'.$identity.':'.$request->ip());
            });
        }

        Gate::before(function ($user): ?bool {
            return $user instanceof User && $user->isCompanyOwner() ? true : null;
        });
    }
}
