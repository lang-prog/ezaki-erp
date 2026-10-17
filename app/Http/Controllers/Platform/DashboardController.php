<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Services\PlatformMetricsService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends PlatformController
{
    public function index(PlatformMetricsService $metrics): Response
    {
        $overview = $metrics->overview();

        return Inertia::render('SuperAdmin/Dashboard', ['overview' => $overview, 'alerts' => $metrics->alerts($overview)]);
    }
}
