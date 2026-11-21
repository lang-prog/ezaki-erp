<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\SimplePartyImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class SimplePartyImportController extends Controller
{
    public function store(Request $request, SimplePartyImportService $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:json,txt', 'max:5120'],
            'customer_parent_account_id' => ['nullable', 'integer'],
            'supplier_parent_account_id' => ['nullable', 'integer'],
        ]);

        try {
            $result = $importer->import(
                $request->attributes->get('company'),
                $request->user(),
                $request->file('file'),
                $request->integer('customer_parent_account_id') ?: null,
                $request->integer('supplier_parent_account_id') ?: null,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'The import could not be completed. No records were saved.']);
        }

        return back()->with('simple_party_import', $result);
    }
}
