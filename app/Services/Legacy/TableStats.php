<?php

declare(strict_types=1);

namespace App\Services\Legacy;

final class TableStats
{
    public int $statements = 0;

    public int $rows = 0;

    public int $rejected = 0;

    /** @var list<string> */
    public array $columns = [];
}
