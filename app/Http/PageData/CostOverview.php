<?php

namespace App\Http\PageData;

use App\Models\Generation;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Was die Lernseiten bei der Claude API gekostet haben, aus dem Log der API-Aufrufe.
 */
class CostOverview
{
    public function __construct(private User $user) {}

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        /** @var Collection<int, Generation> $generations */
        $generations = Generation::query()
            ->where('user_id', $this->user->id)
            ->with('lesson:id,title,topic,prompt,subject,child_id,deleted_at', 'lesson.child:id,name')
            ->latest()
            ->get();

        $months = $generations
            ->groupBy(fn (Generation $g) => $g->created_at?->format('Y-m') ?? 'unbekannt')
            ->map(fn (Collection $items, string $month) => [
                'month' => $items->first()->created_at?->translatedFormat('F Y'),
                // Ohne Lernseite (samt Kind gelöscht) ist nicht mehr bekannt, wie viele es waren
                'lessons' => $items->pluck('lesson_id')->filter()->unique()->count(),
                'calls' => $items->count(),
                'costUsd' => round((float) $items->sum('cost_usd'), 2),
            ])
            ->values();

        // Weich gelöschte Lernseiten behalten ihre Zeile; Aufrufe ohne Lernseite (samt Kind gelöscht)
        // landen gemeinsam in «Gelöschte Lernseiten».
        $lessons = $generations
            ->groupBy(fn (Generation $g) => $g->lesson_id ?? 'geloescht')
            ->map(fn (Collection $items) => [
                ...self::lesson($items->first()->lesson),
                'date' => $items->last()->created_at?->translatedFormat('j. F Y'),
                'calls' => $items->count(),
                'failed' => $items->where('status', 'error')->count(),
                'outputTokens' => (int) $items->sum('output_tokens'),
                'seconds' => (int) round($items->sum('duration_ms') / 1000),
                'costUsd' => round((float) $items->sum('cost_usd'), 3),
            ])
            ->values()
            ->take(50);

        // Pro Schritt und Modell, teuerste zuerst: zeigt, wo sich Sparen lohnt.
        // Der Durchschnitt zählt nur erfolgreiche Aufrufe, sonst drücken Fehlschläge ohne Tokens ihn nach unten.
        $steps = $generations
            ->groupBy(fn (Generation $g) => "{$g->step}|{$g->model}")
            ->map(function (Collection $items) {
                $ok = $items->where('status', 'ok');

                return [
                    'step' => $items->first()->step,
                    'model' => $items->first()->model,
                    'calls' => $items->count(),
                    'failed' => $items->where('status', 'error')->count(),
                    'inputTokens' => (int) round($ok->avg('input_tokens') ?? 0),
                    'outputTokens' => (int) round($ok->avg('output_tokens') ?? 0),
                    'avgUsd' => round((float) ($ok->avg('cost_usd') ?? 0), 3),
                    'totalUsd' => round((float) $items->sum('cost_usd'), 2),
                ];
            })
            ->sortByDesc('totalUsd')
            ->values();

        return [
            'total' => round((float) $generations->sum('cost_usd'), 2),
            'months' => $months,
            'steps' => $steps,
            'lessons' => $lessons,
        ];
    }

    /**
     * @return array{id: int|null, title: string, child: string|null, deleted: bool}
     */
    private static function lesson(?Lesson $lesson): array
    {
        if ($lesson === null) {
            return ['id' => null, 'title' => 'Gelöschte Lernseiten', 'child' => null, 'deleted' => true];
        }

        return [
            'id' => $lesson->id,
            'title' => $lesson->displayTitle(),
            'child' => $lesson->child->name,
            'deleted' => $lesson->trashed(),
        ];
    }
}
