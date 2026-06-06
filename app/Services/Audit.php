<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tamper-evident trail of integrity-sensitive teacher/admin actions
 * (grade changes, submission deletes, impersonation, token lifecycle,
 * account changes, …). Best-effort: a logging failure never breaks the
 * action it is recording.
 *
 * When an admin is impersonating a teacher, the REAL actor recorded is the
 * admin; the teacher being acted-as is captured in `impersonated_id`.
 */
class Audit
{
    public static function log(
        Request $request,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $summary = null,
        array $meta = []
    ): void {
        try {
            $actor = $request->attributes->get('authUser');
            $impId = $request->attributes->get('impersonatorId'); // admin uid when impersonating

            if ($impId) {
                $admin = User::find($impId);
                $actorId = $impId;
                $actorName = $admin->full_name ?? null;
                $actorRole = 'admin';
                $impersonated = $actor->id ?? null;
            } else {
                $actorId = $actor->id ?? null;
                $actorName = $actor->full_name ?? null;
                $actorRole = $actor->role ?? null;
                $impersonated = null;
            }

            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'actor_id' => $actorId,
                'actor_name' => $actorName,
                'actor_role' => $actorRole,
                'impersonated_id' => $impersonated,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'summary' => $summary !== null ? mb_substr($summary, 0, 255) : null,
                'meta' => $meta ? json_encode($meta) : null,
                'ip' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Swallow — auditing must never break the audited operation.
        }
    }
}
