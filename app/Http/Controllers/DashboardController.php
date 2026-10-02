<?php

namespace App\Http\Controllers;

use App\Models\Child;
use App\Models\Lesson;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bibliothek: pro Kind die Lernseiten, nach Fach gruppiert und neueste zuerst.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $children = $request->user()->children()
            ->with(['lessons' => fn ($q) => $q->latest()])
            ->orderBy('name')
            ->get();

        return Inertia::render('Dashboard', [
            'children' => $children->map(fn (Child $child) => [
                'id' => $child->id,
                'name' => $child->name,
                'level' => $child->level,
                'shareUrl' => route('shared.index', $child->share_token),
                'subjects' => $child->lessons
                    // Ohne Fach (noch nicht erkannt) als leerer Schlüssel, damit zuoberst
                    ->groupBy(fn (Lesson $lesson) => (string) $lesson->subject)
                    ->sortKeys(SORT_NATURAL | SORT_FLAG_CASE)
                    ->map(fn ($lessons, string $subject) => [
                        'name' => $subject !== '' ? $subject : Lesson::SUBJECT_PENDING,
                        'lessons' => $lessons->map(fn (Lesson $lesson) => [
                            'id' => $lesson->id,
                            'title' => $lesson->displayTitle(),
                            'emoji' => $lesson->content['meta']['emoji'] ?? null,
                            'status' => $lesson->status->value,
                            'statusLabel' => $lesson->status->label(),
                            'date' => $lesson->created_at?->translatedFormat('j. F Y'),
                        ])->values(),
                    ])
                    ->values(),
            ]),
        ]);
    }
}
