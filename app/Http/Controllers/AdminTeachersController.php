<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\User;
use App\Models\UserCredential;
use App\Services\Audit;
use App\Services\Capabilities;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AdminTeachersController extends Controller
{
    public function index(Request $request)
    {
        $teachers = User::where('role', 'teacher')->orderBy('full_name')
            ->get(['id', 'username', 'full_name', 'subject', 'active']);
        $ids = $teachers->pluck('id');

        $examCounts = Exam::whereIn('created_by', $ids)
            ->selectRaw('created_by, count(*) c')->groupBy('created_by')->pluck('c', 'created_by');
        $studentCounts = User::where('role', 'student')->whereIn('created_by', $ids)
            ->selectRaw('created_by, count(*) c')->groupBy('created_by')->pluck('c', 'created_by');
        $bankCounts = Schema::hasTable('bank_questions')
            ? DB::table('bank_questions')->whereIn('created_by', $ids)
                ->selectRaw('created_by, count(*) c')->groupBy('created_by')->pluck('c', 'created_by')
            : collect();

        $examOwner = Exam::whereIn('created_by', $ids)->pluck('created_by', 'id'); // examId => ownerId
        $subByExam = ExamSubmission::whereIn('exam_id', $examOwner->keys())
            ->selectRaw('exam_id, count(*) c')->groupBy('exam_id')->pluck('c', 'exam_id');
        $subByOwner = [];
        foreach ($subByExam as $eid => $c) {
            $o = $examOwner[$eid] ?? null;
            if ($o) {
                $subByOwner[$o] = ($subByOwner[$o] ?? 0) + $c;
            }
        }

        $rows = $teachers->map(fn ($t) => [
            'userId' => $t->id,
            'username' => $t->username,
            'fullName' => $t->full_name,
            'subject' => $t->subject,
            'active' => (bool) $t->active,
            'examCount' => (int) ($examCounts[$t->id] ?? 0),
            'studentCount' => (int) ($studentCounts[$t->id] ?? 0),
            'bankQuestionCount' => (int) ($bankCounts[$t->id] ?? 0),
            'submissionCount' => (int) ($subByOwner[$t->id] ?? 0),
        ]);

        return Inertia::render('Admin/Teachers', ['teachers' => $rows]);
    }

    // POST /api/admin/teachers
    public function create(Request $r)
    {
        $u = $r->attributes->get('authUser');
        $username = trim((string) $r->input('username', ''));
        $fullName = trim((string) $r->input('fullName', ''));
        $password = (string) $r->input('password', '');
        $subject = trim((string) $r->input('subject', ''));

        if (! preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $username)) {
            return response()->json(['error' => 'Username must be 3–32 chars: letters, digits, dots, dashes, underscores.'], 400);
        }
        if (strlen($fullName) < 2 || strlen($fullName) > 80) {
            return response()->json(['error' => 'Full name must be 2–80 characters.'], 400);
        }
        if (strlen($password) < 6 || strlen($password) > 64) {
            return response()->json(['error' => 'Password must be 6–64 characters.'], 400);
        }

        try {
            $id = (string) Str::uuid();
            User::create([
                'id' => $id, 'username' => $username, 'full_name' => $fullName,
                'role' => 'teacher', 'active' => true, 'subject' => $subject !== '' ? $subject : null,
                'created_by' => $u->id,
            ]);
            UserCredential::create([
                'user_id' => $id, 'password_hash' => Hash::make($password),
                'password_set_by' => $u->id, 'password_set_at' => now(), 'failed_attempts' => 0,
            ]);
        } catch (QueryException $e) {
            return response()->json(['error' => "Username \"{$username}\" is already in use."], 409);
        }
        Audit::log($r, 'teacher.create', 'user', $id, "Created teacher {$fullName} ({$username})");
        return response()->json(['ok' => true, 'userId' => $id]);
    }

    // POST /api/admin/teachers/{uid}  — { password?, active? }
    public function update(Request $r, string $uid)
    {
        $u = $r->attributes->get('authUser');
        $t = User::where('id', $uid)->where('role', 'teacher')->first();
        if (! $t) {
            return response()->json(['error' => 'Teacher not found.'], 404);
        }
        $password = $r->input('password');
        $active = $r->input('active');
        if ($password === null && $active === null) {
            return response()->json(['error' => 'Provide password and/or active.'], 400);
        }

        if (is_string($password) && $password !== '') {
            if (strlen($password) < 6 || strlen($password) > 64) {
                return response()->json(['error' => 'Password must be 6–64 characters.'], 400);
            }
            UserCredential::updateOrCreate(
                ['user_id' => $t->id],
                ['password_hash' => Hash::make($password), 'password_set_by' => $u->id, 'password_set_at' => now(), 'failed_attempts' => 0, 'locked_until' => null]
            );
            $t->increment('token_version');
        }
        if (is_bool($active)) {
            $t->active = $active;
            if (! $active) {
                $t->token_version = $t->token_version + 1;
            }
            $t->save();
        }
        $changes = [];
        if (is_string($password) && $password !== '') {
            $changes[] = 'password reset';
        }
        if (is_bool($active)) {
            $changes[] = $active ? 'activated' : 'deactivated';
        }
        Audit::log($r, 'teacher.update', 'user', $t->id, "Teacher {$t->full_name}: ".(implode(', ', $changes) ?: 'updated'));
        return response()->json(['ok' => true]);
    }

    // GET /api/admin/teachers/{uid}/capabilities
    public function capabilities(Request $r, string $uid)
    {
        $t = User::where('id', $uid)->where('role', 'teacher')->first();
        if (! $t) {
            return response()->json(['error' => 'Teacher not found.'], 404);
        }
        return response()->json([
            'capabilities' => Capabilities::fill($t->capabilities),
            'registry' => Capabilities::REGISTRY,
        ]);
    }

    // POST /api/admin/teachers/{uid}/capabilities  — { capabilities: { key: bool } }
    public function setCapabilities(Request $r, string $uid)
    {
        $t = User::where('id', $uid)->where('role', 'teacher')->first();
        if (! $t) {
            return response()->json(['error' => 'Teacher not found.'], 404);
        }
        $map = $r->input('capabilities');
        if (! is_array($map)) {
            return response()->json(['error' => 'capabilities must be an object.'], 400);
        }
        $t->capabilities = Capabilities::fill(Capabilities::sanitize($map));
        $t->save();
        Audit::log($r, 'teacher.capabilities', 'user', $t->id, "Updated capabilities for {$t->full_name}");
        return response()->json(['ok' => true, 'capabilities' => $t->capabilities]);
    }
}
