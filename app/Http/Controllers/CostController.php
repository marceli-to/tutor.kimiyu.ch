<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Was die Lernseiten bei der Claude API gekostet haben, aus dem Log der API-Aufrufe.
 */
class CostController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var Collection<int, Generation> $generations */
        $generations = Generation::query()
            ->whereHas('lesson.child', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with('lesson:id,title,topic,subject,child_id', 'lesson.child:id,name')
            ->latest()
            ->get();

        $months = $generations
            ->groupBy(fn (Generation $g) => $g->created_at?->format('Y-m') ?? 'unbekannt')
            ->map(fn (Collection $items, string $month) => [
                'month' => $items->first()->created_at?->translatedFormat('F Y'),
                'lessons' => $items->pluck('lesson_id')->unique()->count(),
                'calls' => $items->count(),
                'costUsd' => round((float) $items->sum('cost_usd'), 2),
            ])
            ->values();

        $lessons = $generations
            ->groupBy('lesson_id')
            ->map(fn (Collection $items) => [
                'id' => $items->first()->lesson->id,
                'title' => $items->first()->lesson->title ?? $items->first()->lesson->topic ?? 'Ohne Titel',
                'child' => $items->first()->lesson->child->name,
                'date' => $items->last()->created_at?->translatedFormat('j. F Y'),
                'calls' => $items->count(),
                'failed' => $items->where('status', 'error')->count(),
                'outputTokens' => (int) $items->sum('output_tokens'),
                'seconds' => (int) round($items->sum('duration_ms') / 1000),
                'costUsd' => round((float) $items->sum('cost_usd'), 3),
            ])
            ->values()
            ->take(50);

        // Pro Schritt und Modell, teuerste zuerst: zeigt, wo sich Sparen lohnt
        $steps = $generations
            ->groupBy(fn (Generation $g) => "{$g->step}|{$g->model}")
            ->map(fn (Collection $items) => [
                'step' => $items->first()->step,
                'model' => $items->first()->model,
                'calls' => $items->count(),
                'inputTokens' => (int) round($items->avg('input_tokens')),
                'outputTokens' => (int) round($items->avg('output_tokens')),
                'avgUsd' => round((float) $items->avg('cost_usd'), 3),
                'totalUsd' => round((float) $items->sum('cost_usd'), 2),
            ])
            ->sortByDesc('totalUsd')
            ->values();

        return Inertia::render('Costs', [
            'total' => round((float) $generations->sum('cost_usd'), 2),
            'months' => $months,
            'steps' => $steps,
            'lessons' => $lessons,
        ]);
    }
}
