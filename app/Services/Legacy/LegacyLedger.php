<?php

declare(strict_types=1);

namespace App\Services\Legacy;

use App\Models\DataMigrationRun;
use Carbon\CarbonImmutable;

/**
 * Derives balances, stock and structural document classes from the staged legacy rows.
 * Legacy quantities are in kg; price basis (per ton or per kg) is supplied per product because the source does not store it.
 */
final class LegacyLedger
{
    public const NON_WEIGHT = ['count', 'bag', 'piece', 'box', 'meter', 'volume', 'other'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $products = null;

    /** @param callable(string): ?string $basis product key => 'ton'|'kg'|null (null = undecided/unsupported) */
    public function __construct(public readonly LegacyDataset $d, private readonly DataMigrationRun $run, private $basis) {}

    public function cutoff(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->run->opening_balance_as_of->toDateString())->startOfDay();
    }

    public function from(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->run->documents_from->toDateString())->startOfDay();
    }

    public function to(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->run->documents_to->toDateString())->startOfDay();
    }

    public static function productKey(mixed $type, mixed $category): string
    {
        return LegacyDataset::key($type).':'.LegacyDataset::key($category);
    }

    /** Value of one legacy line using the product's price basis; null when the basis is unknown or unsupported. */
    public function lineValue(string $productKey, float $kg, float $price): ?float
    {
        $basis = ($this->basis)($productKey);
        if ($basis === null) {
            $p = $this->products()[$productKey] ?? null;
            $basis = $p && $p['steel'] && in_array($p['evidence'], ['per_ton', 'none'], true) ? 'ton' : null;
        }

        return match ($basis) {
            'ton' => round($kg / 1000 * $price, 2),
            'kg' => round($kg * $price, 2),
            default => null,
        };
    }

    /**
     * Product facts keyed "type:category".
     *
     * @return array<string, array<string, mixed>>
     */
    public function products(): array
    {
        if ($this->products !== null) {
            return $this->products;
        }
        $types = [];
        foreach ($this->d->rows('type_mm') as $t) {
            $types[LegacyDataset::key($t['id_type'])] = $t;
        }
        $cats = [];
        foreach ($this->d->rows('categories') as $c) {
            $cats[LegacyDataset::key($c['id_categories'])] = $c;
        }
        $out = [];
        $touch = function (string $type, string $cat) use (&$out, $types, $cats): string {
            $k = $type.':'.$cat;
            $out[$k] ??= [
                'key' => $k, 'type_key' => $type, 'category_key' => $cat,
                'type_name' => trim((string) ($types[$type]['name'] ?? '')), 'category_name' => trim((string) ($cats[$cat]['categories'] ?? '')),
                'type_steel' => (int) ($types[$type]['steel_or_other'] ?? 0) === 1, 'category_steel' => (int) ($cats[$cat]['steel_or_other'] ?? 0) === 1,
                'type_known' => isset($types[$type]), 'category_known' => isset($cats[$cat]),
                'per_ton_lines' => 0, 'per_kg_lines' => 0, 'other_lines' => 0, 'lines' => 0,
            ];

            return $k;
        };
        foreach (['details_buy', 'details_sales'] as $table) {
            foreach ($this->d->rows($table) as $l) {
                $k = $touch(LegacyDataset::key($l['id_type']), LegacyDataset::key($l['id_categories']));
                $out[$k]['lines']++;
                if ($table === 'details_sales') {
                    $kg = LegacyDataset::num($l['kg']);
                    $price = LegacyDataset::num($l['price']);
                    $total = LegacyDataset::num($l['total']);
                    if ($kg > 0 && $price > 0 && $total > 0) {
                        if (abs($kg / 1000 * $price - $total) <= 1) {
                            $out[$k]['per_ton_lines']++;
                        } elseif (abs($kg * $price - $total) <= 1) {
                            $out[$k]['per_kg_lines']++;
                        } else {
                            $out[$k]['other_lines']++;
                        }
                    }
                }
            }
        }
        foreach ($this->d->rows('starting_balance') as $s) {
            $touch(LegacyDataset::key($s['id_type']), LegacyDataset::key($s['id_categories']));
        }
        foreach ($out as $k => &$p) {
            $p['numeric_diameter'] = self::diameter($p['category_name']);
            $p['evidence'] = match (true) {
                $p['per_ton_lines'] > 0 && $p['per_kg_lines'] === 0 && $p['other_lines'] === 0 => 'per_ton',
                $p['per_kg_lines'] > 0 && $p['per_ton_lines'] === 0 && $p['other_lines'] === 0 => 'per_kg',
                $p['per_ton_lines'] + $p['per_kg_lines'] + $p['other_lines'] === 0 => 'none',
                default => 'mixed',
            };
            $p['steel'] = $p['type_steel'] && $p['category_steel'];
        }
        unset($p);

        return $this->products = $out;
    }

    /** Numeric diameter in mm from `8`, `8mm`, `8 مم`, `٨مم`, `8,5 ملم`; null for text materials. */
    public static function diameter(string $value): ?float
    {
        $v = strtr(trim($value), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => ',']);
        if (! preg_match('/^\s*([0-9]+(?:[.,][0-9]+)?)\s*(?:مم|ملم|mm|㎜)?\s*$/iu', $v, $m)) {
            return null;
        }
        $n = (float) str_replace(',', '.', $m[1]);

        return $n > 0 ? $n : null;
    }

    /** Conservative name key: Unicode-normalised, harakat/tatweel removed, spaces squished, lower-cased. Never folds alef/yaa/taa forms. */
    public static function nameKey(string $name): string
    {
        $n = class_exists(\Normalizer::class) ? (\Normalizer::normalize($name, \Normalizer::FORM_KC) ?: $name) : $name;
        $n = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $n) ?? $n;

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $n)));
    }

    /**
     * Sales and purchase document facts shared by classification and balances.
     *
     * @return array<string, mixed>
     */
    public function purchaseFacts(array $b): array
    {
        $id = LegacyDataset::key($b['id_buy']);
        $lines = $this->d->where('details_buy', 'id_buy', $id);
        $value = 0.0;
        $undecided = false;
        $out = [];
        foreach ($lines as $l) {
            $key = self::productKey($l['id_type'], $l['id_categories']);
            $kg = LegacyDataset::num($l['kg']);
            $price = LegacyDataset::num($l['price']);
            $lineValue = $this->lineValue($key, $kg, $price);
            $undecided = $undecided || $lineValue === null;
            $value += $lineValue ?? 0.0;
            $out[] = ['line_id' => (string) $l['id_details_buy'], 'product_key' => $key, 'warehouse_key' => LegacyDataset::key($l['id_stores']), 'kg' => $kg, 'packages' => LegacyDataset::num($l['no']), 'price' => $price, 'legacy_value' => $lineValue, 'note' => $l['note'] ?? null];
        }

        return ['lines' => $out, 'value' => round($value, 2), 'extra' => LegacyDataset::num($b['exp']), 'undecided' => $undecided];
    }

    /** @return array<string, mixed> */
    public function salesFacts(array $s): array
    {
        $id = LegacyDataset::key($s['id_sales']);
        $lines = $this->d->where('details_sales', 'id_sales', $id);
        $sum = 0.0;
        $out = [];
        foreach ($lines as $l) {
            $key = self::productKey($l['id_type'], $l['id_categories']);
            $kg = LegacyDataset::num($l['kg']);
            $price = LegacyDataset::num($l['price']);
            $total = LegacyDataset::num($l['total']);
            $sum += $total;
            $out[] = ['line_id' => (string) $l['id_details_sales'], 'product_key' => $key, 'warehouse_key' => LegacyDataset::key($l['id_stores']), 'kg' => $kg, 'price' => $price, 'legacy_value' => $total, 'note' => $l['note'] ?? null];
        }
        $tips = LegacyDataset::num($s['tips']);
        $mashal = LegacyDataset::num($s['mashal']);

        return ['lines' => $out, 'lines_total' => round($sum, 2), 'transport' => $tips, 'loading' => $mashal, 'computed_total' => round($sum + $tips + $mashal, 2), 'header_total' => LegacyDataset::num($s['total'])];
    }

    /**
     * Opening position at the cutoff (balances are signed: customers debit+, suppliers credit+, before applying source sign policy).
     *
     * @return array<string, mixed>
     */
    public function opening(?CarbonImmutable $asOf = null): array
    {
        $cut = $asOf ?? $this->cutoff();
        $issues = ['undatable' => [], 'invoice_total_mismatch' => 0, 'invoice_total_mismatch_delta' => 0.0, 'undecided_basis_lines' => 0, 'invoice_with_cash' => 0, 'invoice_with_payment' => 0];
        $cust = [];
        foreach ($this->d->rows('customer') as $c) {
            $cust[LegacyDataset::key($c['id_customer'])] = ['start' => LegacyDataset::num($c['start_balance']), 'invoices' => 0.0, 'receipts' => 0.0];
        }
        $supp = [];
        foreach ($this->d->rows('account_import') as $a) {
            $k = LegacyDataset::key($a['id_import']);
            $supp[$k] ??= ['start' => 0.0, 'invoices' => 0.0, 'payments' => 0.0];
            $supp[$k]['start'] += LegacyDataset::num($a['start_cash']);
        }
        foreach ($this->d->rows('sales') as $s) {
            $d = LegacyDataset::date($s['insert_date']);
            if ($d === null) {
                $issues['undatable'][] = 'sales:'.$s['id_sales'];

                continue;
            }
            if ($d->greaterThan($cut)) {
                continue;
            }
            $k = LegacyDataset::key($s['id_customer']);
            $hasLines = $this->d->where('details_sales', 'id_sales', LegacyDataset::key($s['id_sales'])) !== [];
            $cash = LegacyDataset::num($s['cash']);
            if ($hasLines) {
                $f = $this->salesFacts($s);
                $amount = $f['header_total'];
                if (abs($f['header_total'] - $f['computed_total']) > 0.5) {
                    $issues['invoice_total_mismatch']++;
                    $issues['invoice_total_mismatch_delta'] += $f['header_total'] - $f['computed_total'];
                }
                if ($k !== '0') {
                    $cust[$k] ??= ['start' => 0.0, 'invoices' => 0.0, 'receipts' => 0.0];
                    $cust[$k]['invoices'] += $amount;
                }
                if ($cash > 0) {
                    $issues['invoice_with_cash']++;
                    if ($k !== '0') {
                        $cust[$k]['receipts'] += $cash;
                    }
                }
            } elseif ($cash > 0 && $k !== '0') {
                $cust[$k] ??= ['start' => 0.0, 'invoices' => 0.0, 'receipts' => 0.0];
                $cust[$k]['receipts'] += $cash;
            }
        }
        foreach ($this->d->rows('buy') as $b) {
            $d = LegacyDataset::date($b['date']);
            if ($d === null) {
                $issues['undatable'][] = 'buy:'.$b['id_buy'];

                continue;
            }
            if ($d->greaterThan($cut)) {
                continue;
            }
            $k = LegacyDataset::key($b['id_import']);
            if ($k === '0') {
                continue;
            }
            $supp[$k] ??= ['start' => 0.0, 'invoices' => 0.0, 'payments' => 0.0];
            $f = $this->purchaseFacts($b);
            if ($f['lines'] !== []) {
                $supp[$k]['invoices'] += $f['value'] + $f['extra'];
                $issues['undecided_basis_lines'] += $f['undecided'] ? 1 : 0;
                if (LegacyDataset::num($b['eda3']) > 0) {
                    $issues['invoice_with_payment']++;
                    $supp[$k]['payments'] += LegacyDataset::num($b['eda3']);
                }
            } else {
                $supp[$k]['payments'] += LegacyDataset::num($b['eda3']);
            }
        }

        return ['customers' => $cust, 'suppliers' => $supp, 'issues' => $issues];
    }

    /**
     * Stock at cutoff per product key (kg) with weighted-average purchase cost per ton from valid pre-cutoff purchases.
     *
     * @return array<string, array<string, mixed>>
     */
    public function stock(?CarbonImmutable $asOf = null): array
    {
        $cut = $asOf ?? $this->cutoff();
        $out = [];
        $add = function (string $key, string $wh) use (&$out): void {
            $out[$key] ??= ['key' => $key, 'kg' => 0.0, 'opening_kg' => 0.0, 'movement_kg' => 0.0, 'warehouses' => [], 'buy_tons' => 0.0, 'buy_value' => 0.0, 'undatable' => 0];
            $out[$key]['warehouses'][$wh] = true;
        };
        $warehouses = array_map(fn ($s) => LegacyDataset::key($s['id_stores']), $this->d->rows('stores'));
        $defaultWh = count($warehouses) === 1 ? $warehouses[0] : null;
        foreach ($this->d->rows('starting_balance') as $s) {
            $key = self::productKey($s['id_type'], $s['id_categories']);
            $add($key, $defaultWh ?? '?');
            $out[$key]['kg'] += LegacyDataset::num($s['K_G']);
            $out[$key]['opening_kg'] += LegacyDataset::num($s['K_G']);
        }
        $detailsBuy = [];
        foreach ($this->d->rows('details_buy') as $l) {
            $detailsBuy[(string) $l['id_details_buy']] = $l;
        }
        $detailsSales = [];
        foreach ($this->d->rows('details_sales') as $l) {
            $detailsSales[(string) $l['id_details_sales']] = $l;
        }
        foreach ($this->d->rows('store') as $r) {
            $d = LegacyDataset::date($r['date_add']);
            $det = ! empty($r['id_details_buy']) ? ($detailsBuy[(string) $r['id_details_buy']] ?? null) : ($detailsSales[(string) ($r['id_details_sales'] ?? '')] ?? null);
            if ($det === null) {
                continue;
            }
            $key = self::productKey($det['id_type'], $det['id_categories']);
            $add($key, LegacyDataset::key($r['id_stores']));
            if ($d === null) {
                $out[$key]['undatable']++;

                continue;
            }
            if ($d->greaterThan($cut)) {
                continue;
            }
            $delta = LegacyDataset::num($r['buy']) - LegacyDataset::num($r['sale']);
            $out[$key]['kg'] += $delta;
            $out[$key]['movement_kg'] += $delta;
        }
        $dateOf = [];
        foreach ($this->d->rows('buy') as $b) {
            $dateOf[LegacyDataset::key($b['id_buy'])] = LegacyDataset::date($b['date']);
        }
        foreach ($this->d->rows('details_buy') as $l) {
            $d = $dateOf[LegacyDataset::key($l['id_buy'])] ?? null;
            $kg = LegacyDataset::num($l['kg']);
            $price = LegacyDataset::num($l['price']);
            if ($d === null || $d->greaterThan($cut) || $kg <= 0 || $price <= 0) {
                continue;
            }
            $key = self::productKey($l['id_type'], $l['id_categories']);
            $value = $this->lineValue($key, $kg, $price);
            $add($key, LegacyDataset::key($l['id_stores']));
            $out[$key]['buy_tons'] += $kg / 1000;
            $out[$key]['buy_value'] += $value ?? 0.0;
            $out[$key]['basis_undecided'] = ($out[$key]['basis_undecided'] ?? false) || $value === null;
        }
        foreach ($out as &$p) {
            $p['tons'] = round($p['kg'] / 1000, 3);
            $p['cost_per_ton'] = $p['buy_tons'] > 0 ? round($p['buy_value'] / $p['buy_tons'], 2) : null;
            $p['warehouses'] = array_keys($p['warehouses']);
        }
        unset($p);

        return $out;
    }

    /**
     * Cashbox and bank balances at the cutoff.
     *
     * @return array{cashboxes: array<string, float>, unassigned_treasury: array<string, float|int>, banks: array<string, float>, undatable: int}
     */
    public function cashAndBanks(?CarbonImmutable $asOf = null): array
    {
        $cut = $asOf ?? $this->cutoff();
        $boxes = [];
        $unassigned = ['rows' => 0, 'net' => 0.0];
        $undatable = 0;
        foreach ($this->d->rows('treasury') as $t) {
            $d = LegacyDataset::date($t['inserted_date']);
            if ($d === null) {
                $undatable++;

                continue;
            }
            if ($d->greaterThan($cut)) {
                continue;
            }
            $net = LegacyDataset::num($t['cash_in']) - LegacyDataset::num($t['cash_out']);
            $k = LegacyDataset::key($t['id_name_treasury']);
            if ($k === '0') {
                $unassigned['rows']++;
                $unassigned['net'] += $net;

                continue;
            }
            $boxes[$k] = ($boxes[$k] ?? 0.0) + $net;
        }
        $banks = [];
        foreach ($this->d->rows('bank_accounts') as $b) {
            $banks[LegacyDataset::key($b['id_bank_accounts'])] = LegacyDataset::num($b['starting_balance']);
        }
        foreach ($this->d->rows('bank_operations') as $o) {
            $d = LegacyDataset::date($o['inserted_date']);
            if ($d === null) {
                $undatable++;

                continue;
            }
            if ($d->greaterThan($cut)) {
                continue;
            }
            $k = LegacyDataset::key($o['id_bank_accounts']);
            $banks[$k] = ($banks[$k] ?? 0.0) + LegacyDataset::num($o['cash_in']) - LegacyDataset::num($o['cash_out']);
        }

        return ['cashboxes' => array_map(fn ($v) => round($v, 2), $boxes), 'unassigned_treasury' => ['rows' => $unassigned['rows'], 'net' => round($unassigned['net'], 2)], 'banks' => array_map(fn ($v) => round($v, 2), $banks), 'undatable' => $undatable];
    }
}
