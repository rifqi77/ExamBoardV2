<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // Resolve lazily: Inertia's middleware runs share() *before* the
            // route middleware (jwt.auth) sets `authUser`, so reading it here
            // eagerly always yields null. A closure defers evaluation until the
            // response renders, by which point jwt.auth has populated it.
            'auth' => fn () => [
                'user' => ($user = $request->attributes->get('authUser')) ? [
                    'id' => $user->id,
                    'username' => $user->username,
                    'fullName' => $user->full_name,
                    'role' => $user->role,
                ] : null,
                'impersonating' => (bool) $request->attributes->get('impersonatorId'),
            ],
        ];
    }
}
