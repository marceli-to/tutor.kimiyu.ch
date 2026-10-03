<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An interactive graphic of a lesson. Graphic 1 is at the top, 2 and 3 in the matching section.
 *
 * @property int $id
 * @property int $lesson_id
 * @property int $position 1–3
 * @property string|null $request The parents' wish
 * @property string|null $pattern Requested pattern (GraphicPattern)
 * @property array{pattern: string, idea: string}|null $plan null: no plan (yet), e.g. because the wish doesn't fit the material
 * @property array{pattern: string, description: string, css: string, markup: string, script: string}|null $graphic
 * @property string|null $error Note for the parents why the graphic is missing
 * @property bool $hidden Hidden by the parents (block removed); comes back with «Grafik neu erstellen»
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['position', 'request', 'pattern', 'plan', 'graphic', 'error', 'hidden'])]
class LessonGraphic extends Model
{
	/** @var array<string, mixed> */
	protected $attributes = ['hidden' => false];

	protected function casts(): array
	{
		return [
			'position' => 'integer',
			'plan' => 'array',
			'graphic' => 'array',
			'hidden' => 'boolean',
		];
	}

	/**
	 * @return BelongsTo<Lesson, $this>
	 */
	public function lesson(): BelongsTo
	{
		// Also for deleted lessons, e.g. in a job still waiting in the queue
		return $this->belongsTo(Lesson::class)->withTrashed();
	}
}
