<?php

namespace App\Http\Controllers;

use App\Http\PageData\CostOverview;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CostController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Costs', (new CostOverview($request->user()))->props());
    }
}
