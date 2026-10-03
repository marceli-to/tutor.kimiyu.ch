<?php

namespace App\Models;

use App\Enums\LessonStatus;
use App\Lessons\Profile;
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
 * @property string|null $subject Empty until the AI has detected the subject
 * @property bool $subject_detected The AI detected the subject; the parents did not give one
 * @property Profile|null $profile Chosen by the parents; null: derived from the subject
 * @property string $level
 * @property string|null $topic
 * @property string|null $notes
 * @property string|null $prompt The parents' request, free text
 * @property int $photo_count
 * @property 'none'|'auto'|'custom' $graphics_mode No graphic, the AI decides, or as the parents wish
 * @property 'new'|'exam' $purpose New material or exam preparation
 * @property 'short'|'normal'|'detailed' $scope Scope of the page
 * @property list<'quiz'|'sorting'|'flashcards'|'cloze'|'exercises'>|null $modules Allowed learning modules, null for old lessons: all
 * @property int|null $schema_version
 * @property array<string, mixed>|null $content
 * @property list<array{area: string, change: string}>|null $check_notes
 * @property string|null $source_summary
 * @property list<string>|null $additions What the AI added from its own knowledge
 * @property array{title: string, key_idea: string, sections: list<array{title: string, goal: string|null}>, note: string|null, removed_additions: list<string>}|null $plan
 * @property Carbon|null $plan_confirmed_at The parents (or the pipeline without review) confirmed the plan
 * @property bool $review_plan Stop after the planning step until the parents confirm the plan
 * @property string|null $error
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at Soft-deleted: photos are gone, the costs are kept
 */
#[Fillable(['status', 'step', 'title', 'subject', 'subject_detected', 'profile', 'level', 'topic', 'notes', 'prompt', 'photo_count', 'graphics_mode', 'purpose', 'scope', 'modules', 'schema_version', 'content', 'check_notes', 'source_summary', 'additions', 'plan', 'plan_confirmed_at', 'review_plan', 'error', 'published_at'])]
class Lesson extends Model
{
	/** @use HasFactory<LessonFactory> */
	use HasFactory, SoftDeletes;

	/** Shown while the AI hasn't detected the subject yet */
	public const SUBJECT_PENDING = 'Fach wird erkannt …';

	/** Shown when the generation failed before the AI detected the subject */
	public const SUBJECT_UNKNOWN = 'Fach unbekannt';

	public const PURPOSES = ['new', 'exam'];

	public const SCOPES = ['short', 'normal', 'detailed'];

	// «exercises» only has an effect in math and geometry lessons (see Profile::modules())
	public const MODULES = ['quiz', 'sorting', 'flashcards', 'cloze', 'exercises'];

	/**
	 * Like the database default, so unsaved lessons have it too.
	 *
	 * @var array<string, mixed>
	 */
	protected $attributes = [
		'photo_count' => 0,
		'subject_detected' => false,
		'graphics_mode' => 'auto',
		'purpose' => 'new',
		'scope' => 'normal',
		'review_plan' => false,
	];

	protected function casts(): array
	{
		return [
			'status' => LessonStatus::class,
			'photo_count' => 'integer',
			'subject_detected' => 'boolean',
			'profile' => Profile::class,
			'content' => 'array',
			'check_notes' => 'array',
			'additions' => 'array',
			'modules' => 'array',
			'plan' => 'array',
			'plan_confirmed_at' => 'datetime',
			'review_plan' => 'boolean',
			'published_at' => 'datetime',
		];
	}

	/**
	 * Created without photos (topic or request): the content comes from the AI's knowledge.
	 */
	public function isFromTopic(): bool
	{
		return $this->photo_count === 0 && ($this->topic !== null || $this->prompt !== null);
	}

	/**
	 * Allowed learning modules; old lessons without a list allow all.
	 *
	 * @return list<'quiz'|'sorting'|'flashcards'|'cloze'|'exercises'>
	 */
	public function allowedModules(): array
	{
		return $this->modules ?? self::MODULES;
	}

	/**
	 * Modules a generation fills: the profile's, within the parents' choice. The parents only choose
	 * among MODULES; a module of the profile beyond that (find_the_mistake in german) always comes with it.
	 * If the choice leaves nothing for the profile (only exercises in a biology lesson), the quiz.
	 *
	 * @return list<string>
	 */
	public function generatedModules(): array
	{
		$modules = array_values(array_filter(
			$this->resolvedProfile()->modules(),
			fn (string $module) => ! in_array($module, self::MODULES, true) || in_array($module, $this->allowedModules(), true),
		));

		return $modules !== [] ? $modules : ['quiz'];
	}

	/**
	 * The parents' profile, otherwise the one of the subject. Not stored: a subject the AI
	 * detects again on a retry brings its own profile, and old lessons have none.
	 */
	public function resolvedProfile(): Profile
	{
		return $this->profile ?? Profile::forSubject($this->subject);
	}

	/**
	 * Language for reading foreign words aloud (BCP 47); null: no speaker buttons.
	 */
	public function speechLang(): ?string
	{
		return $this->resolvedProfile()->speechLang($this->subject);
	}

	/**
	 * Whether ElevenLabs reads this lesson's words: a language lesson with a key and a voice for its language.
	 */
	public function speaksWithElevenLabs(): bool
	{
		$lang = $this->speechLang();

		return $lang !== null
			&& filled(config('speech.key'))
			&& filled(config('speech.voices.'.strtolower(explode('-', $lang)[0])));
	}

	/**
	 * Upper bound of the sections for the scope, e.g. 3 for «1–3».
	 */
	public function maxSections(): int
	{
		$range = (config("lessons.scope.{$this->scope}") ?? config('lessons.scope.normal'))['sections'];

		return (int) last(preg_split('/\D+/', $range) ?: [$range]);
	}

	/**
	 * Waiting for the parents to confirm the plan.
	 */
	public function isPlanned(): bool
	{
		return $this->status === LessonStatus::Planned;
	}

	/**
	 * A part of a finished page is being regenerated. Status and publication stay as they are meanwhile.
	 */
	public function isRegenerating(): bool
	{
		return $this->step !== null && str_starts_with($this->step, 'regenerate-');
	}

	/**
	 * Whether the lesson still exists. The model in a job does not know about a deletion
	 * in the meantime, and update() would write into the deleted row anyway.
	 */
	public function stillExists(): bool
	{
		return self::whereKey($this->id)->exists();
	}

	/**
	 * Ready for the child. Not while a part is being regenerated: it would ask for a new check right after.
	 */
	public function canBePublished(): bool
	{
		return $this->status === LessonStatus::Review && $this->content !== null && ! $this->isRegenerating();
	}

	/**
	 * The parents can correct the content. Not while a part is being regenerated: the job would overwrite the changes.
	 */
	public function isEditable(): bool
	{
		return $this->content !== null
			&& ! $this->isRegenerating()
			&& in_array($this->status, [LessonStatus::Review, LessonStatus::Published], true);
	}

	/**
	 * The subject for display, also before (or without) the detection.
	 */
	public function subjectLabel(): string
	{
		return $this->subject ?? ($this->status === LessonStatus::Failed ? self::SUBJECT_UNKNOWN : self::SUBJECT_PENDING);
	}

	/**
	 * Title for lists, also before the AI has set a title.
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
