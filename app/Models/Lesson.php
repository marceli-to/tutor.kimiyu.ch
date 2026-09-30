<?php

namespace App\Models;

use App\Enums\LessonStatus;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $child_id
 * @property LessonStatus $status
 * @property string|null $step
 * @property string|null $title
 * @property string $subject
 * @property string $level
 * @property string|null $topic
 * @property string|null $notes
 * @property int|null $schema_version
 * @property array<string, mixed>|null $content
 * @property array{muster: string, idee: string}|null $hero_plan
 * @property array{muster: string, beschreibung: string, css: string, markup: string, script: string}|null $hero
 * @property string|null $hero_error
 * @property list<array{bereich: string, aenderung: string}>|null $check_notes
 * @property string|null $source_summary
 * @property string|null $error
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['status', 'step', 'title', 'subject', 'level', 'topic', 'notes', 'schema_version', 'content', 'hero_plan', 'hero', 'hero_error', 'check_notes', 'source_summary', 'error', 'published_at'])]
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => LessonStatus::class,
            'content' => 'array',
            'hero_plan' => 'array',
            'hero' => 'array',
            'check_notes' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Ohne Fotos erstellt: Inhalt stammt aus dem Wissen der KI, nicht aus dem Schulbuch.
     */
    public function isFromTopic(): bool
    {
        return $this->topic !== null;
    }

    /**
     * @return BelongsTo<Child, $this>
     */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    /**
     * @return HasMany<LessonImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(LessonImage::class)->orderBy('position');
    }

    /**
     * @return HasMany<Generation, $this>
     */
    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    /**
     * @return HasMany<Attempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }
}
