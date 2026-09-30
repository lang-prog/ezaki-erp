<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReleaseContractTest extends TestCase
{
    public function test_versioned_openapi_contract_is_structurally_present(): void
    {
        $spec = json_decode((string) file_get_contents(__DIR__.'/../../docs/openapi/v1/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('3.1.0', $spec['openapi']);
        self::assertSame('/api/v1', $spec['servers'][0]['url']);
        foreach (['/health', '/login', '/dashboard', '/branches', '/warehouses'] as $path) {
            self::assertArrayHasKey($path, $spec['paths']);
        }
        self::assertArrayHasKey('DataResponse', $spec['components']['schemas']);
        self::assertArrayHasKey('ErrorResponse', $spec['components']['schemas']);
    }

    public function test_release_scripts_are_executable_and_do_not_put_passwords_in_arguments(): void
    {
        foreach (['backup.sh', 'restore.sh', 'preflight.sh', 'smoke.sh'] as $script) {
            self::assertTrue(is_executable(__DIR__.'/../../scripts/'.$script), $script.' must be executable');
        }
        $backup = (string) file_get_contents(__DIR__.'/../../scripts/backup.sh');
        self::assertStringNotContainsString('DB_PASSWORD=', $backup);
        self::assertStringContainsString('backup:database', $backup);
    }
}
