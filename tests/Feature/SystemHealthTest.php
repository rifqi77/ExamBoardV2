<?php

namespace Tests\Feature;

use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_reports_checks_with_db_ok(): void
    {
        $snap = (new SystemHealth())->snapshot();

        $this->assertArrayHasKey('generatedAt', $snap);
        $this->assertArrayHasKey('checks', $snap);

        $keys = array_column($snap['checks'], 'key');
        foreach (['db', 'migrations', 'backup', 'scheduler', 'queue', 'ai', 'opcache', 'alerting'] as $k) {
            $this->assertContains($k, $keys, "missing check: {$k}");
        }

        $db = collect($snap['checks'])->firstWhere('key', 'db');
        $this->assertSame('ok', $db['status']); // test DB is connected

        // Every status is one of the known values.
        foreach ($snap['checks'] as $c) {
            $this->assertContains($c['status'], ['ok', 'warn', 'down']);
        }
    }
}
