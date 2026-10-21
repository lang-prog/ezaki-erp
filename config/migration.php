<?php

declare(strict_types=1);

return [
    // Identity used in every durable source link (company + system + table + id + document type).
    'source_system' => env('MIGRATION_SOURCE_SYSTEM', 'old-prog'),

    // Upload ceiling in kilobytes; must stay below PHP post_max_size/upload_max_filesize on the web server.
    'max_upload_kb' => (int) env('MIGRATION_MAX_UPLOAD_KB', 40960),

    // Hard stop while streaming a (decompressed) dump, and for any single SQL statement.
    'max_uncompressed_bytes' => (int) env('MIGRATION_MAX_UNCOMPRESSED_BYTES', 268435456),
    'max_statement_bytes' => (int) env('MIGRATION_MAX_STATEMENT_BYTES', 33554432),

    // Source files are deleted after this many days from sign-off; the SHA-256 stays in the run for audit.
    'source_retention_days' => (int) env('MIGRATION_SOURCE_RETENTION_DAYS', 90),

    // Dates before this year or after the dump date are treated as malformed and sent to review.
    'valid_from_year' => 2015,

    // Run-level lock lifetime; a killed worker can be resumed once the lock has expired.
    'lock_seconds' => (int) env('MIGRATION_LOCK_SECONDS', 900),

    'mappings_per_page' => 50,
    'records_per_page' => 50,
];
