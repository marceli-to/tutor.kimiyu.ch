<?php

namespace App\Models;

use App\Enums\LessonStatus;
use Database\Factories\LessonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
 * @property string|null $prompt Auftrag der Eltern, frei formuliert
 * @property int $photo_count
 * @property bool $with_hero
 * @property int|null $schema_version
 * @property array<string, mixed>|null $content
 * @property array{muster: string, idee: string}|null $hero_plan null: keine Grafik (abgewählt oder kein Muster passt)
 * @property array{muster: string, beschreibung: string, css: string, markup: string, script: string}|null $hero
 * @property string|null $hero_error
 * @property list<array{bereich: string, aenderung: string}>|null $check_notes
 * @property string|null $source_summary
 * @property list<string>|null $additions Was die KI aus eigenem Wissen ergänzt hat
 * @property string|null $error
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at Weich gelöscht: Fotos sind weg, die Kosten bleiben erhalten
 */
#[Fillable(['status', 'step', 'title', 'subject', 'level', 'topic', 'notes', 'prompt', 'photo_count', 'with_hero', 'schema_version', 'content', 'hero_plan', 'hero', 'hero_error', 'check_notes', 'source_summary', 'additions', 'error', 'published_at'])]
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Wie der Standardwert in der Datenbank, damit auch ungespeicherte Lernseiten ihn haben.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'photo_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => LessonStatus::class,
            'with_hero' => 'boolean',
            'content' => 'array',
            'hero_plan' => 'array',
            'hero' => 'array',
            'check_notes' => 'array',
            'additions' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Ohne Fotos erstellt (Thema oder Auftrag): Inhalt stammt aus dem Wissen der KI.
     */
    public function isFromTopic(): bool
    {
        return $this->photo_count === 0 && ($this->topic !== null || $this->prompt !== null);
    }

    /**
     * Titel für Listen, auch bevor die KI einen Titel gesetzt hat.
     */
    public function displayTitle(): string
    {
        return $this->title ?? $this->topic ?? ($this->prompt ? Str::limit($this->prompt, 60) : 'Neue Lernseite');
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
