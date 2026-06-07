<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The login endpoint must 429 after the per-IP budget (10/min) is spent,
     * so a flood of guesses against the login form is bounded even before the
     * per-account lockout engages.
     */
    public function test_login_is_throttled_per_ip(): void
    {
        $payload = ['username' => 'nobody-here', 'password' => 'wrong-password'];

        // First 10 are processed (invalid creds → 401), not throttled.
        for ($i = 0; $i < 10; $i++) {
            $res = $this->postJson('/api/auth/login', $payload);
            $this->assertNotSame(429, $res->getStatusCode(), "attempt {$i} should not be throttled yet");
        }

        // The 11th within the window is rejected by the limiter.
        $this->postJson('/api/auth/login', $payload)->assertStatus(429);
    }
}
