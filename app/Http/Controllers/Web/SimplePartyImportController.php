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
        ]);

        try {
            $result = $importer->import(
                $request->attributes->get('company'),
                $request->user(),
                $request->file('file'),
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
