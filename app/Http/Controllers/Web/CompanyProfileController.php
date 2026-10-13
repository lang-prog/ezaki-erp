<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\AuditRecorder;
use App\Services\SessionRevocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CompanyProfileController extends Controller
{
    public function companySettings(Request $request): Response
    {
        abort_unless($request->user()->isCompanyOwner(), 403);
        $company = $request->attributes->get('company');

        return Inertia::render('Company/CompanySettings', ['company' => [...$company->only(['id', 'name', 'trade_name', 'tax_number', 'phone', 'country', 'city', 'address', 'notes']), 'print_background_url' => $company->print_background_path ? Storage::disk('public')->url($company->print_background_path) : null]]);
    }

    public function updateCompany(Request $request, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($request->user()->isCompanyOwner(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'trade_name' => ['nullable', 'string', 'max:180'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:60'],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'print_background' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_print_background' => ['nullable', 'boolean'],
        ]);
        $company = $request->attributes->get('company');
        $oldPath = $company->print_background_path;
        $newPath = null;
        $removeBackground = $request->boolean('remove_print_background') && ! $request->hasFile('print_background');
        unset($data['print_background'], $data['remove_print_background']);
        if ($removeBackground && $oldPath) {
            $data['print_background_path'] = null;
        }
        if ($request->hasFile('print_background')) {
            $newPath = $request->file('print_background')->store('companies/'.$company->id.'/print', 'public');
            abort_unless(is_string($newPath) && $newPath !== '', 500, 'The print background could not be stored.');
            $data['print_background_path'] = $newPath;
        }
        try {
            DB::transaction(function () use ($company, $data, $audit, $request, $oldPath, $newPath, $removeBackground): void {
                $locked = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $before = $locked->only(['name', 'trade_name', 'country', 'city']);
                $locked->fill($data)->save();
                $audit->record('company.settings_updated', $locked, $locked->id, $request->user()->id, [
                    'before' => $before,
                    'after' => $locked->only(['name', 'trade_name', 'country', 'city']),
                    'changed_fields' => array_values(array_diff(array_keys($data), ['print_background_path'])),
                    'print_background_action' => $newPath ? 'uploaded' : ($removeBackground && $oldPath ? 'removed' : 'unchanged'),
                ], $request);
            });
        } catch (\Throwable $exception) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }
            throw $exception;
        }
        if ($oldPath && ($newPath || $removeBackground) && $oldPath !== $newPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return back()->with('status', 'Company details updated.');
    }

    public function updatePassword(Request $request, SessionRevocationService $sessions): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'string', 'min:8'],
        ]);
        $request->user()->forceFill(['password' => Hash::make($data['password'])])->save();
        $request->user()->tokens()->delete();
        $sessions->revoke($request->user());

        return back()->with('status', 'Password updated.');
    }
}
