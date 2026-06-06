<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login:127.0.0.1'); // isolate the per-IP throttle between tests
    }

    private function makeUser(bool $active = true): User
    {
        $id = (string) Str::uuid();
        User::create(['id' => $id, 'username' => 'u'.substr($id, 0, 8), 'full_name' => 'Test User', 'role' => 'teacher', 'active' => $active]);
        UserCredential::create(['user_id' => $id, 'password_hash' => Hash::make('correct-pw'), 'password_set_at' => now(), 'failed_attempts' => 0]);
        return User::find($id);
    }

    public function test_successful_login_returns_user(): void
    {
        $u = $this->makeUser();
        $this->postJson('/api/auth/login', ['username' => $u->username, 'password' => 'correct-pw'])
            ->assertOk()->assertJsonPath('user.username', $u->username);
    }

    public function test_wrong_password_is_401(): void
    {
        $u = $this->makeUser();
        $this->postJson('/api/auth/login', ['username' => $u->username, 'password' => 'nope'])->assertStatus(401);
    }

    public function test_deactivated_account_is_403(): void
    {
        $u = $this->makeUser(false);
        $this->postJson('/api/auth/login', ['username' => $u->username, 'password' => 'correct-pw'])->assertStatus(403);
    }

    public function test_account_locks_after_five_failures(): void
    {
        $u = $this->makeUser();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['username' => $u->username, 'password' => 'nope']);
        }
        // Locked: even the correct password is now refused with 423.
        $this->postJson('/api/auth/login', ['username' => $u->username, 'password' => 'correct-pw'])->assertStatus(423);
        $this->assertNotNull(UserCredential::where('user_id', $u->id)->value('locked_until'));
    }
}
