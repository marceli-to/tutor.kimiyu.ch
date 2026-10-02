<?php

namespace App\Http\Controllers;

use App\Http\PageData\Dashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard', (new Dashboard($request->user()))->props());
    }
}
