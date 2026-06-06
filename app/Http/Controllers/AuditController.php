<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuditController extends Controller
{
    // GET /admin/audit  — most recent integrity-sensitive actions
    public function index(Request $request)
    {
        $rows = AuditLog::orderByDesc('created_at')->limit(500)->get();
        $actions = AuditLog::select('action')->distinct()->orderBy('action')->pluck('action')->all();

        return Inertia::render('Admin/Audit', [
            'entries' => $rows->map(fn ($e) => [
                'id' => $e->id,
                'at' => optional($e->created_at)->toIso8601String(),
                'actorName' => $e->actor_name,
                'actorRole' => $e->actor_role,
                'impersonated' => $e->impersonated_id ? true : false,
                'action' => $e->action,
                'targetType' => $e->target_type,
                'targetId' => $e->target_id,
                'summary' => $e->summary,
                'ip' => $e->ip,
            ])->all(),
            'actions' => $actions,
        ]);
    }
}
