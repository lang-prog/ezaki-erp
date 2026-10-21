<?php

declare(strict_types=1);

namespace App\Services\Legacy;

use App\Models\DataMigrationMapping;
use App\Models\DataMigrationRun;

/** Read access to the run's mapping decisions keyed by entity and source key. */
final class MappingIndex
{
    /** @var array<string, DataMigrationMapping> */
    private array $items = [];

    public function __construct(DataMigrationRun $run)
    {
        foreach (DataMigrationMapping::query()->where('run_id', $run->id)->get() as $m) {
            $this->items[$m->entity_type.'|'.$m->source_key] = $m;
        }
    }

    public function put(DataMigrationMapping $m): void
    {
        $this->items[$m->entity_type.'|'.$m->source_key] = $m;
    }

    public function get(string $entity, string $key): ?DataMigrationMapping
    {
        return $this->items[$entity.'|'.$key] ?? null;
    }

    /** Target id when the mapping is approved ('matched' with a target). */
    public function target(string $entity, string $key): ?int
    {
        $m = $this->get($entity, $key);

        return $m && $m->decision === 'matched' && $m->target_id ? (int) $m->target_id : null;
    }

    /** 'ton' | 'kg' for weight-based products, 'unsupported' for count-based ones, null when undecided or unmapped. */
    public function basis(string $productKey): ?string
    {
        $m = $this->get('product', $productKey);

        return match (data_get($m?->payload, 'source_attributes.unit_basis')) {
            'ton_weight' => 'ton',
            'kg_weight' => 'kg',
            null => null,
            default => 'unsupported',
        };
    }

    /** @return array<string, DataMigrationMapping> */
    public function all(): array
    {
        return $this->items;
    }
}
