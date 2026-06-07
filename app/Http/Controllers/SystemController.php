<?php

namespace App\Http\Controllers;

use App\Services\SystemHealth;
use Inertia\Inertia;

class SystemController extends Controller
{
    // GET /admin/system — the health panel page.
    public function page(SystemHealth $health)
    {
        return Inertia::render('Admin/System', ['initial' => $health->snapshot()]);
    }

    // GET /api/admin/system-health — fresh snapshot for the Refresh button.
    public function data(SystemHealth $health)
    {
        return response()->json($health->snapshot());
    }
}
