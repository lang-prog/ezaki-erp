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

    /** @return array{cutoff_date: string, effective_date: string, total: int, created: int, reparented: int, skipped: int, skipped_names: list<string>} */
    public function import(Company $company, User $actor, UploadedFile $file, ?int $customerParentId = null, ?int $supplierParentId = null): array
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
            $sourceId = $row['source_id'] ?? null;
            $key = $type.'|'.mb_strtolower($name).'|'.($sourceId === null ? 'no-source' : (string) $sourceId);
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
                throw ValidationException::withMessages(["file.line_{$line}" => "Party row {$line} duplicates row {$seen[$key]} by source identity."]);
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
                'source_id' => $sourceId,
            ];
        }

        return DB::transaction(function () use ($company, $actor, $normalized, $cutoff, $effective, $customerParentId, $supplierParentId): array {
            $this->foundation->seedCompany($company, $actor);
            $parents = [
                'customer' => $customerParentId ? $this->parentAccount($company, $customerParentId, 'asset', 'customer_parent_account_id') : $this->foundation->systemAccount($company, 'customer_control'),
                'supplier' => $supplierParentId ? $this->parentAccount($company, $supplierParentId, 'liability', 'supplier_parent_account_id') : $this->foundation->systemAccount($company, 'supplier_control'),
            ];
            $existing = CustomerSupplier::query()->where('company_id', $company->id)->get();
            $existingByName = $existing->mapWithKeys(fn (CustomerSupplier $party): array => [$this->partyKey($party->name, $party->is_supplier) => $party]);
            $created = 0;
            $reparented = 0;
            $skipped = [];

            foreach ($normalized as $row) {
                $key = $this->partyKey($row['name'], $row['type'] === 'supplier');
                if ($existingByName->has($key)) {
                    $party = $existingByName->get($key);
                    $expectedParent = $parents[$row['type']];
                    if ($party->account && (int) $party->account->parent_id !== (int) $expectedParent->id) {
                        $this->foundation->moveAccount($company, $party->account, $expectedParent);
                        $party->forceFill(['parent_account_id' => $expectedParent->id])->save();
                        $reparented++;
                    }
                    $skipped[] = $row['name'];

                    continue;
                }

                $partyData = $row;
                unset($partyData['source_id']);
                $this->accounting->createParty($company, $actor, [
                    ...$partyData,
                    'parent_account_id' => $parents[$row['type']]->id,
                    'is_customer' => $row['type'] === 'customer',
                    'is_supplier' => $row['type'] === 'supplier',
                    'opening_balance_date' => $effective->toDateString(),
                ]);
                // Do not add newly-created rows to this map: distinct source_id
                // rows with the same display name are independent legacy accounts.
                $created++;
            }

            return [
                'cutoff_date' => $cutoff->toDateString(),
                'effective_date' => $effective->toDateString(),
                'total' => count($normalized),
                'created' => $created,
                'reparented' => $reparented,
                'skipped' => count($skipped),
                'skipped_names' => $skipped,
            ];
        });
    }

    private function parentAccount(Company $company, int $id, string $type, string $field): Account
    {
        $account = Account::query()->where('company_id', $company->id)->whereKey($id)->where('is_active', true)->first();
        if (! $account || $account->account_type !== $type) {
            throw ValidationException::withMessages([$field => $type === 'asset' ? 'The customer parent must be an active asset account.' : 'The supplier parent must be an active liability account.']);
        }

        return $account;
    }

    private function partyKey(string $name, bool $supplier): string
    {
        return ($supplier ? 'supplier|' : 'customer|').mb_strtolower(trim($name));
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
