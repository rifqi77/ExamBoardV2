<?php

namespace Tests\Feature;

use App\Http\Controllers\ExamDetailController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BulkTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_generates_n_unique_single_use_tokens(): void
    {
        $teacher = User::create(['id' => (string) Str::uuid(), 'username' => 'bt'.substr((string) Str::uuid(), 0, 5), 'full_name' => 'BT', 'role' => 'teacher', 'active' => true]);
        $examId = (string) Str::uuid();
        DB::table('exams')->insert([
            'id' => $examId, 'exam_code' => 'BT-1', 'name' => 'BT', 'duration_minutes' => 30, 'passing_grade' => 50,
            'active' => 1, 'exam_mode' => 'strict', 'language' => 'English', 'created_by' => $teacher->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = Request::create('/x', 'POST', ['count' => 5]);
        $r->attributes->set('authUser', $teacher);
        $resp = (new ExamDetailController())->generateTokensBulk($r, 'BT-1');
        $body = json_decode($resp->getContent(), true);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame(5, $body['count']);
        $this->assertCount(5, $body['codes']);
        $this->assertCount(5, array_unique($body['codes']));               // all distinct
        $this->assertSame(1, $body['maxUses']);                            // single-use

        $rows = DB::table('exam_access_tokens')->where('exam_id', $examId)->get();
        $this->assertCount(5, $rows);
        $this->assertTrue($rows->every(fn ($t) => (int) $t->max_uses === 1 && (int) $t->active === 1 && (int) $t->used_count === 0));
    }

    public function test_bulk_rejects_out_of_range_count(): void
    {
        $teacher = User::create(['id' => (string) Str::uuid(), 'username' => 'bt2'.substr((string) Str::uuid(), 0, 4), 'full_name' => 'BT', 'role' => 'teacher', 'active' => true]);
        DB::table('exams')->insert([
            'id' => (string) Str::uuid(), 'exam_code' => 'BT-2', 'name' => 'BT', 'duration_minutes' => 30, 'passing_grade' => 50,
            'active' => 1, 'exam_mode' => 'strict', 'language' => 'English', 'created_by' => $teacher->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $r = Request::create('/x', 'POST', ['count' => 99999]);
        $r->attributes->set('authUser', $teacher);
        $this->assertSame(400, (new ExamDetailController())->generateTokensBulk($r, 'BT-2')->getStatusCode());
    }
}
