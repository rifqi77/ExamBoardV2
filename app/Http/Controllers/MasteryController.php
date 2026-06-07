<?php

namespace App\Http\Controllers;

use App\Services\CohortMastery;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MasteryController extends Controller
{
    // GET /{role}/mastery
    public function index(Request $request)
    {
        $user = $request->attributes->get('authUser');

        return Inertia::render('Teacher/Mastery', CohortMastery::forScope($user));
    }
}
