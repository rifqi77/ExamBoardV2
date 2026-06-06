<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Capabilities;
use Tests\TestCase;

class CapabilitiesTest extends TestCase
{
    private function user(string $role, ?array $caps = null): User
    {
        $u = new User(['role' => $role]);
        $u->capabilities = $caps;
        return $u;
    }

    public function test_admin_is_always_allowed(): void
    {
        $u = $this->user('admin', ['ai.generate' => false]);
        $this->assertTrue(Capabilities::has($u, 'ai.generate'));
    }

    public function test_teacher_with_no_map_defaults_allowed(): void
    {
        $this->assertTrue(Capabilities::has($this->user('teacher', null), 'ai.generate'));
    }

    public function test_teacher_explicit_false_blocks_only_that_key(): void
    {
        $u = $this->user('teacher', ['ai.generate' => false]);
        $this->assertFalse(Capabilities::has($u, 'ai.generate'));
        $this->assertTrue(Capabilities::has($u, 'exam.config.duration')); // unset key defaults true
    }

    public function test_fill_returns_every_key(): void
    {
        $filled = Capabilities::fill(['ai.generate' => false, 'bogus.key' => true]);
        $this->assertCount(count(Capabilities::keys()), $filled);
        $this->assertFalse($filled['ai.generate']);
        $this->assertArrayNotHasKey('bogus.key', Capabilities::sanitize(['bogus.key' => true]));
    }
}
