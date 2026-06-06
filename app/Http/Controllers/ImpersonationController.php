<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Services\SessionJwt;
use Illuminate\Http\Request;

/**
 * Admin impersonation. Starting mints a session for the target teacher
 * carrying an `imp` claim with the admin's own uid; stopping reads that
 * claim and mints a fresh admin session. The cookie is swapped server-side
 * in the same response — no client token handling.
 */
class ImpersonationController extends Controller
{
    private function cookie(string $jwt)
    {
        $minutes = (int) (SessionJwt::ttlSeconds() / 60);
        // name, value, minutes, path, domain, secure, httpOnly, raw, sameSite
        return cookie(SessionJwt::COOKIE, $jwt, $minutes, '/', null, (bool) config('session.secure'), true, false, 'lax');
    }

    // POST /api/admin/impersonate/{uid}   (admin only)
    public function start(Request $request, string $uid)
    {
        $admin = $request->attributes->get('authUser');
        // Refuse to nest impersonation.
        if ($request->attributes->get('impersonatorId')) {
            return response()->json(['error' => 'Already impersonating — stop first.'], 409);
        }
        $target = User::find($uid);
        if (! $target) {
            return response()->json(['error' => 'Teacher not found.'], 404);
        }
        if ($target->role !== 'teacher') {
            return response()->json(['error' => 'Only teacher accounts can be impersonated.'], 400);
        }
        if (! $target->active) {
            return response()->json(['error' => 'Cannot impersonate a deactivated teacher.'], 400);
        }
        $jwt = SessionJwt::sign($target->id, 'teacher', (int) $target->token_version, $admin->id);
        Audit::log($request, 'impersonate.start', 'user', $target->id, "Started impersonating {$target->full_name}");
        return response()->json([
            'ok' => true,
            'user' => ['uid' => $target->id, 'fullName' => $target->full_name, 'role' => 'teacher'],
            'redirect' => '/teacher',
        ])->withCookie($this->cookie($jwt));
    }

    // POST /api/admin/impersonate/stop   (must be inside an impersonated session)
    public function stop(Request $request)
    {
        $impId = $request->attributes->get('impersonatorId');
        if (! $impId) {
            return response()->json(['error' => 'Not impersonating.'], 400);
        }
        $admin = User::find($impId);
        if (! $admin || $admin->role !== 'admin' || ! $admin->active) {
            return response()->json(['error' => 'Original admin account is unavailable.'], 400);
        }
        $jwt = SessionJwt::sign($admin->id, 'admin', (int) $admin->token_version);
        Audit::log($request, 'impersonate.stop', 'user', $admin->id, 'Stopped impersonating');
        return response()->json(['ok' => true, 'redirect' => '/admin'])->withCookie($this->cookie($jwt));
    }
}
