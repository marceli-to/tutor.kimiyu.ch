<?php

namespace App\Http\PageData;

use App\Lessons\GraphicPattern;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Props for lessons/Create: children, limits, patterns and the defaults from earlier lessons.
 */
class CreateLessonPage
{
	public function __construct(private User $user) {}

	/**
	 * @return array<string, mixed>
	 */
	public function props(): array
	{
		// One query for both defaults: newest first, deleted ones excluded
		$recent = Lesson::query()
			->whereIn('child_id', $this->user->children()->select('id'))
			->latest()
			->latest('id')
			->get(['id', 'child_id', 'subject', 'purpose', 'scope', 'modules', 'graphics_mode', 'created_at']);

		return [
			'children' => $this->user->children()->orderBy('name')->get(['id', 'name', 'level']),
			'maxImages' => config('lessons.images.max_count'),
			'maxEdge' => config('lessons.images.max_edge'),
			'patterns' => array_map(
				fn (GraphicPattern $pattern) => ['value' => $pattern->value, 'label' => $pattern->label()],
				GraphicPattern::cases(),
			),
			'lastSettings' => self::lastSettings($recent),
			'lastByChild' => self::lastByChild($recent),
			'scopeInfo' => config('lessons.scope'),
		];
	}

	/**
	 * Settings of the latest lesson per child and subject, key «{childId}|{subject}».
	 * The subject is free text, so it is lower-cased and trimmed.
	 *
	 * @param  Collection<int, Lesson>  $recent
	 * @return array<string, array{purpose: string, scope: string, modules: list<string>, graphics_mode: string}>
	 */
	private static function lastSettings(Collection $recent): array
	{
		return $recent
			// Subject not detected yet: belongs to no subject
			->whereNotNull('subject')
			->unique(fn (Lesson $lesson) => self::settingsKey($lesson->child_id, $lesson->subject))
			->mapWithKeys(fn (Lesson $lesson) => [
				self::settingsKey($lesson->child_id, $lesson->subject) => [
					'purpose' => $lesson->purpose,
					'scope' => $lesson->scope,
					'modules' => $lesson->allowedModules(),
					'graphics_mode' => $lesson->graphics_mode,
				],
			])
			->all();
	}

	/**
	 * Settings of the latest lesson per child, whatever the subject, for the «last» preset.
	 * Custom graphic wishes apply only to that one page, so they become «auto».
	 *
	 * @param  Collection<int, Lesson>  $recent
	 * @return array<int, array{purpose: string, scope: string, modules: list<string>, graphics_mode: string}>
	 */
	private static function lastByChild(Collection $recent): array
	{
		return $recent
			->unique('child_id')
			->mapWithKeys(fn (Lesson $lesson) => [
				$lesson->child_id => [
					'purpose' => $lesson->purpose,
					'scope' => $lesson->scope,
					'modules' => $lesson->allowedModules(),
					'graphics_mode' => $lesson->graphics_mode === 'custom' ? 'auto' : $lesson->graphics_mode,
				],
			])
			->all();
	}

	private static function settingsKey(int $childId, ?string $subject): string
	{
		return $childId.'|'.mb_strtolower(trim((string) $subject));
	}
}
