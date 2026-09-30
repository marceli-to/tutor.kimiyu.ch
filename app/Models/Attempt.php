<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine Antwort eines Kindes auf eine Quizfrage, einen Sortierbegriff oder eine Lücke.
 *
 * @property int $id
 * @property int $child_id
 * @property int $lesson_id
 * @property string $module
 * @property string $item_id
 * @property bool $correct
 * @property Carbon|null $created_at
 */
#[Fillable(['child_id', 'lesson_id', 'module', 'item_id', 'correct'])]
class Attempt extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'correct' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Child, $this>
     */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    /**
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
