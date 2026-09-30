<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vorläufige Übersicht für Phase 1. Die richtige Bibliothek pro Kind folgt in Phase 3.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $lessons = Lesson::query()
            ->whereHas('child', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with('child:id,name')
            ->latest()
            ->get()
            ->map(fn (Lesson $lesson) => [
                'id' => $lesson->id,
                'title' => $lesson->title ?? 'Neue Lernseite',
                'subject' => $lesson->subject,
                'child' => $lesson->child->name,
                'emoji' => $lesson->content['meta']['emoji'] ?? null,
                'status' => $lesson->status->label(),
            ]);

        return Inertia::render('Dashboard', ['lessons' => $lessons]);
    }
}
