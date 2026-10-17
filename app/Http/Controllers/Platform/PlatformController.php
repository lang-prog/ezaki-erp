<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

abstract class PlatformController extends Controller
{
    protected function reason(Request $request): string
    {
        return (string) $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], ['reason.required' => __('platform.reason_required'), 'reason.min' => __('platform.reason_required')])['reason'];
    }

    protected function done(Request $request, array $payload = []): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? response()->json(['ok' => true] + $payload) : back();
    }

    protected function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }
}
