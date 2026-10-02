<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine interaktive Grafik einer Lernseite. Grafik 1 steht oben, 2 und 3 im passenden Abschnitt.
 *
 * @property int $id
 * @property int $lesson_id
 * @property int $position 1–3
 * @property string|null $request Wunsch der Eltern
 * @property string|null $pattern Gewünschtes Muster (HeroPattern)
 * @property array{muster: string, idee: string}|null $plan null: (noch) kein Plan, z. B. weil der Wunsch nicht zum Stoff passt
 * @property array{muster: string, beschreibung: string, css: string, markup: string, script: string}|null $graphic
 * @property string|null $error Hinweis für die Eltern, warum die Grafik fehlt
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['position', 'request', 'pattern', 'plan', 'graphic', 'error'])]
class LessonGraphic extends Model
{
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'plan' => 'array',
            'graphic' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        // Auch für gelöschte Lernseiten, z. B. in einem Job, der noch in der Queue steht
        return $this->belongsTo(Lesson::class)->withTrashed();
    }
}
