<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\Company;
use App\Models\CustomerSupplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SimplePartyImportService
{
    private const MAX_ROWS = 5000;

    public function __construct(
        private readonly AccountingFoundationService $foundation,
        private readonly AccountingService $accounting,
    ) {}

    /** @return array{cutoff_date: string, effective_date: string, total: int, created: int, skipped: int, skipped_names: list<string>} */
    public function import(Company $company, User $actor, UploadedFile $file): array
    {
        $payload = $this->decode($file);
        $cutoff = $this->date($payload['cutoff_date'] ?? null, 'cutoff_date');
        $rows = $payload['parties'] ?? null;

        if (($payload['schema_version'] ?? null) !== 1) {
            throw ValidationException::withMessages(['file' => 'The JSON schema_version must be 1.']);
        }
        if (! is_array($rows) || $rows === [] || count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'The JSON file must contain between 1 and 5,000 parties.']);
        }

        $effective = $cutoff->addDay();
        $normalized = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $line = $index + 1;
            if (! is_array($row)) {
                throw ValidationException::withMessages(["file.line_{$line}" => "Party row {$line} must be an object."]);
            }
            $name = trim((string) ($row['name'] ?? ''));
            $type = strtolower(trim((string) ($row['type'] ?? '')));
            $direction = strtolower(trim((string) ($row['opening_balance_direction'] ?? '')));
            $key = mb_strtolower($name);
            $amount = $row['opening_balance'] ?? null;

            if ($name === '' || mb_strlen($name) > 255) {
                throw ValidationException::withMessages(["file.line_{$line}" => "Party row {$line} has an invalid name."]);
            }
            if (! in_array($type, ['customer', 'supplier'], true)) {
                throw ValidationException::withMessages(["file.line_{$line}" => "Party row {$line} type must be customer or supplier."]);
            }
            if (! in_array($direction, ['debit', 'credit'], true)) {
                throw ValidationException::withMessages(["file.line_{$line}" => "Party row {$line} opening_balance_direction must be debit or credit."]);
            }
            if (! is_numeric($amount) || ! is_finite((float) $amount) || (float) $amount < 0) {
                throw ValidationException::withMessages(["file.line_{$line}" => "Party row {$line} opening_balance must be a non-negative number."]);
            }
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["file.line_{$line}" => "Party row {$line} duplicates row {$seen[$key]} by name."]);
            }
            $seen[$key] = $line;
            $normalized[] = [
                'name' => $name,
                'type' => $type,
                'opening_balance' => round((float) $amount, 2),
                'opening_balance_direction' => $direction,
                'email' => $this->nullableString($row['email'] ?? null, 255),
                'phone' => $this->nullableString($row['phone'] ?? null, 50),
                'tax_number' => $this->nullableString($row['tax_number'] ?? null, 255),
                'address' => $this->nullableString($row['address'] ?? null, 2000),
            ];
        }

        return DB::transaction(function () use ($company, $actor, $normalized, $cutoff, $effective): array {
            $this->foundation->seedCompany($company, $actor);
            $parents = [
                'customer' => Account::query()->where('company_id', $company->id)->where('code', '1.2')->firstOrFail(),
                'supplier' => Account::query()->where('company_id', $company->id)->where('code', '2.1')->firstOrFail(),
            ];
            $existing = CustomerSupplier::query()->where('company_id', $company->id)->get(['name'])->mapWithKeys(fn (CustomerSupplier $party): array => [mb_strtolower(trim($party->name)) => true]);
            $created = 0;
            $skipped = [];

            foreach ($normalized as $row) {
                $key = mb_strtolower($row['name']);
                if ($existing->has($key)) {
                    $skipped[] = $row['name'];

                    continue;
                }
                $this->accounting->createParty($company, $actor, [
                    ...$row,
                    'parent_account_id' => $parents[$row['type']]->id,
                    'is_customer' => $row['type'] === 'customer',
                    'is_supplier' => $row['type'] === 'supplier',
                    'opening_balance_date' => $effective->toDateString(),
                ]);
                $existing->put($key, true);
                $created++;
            }

            return [
                'cutoff_date' => $cutoff->toDateString(),
                'effective_date' => $effective->toDateString(),
                'total' => count($normalized),
                'created' => $created,
                'skipped' => count($skipped),
                'skipped_names' => $skipped,
            ];
        });
    }

    /** @return array<string, mixed> */
    private function decode(UploadedFile $file): array
    {
        $contents = file_get_contents($file->getPathname());
        if ($contents === false || strlen($contents) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'The JSON file is missing, unreadable, or larger than 5 MB.']);
        }
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['file' => 'The uploaded file is not valid JSON.']);
        }
        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['file' => 'The JSON root must be an object.']);
        }

        return $decoded;
    }

    private function date(mixed $value, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => "The {$field} must be a valid date."]);
        }
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = trim((string) $value);
        if (mb_strlen($value) > $max) {
            throw ValidationException::withMessages(['file' => "A text field exceeds the maximum length of {$max} characters."]);
        }

        return $value;
    }
}
