<?php

namespace Tests\Feature;

use App\Http\Controllers\StudentMgmtController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function teacher(): User
    {
        return User::create(['id' => (string) Str::uuid(), 'username' => 't'.substr((string) Str::uuid(), 0, 6), 'full_name' => 'T', 'role' => 'teacher', 'active' => true]);
    }

    private function req(User $actor, array $params): Request
    {
        $r = Request::create('/x', 'POST', $params);
        $r->attributes->set('authUser', $actor);
        return $r;
    }

    public function test_create_reveals_password_once_but_stores_no_plaintext(): void
    {
        $teacher = $this->teacher();
        $ctrl = new StudentMgmtController();
        $resp = $ctrl->create($this->req($teacher, ['username' => 'stud01', 'fullName' => 'Student One', 'password' => 'secret123']));
        $body = json_decode($resp->getContent(), true);

        // One-time reveal in the response …
        $this->assertSame('secret123', $body['student']['passwordPlain']);
        // … but nothing recoverable persisted.
        $uid = $body['student']['userId'];
        $this->assertNull(DB::table('user_credentials')->where('user_id', $uid)->value('password_plain'));
        // Hash is stored and still verifies (login keeps working).
        $hash = DB::table('user_credentials')->where('user_id', $uid)->value('password_hash');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret123', $hash));
    }

    public function test_bulk_reset_stores_no_plaintext(): void
    {
        $teacher = $this->teacher();
        $ctrl = new StudentMgmtController();
        $created = json_decode($ctrl->create($this->req($teacher, ['username' => 'stud02', 'fullName' => 'Student Two', 'password' => 'secret123']))->getContent(), true);
        $uid = $created['student']['userId'];

        $resp = $ctrl->bulk($this->req($teacher, ['action' => 'reset', 'userIds' => [$uid]]));
        $body = json_decode($resp->getContent(), true);

        $this->assertNotEmpty($body['credentials'][0]['password']);                 // revealed once
        $this->assertNull(DB::table('user_credentials')->where('user_id', $uid)->value('password_plain')); // not stored
    }
}
