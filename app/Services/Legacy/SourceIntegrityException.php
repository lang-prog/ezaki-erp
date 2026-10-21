<?php

declare(strict_types=1);

namespace App\Services\Legacy;

use RuntimeException;

final class SourceIntegrityException extends RuntimeException
{
    /** @param array<string, mixed> $integrity */
    public function __construct(string $message, public readonly array $integrity = [])
    {
        parent::__construct($message);
    }
}
