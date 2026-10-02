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
 * @property 'none'|'auto'|'custom' $graphics_mode Keine Grafik, die KI entscheidet oder nach Wunsch der Eltern
 * @property 'neu'|'pruefung' $purpose Neuer Stoff oder Prüfungsvorbereitung
 * @property 'kurz'|'normal'|'ausfuehrlich' $scope Umfang der Seite
 * @property list<'quiz'|'sortieren'|'karten'|'lueckentext'>|null $modules Erlaubte Lernmodule, null bei alten Lernseiten: alle
 * @property int|null $schema_version
 * @property array<string, mixed>|null $content
 * @property list<array{bereich: string, aenderung: string}>|null $check_notes
 * @property string|null $source_summary
 * @property list<string>|null $additions Was die KI aus eigenem Wissen ergänzt hat
 * @property string|null $error
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at Weich gelöscht: Fotos sind weg, die Kosten bleiben erhalten
 */
#[Fillable(['status', 'step', 'title', 'subject', 'level', 'topic', 'notes', 'prompt', 'photo_count', 'graphics_mode', 'purpose', 'scope', 'modules', 'schema_version', 'content', 'check_notes', 'source_summary', 'additions', 'error', 'published_at'])]
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory, SoftDeletes;

    public const PURPOSES = ['neu', 'pruefung'];

    public const SCOPES = ['kurz', 'normal', 'ausfuehrlich'];

    public const MODULES = ['quiz', 'sortieren', 'karten', 'lueckentext'];

    /**
     * Wie der Standardwert in der Datenbank, damit auch ungespeicherte Lernseiten ihn haben.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'photo_count' => 0,
        'graphics_mode' => 'auto',
        'purpose' => 'neu',
        'scope' => 'normal',
    ];

    protected function casts(): array
    {
        return [
            'status' => LessonStatus::class,
            'photo_count' => 'integer',
            'content' => 'array',
            'check_notes' => 'array',
            'additions' => 'array',
            'modules' => 'array',
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
     * Erlaubte Lernmodule; alte Lernseiten ohne Liste erlauben alle.
     *
     * @return list<'quiz'|'sortieren'|'karten'|'lueckentext'>
     */
    public function allowedModules(): array
    {
        return $this->modules ?? self::MODULES;
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
     * @return HasMany<LessonGraphic, $this>
     */
    public function graphics(): HasMany
    {
        return $this->hasMany(LessonGraphic::class)->orderBy('position');
    }

    public function graphic(int $position): ?LessonGraphic
    {
        return $this->graphics()->where('position', $position)->first();
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
