<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserCredential;
use App\Services\Audit;
use App\Services\StudentCredentials;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StudentMgmtController extends Controller
{
    // POST /api/teacher/students  — create one student
    public function create(Request $r)
    {
        $u = $r->attributes->get('authUser');
        $username = trim((string) $r->input('username', ''));
        $fullName = trim((string) $r->input('fullName', ''));
        $password = (string) $r->input('password', '');

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
                'id' => $id,
                'username' => $username,
                'full_name' => $fullName,
                'role' => 'student',
                'active' => true,
                'subject' => null,
                'created_by' => $u->id,
            ]);
            UserCredential::create([
                'user_id' => $id,
                'password_hash' => Hash::make($password),
                'password_set_by' => $u->id,
                'password_set_at' => now(),
                'failed_attempts' => 0,
            ]);
        } catch (QueryException $e) {
            return response()->json(['error' => "Username \"{$username}\" is already in use."], 409);
        }

        Audit::log($r, 'student.create', 'user', $id, "Created student {$fullName} ({$username})");
        return response()->json(['student' => [
            'userId' => $id,
            'username' => $username,
            'fullName' => $fullName,
            'active' => true,
            'totalSubmissions' => 0,
            'passwordPlain' => $password,
        ]]);
    }

    // POST /api/teacher/students/bulk  — { action, userIds[], password? }
    public function bulk(Request $r)
    {
        $u = $r->attributes->get('authUser');
        $action = $r->input('action');
        if (! in_array($action, ['deactivate', 'activate', 'reset', 'delete', 'extra_time'], true)) {
            return response()->json(['error' => 'Unknown action.'], 400);
        }
        $ids = array_values(array_unique(array_filter(
            (array) $r->input('userIds', []),
            fn ($x) => is_string($x) && $x !== ''
        )));
        if (count($ids) === 0) {
            return response()->json(['error' => 'No students selected.'], 400);
        }
        if (count($ids) > 1000) {
            return response()->json(['error' => 'Too many at once (max 1000).'], 400);
        }
        $explicit = $r->input('password');
        $explicit = (is_string($explicit) && strlen($explicit) >= 6) ? $explicit : null;

        $targets = User::whereIn('id', $ids)->where('role', 'student')
            ->get(['id', 'username', 'full_name', 'created_by']);
        $isTeacher = $u->role === 'teacher';
        $allowed = $targets->filter(fn ($t) => ! $isTeacher || $t->created_by === $u->id);
        $allowedIds = $allowed->pluck('id')->all();
        $skipped = count($ids) - count($allowedIds);
        if (count($allowedIds) === 0) {
            return response()->json(['error' => 'None of the selected students are yours to manage.'], 403);
        }

        if ($action === 'deactivate' || $action === 'activate') {
            $next = $action === 'activate';
            User::whereIn('id', $allowedIds)->update(['active' => $next]);
            if (! $next) {
                User::whereIn('id', $allowedIds)->increment('token_version');
            }
            Audit::log($r, 'student.bulk', null, null, "Bulk {$action} on ".count($allowedIds).' student(s)', ['action' => $action, 'count' => count($allowedIds)]);
            return response()->json(['action' => $action, 'updated' => count($allowedIds), 'skipped' => $skipped]);
        }

        if ($action === 'extra_time') {
            $pct = max(0, min(300, (int) $r->input('value', 0)));
            User::whereIn('id', $allowedIds)->update(['extra_time_percent' => $pct]);
            Audit::log($r, 'student.bulk', null, null, "Set extra time {$pct}% on ".count($allowedIds).' student(s)', ['action' => 'extra_time', 'value' => $pct, 'count' => count($allowedIds)]);
            return response()->json(['action' => 'extra_time', 'updated' => count($allowedIds), 'value' => $pct, 'skipped' => $skipped]);
        }

        if ($action === 'delete') {
            $cnt = User::whereIn('id', $allowedIds)->delete();
            Audit::log($r, 'student.bulk', null, null, "Bulk-deleted {$cnt} student(s)", ['action' => 'delete', 'count' => $cnt]);
            return response()->json(['action' => $action, 'deleted' => $cnt, 'skipped' => $skipped]);
        }

        // reset
        $creds = [];
        foreach ($allowed as $t) {
            $pw = $explicit ?? StudentCredentials::generatePasswordFromName($t->full_name);
            UserCredential::updateOrCreate(
                ['user_id' => $t->id],
                [
                    'password_hash' => Hash::make($pw),
                    'password_set_by' => $u->id,
                    'password_set_at' => now(),
                    'failed_attempts' => 0,
                    'locked_until' => null,
                ]
            );
            User::where('id', $t->id)->increment('token_version');
            $creds[] = ['userId' => $t->id, 'username' => $t->username, 'fullName' => $t->full_name, 'password' => $pw];
        }
        Audit::log($r, 'student.bulk', null, null, 'Bulk-reset '.count($creds).' password(s)', ['action' => 'reset', 'count' => count($creds)]);
        return response()->json(['action' => 'reset', 'reset' => count($creds), 'credentials' => $creds, 'skipped' => $skipped]);
    }
}
