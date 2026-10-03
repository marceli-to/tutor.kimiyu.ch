<?php

namespace App\Actions\Generation;

use App\Lessons\Ai\ModelException;
use App\Lessons\Ai\Prompts;
use App\Lessons\GenerationFailed;
use App\Lessons\LessonGone;
use App\Models\Lesson;
use Illuminate\Support\Str;

/**
 * Step 1 of the generation: the analysis call is the planning step. It reads the photos once and stores
 * subject, summary, additions, the plans for the graphics and the plan of the page (title, key idea, sections).
 * The parents may review the plan before the expensive steps (WriteLesson) run.
 */
class PlanLesson
{
	public function __construct(
		private CallModel $callModel,
		private LoadLessonImages $loadImages,
	) {}

	/**
	 * @throws GenerationFailed when the source is unusable or no plan comes out
	 * @throws ModelException on API errors
	 */
	public function handle(Lesson $lesson): void
	{
		$images = $this->loadImages->handle($lesson);

		// A fresh analysis detects the subject again, unless the parents gave it
		if ($lesson->subject_detected) {
			$lesson->update(['subject' => null, 'subject_detected' => false]);
		}

		$data = $this->callModel->handle($lesson, Prompts::analysis($lesson, $images))->data;

		if (! ($data['source']['readable'] ?? false)) {
			throw new GenerationFailed(
				($data['source']['problem'] ?? null) ?: match (true) {
					! $lesson->isFromTopic() => 'Auf den Fotos war kein Schulstoff zu erkennen.',
					$lesson->prompt !== null => 'Zu diesem Auftrag konnte keine Lernseite erstellt werden.',
					// Old lessons from a topic
					default => 'Zu diesem Thema konnte keine Lernseite erstellt werden.',
				},
			);
		}

		$plan = self::plan($lesson, $data);

		// Store subject, summary and plans; the next calls need them.
		// The subject only if the parents didn't give one.
		$lesson->update([
			'subject_detected' => $lesson->subject === null,
			'subject' => $lesson->subject ?? self::detectedSubject($data['subject'] ?? null),
			'source_summary' => (string) ($data['summary'] ?? ''),
			// Without photos everything is added; a list would be meaningless
			'additions' => $lesson->isFromTopic()
				? null
				: (array_values(array_filter((array) ($data['additions'] ?? []), 'is_string')) ?: null),
		]);

		$this->storeGraphicPlans($lesson, (array) ($data['graphic_plans'] ?? []));

		$lesson->update([
			'title' => $plan['title'],
			'plan' => $plan,
			'plan_confirmed_at' => null,
		]);
	}

	/**
	 * The plan of the page from the analysis. Sections beyond the scope are cut, empty goals become null.
	 *
	 * @param  array<string, mixed>  $data
	 * @return array{title: string, key_idea: string, sections: list<array{title: string, goal: string|null}>, note: null, removed_additions: list<string>}
	 *
	 * @throws GenerationFailed without a title or a section
	 */
	private static function plan(Lesson $lesson, array $data): array
	{
		$sections = [];

		foreach ((array) ($data['sections'] ?? []) as $section) {
			$title = is_array($section) && is_string($section['title'] ?? null) ? trim($section['title']) : '';
			$goal = is_string($section['goal'] ?? null) ? trim($section['goal']) : '';

			if ($title !== '') {
				$sections[] = ['title' => $title, 'goal' => $goal !== '' ? $goal : null];
			}
		}

		$title = is_string($data['title'] ?? null) ? Str::limit(trim($data['title']), 120, '') : '';

		if ($sections === [] || $title === '') {
			throw new GenerationFailed('Die KI hat keinen gültigen Plan geliefert.', 'Titel oder Abschnitte fehlen in der Analyse.');
		}

		return [
			'title' => $title,
			'key_idea' => is_string($data['key_idea'] ?? null) ? trim($data['key_idea']) : '',
			'sections' => array_slice($sections, 0, $lesson->maxSections()),
			'note' => null,
			'removed_additions' => [],
		];
	}

	/**
	 * Stores the analysis plans per graphic. «auto»: only graphic 1; «custom»: only requested graphics;
	 * «none»: none. Without a plan the AI's note is stored as the graphic's error.
	 *
	 * @param  array<mixed>  $plans
	 */
	private function storeGraphicPlans(Lesson $lesson, array $plans): void
	{
		// updateOrCreate() would bring back the rows of a deleted lesson
		if (! $lesson->stillExists()) {
			throw new LessonGone;
		}

		$positions = match ($lesson->graphics_mode) {
			'none' => [],
			'custom' => $lesson->graphics()->pluck('position')->all(),
			default => [1],
		};

		$planned = [];

		foreach ($plans as $entry) {
			$number = is_array($entry) ? ($entry['number'] ?? null) : null;

			if (! is_int($number) || ! in_array($number, $positions, true) || isset($planned[$number])) {
				continue;
			}

			$plan = is_array($entry['plan'] ?? null) ? $entry['plan'] : null;
			$hint = is_string($entry['note'] ?? null) && trim($entry['note']) !== '' ? trim($entry['note']) : null;

			$lesson->graphics()->updateOrCreate(['position' => $number], [
				'plan' => $plan,
				'error' => $plan === null ? $hint : null,
			]);
			$planned[$number] = $plan;
		}

		// The parents should know why a wish is missing, even if the AI skipped it
		if ($lesson->graphics_mode === 'custom') {
			$lesson->graphics()->whereNotIn('position', array_keys($planned))->update([
				'plan' => null,
				'error' => 'Für diese Grafik hat die KI keinen Plan erstellt.',
			]);
		} else {
			// A plan from an earlier attempt that the new analysis dropped must not be built; finished graphics stay
			$lesson->graphics()->whereNotIn('position', array_keys($planned))->whereNull('graphic')->delete();
		}
	}

	/**
	 * The subject detected by the AI, shortened; «Allgemein» without a usable answer.
	 */
	private static function detectedSubject(mixed $subject): string
	{
		$subject = is_string($subject) ? Str::limit(trim($subject), 60, '') : '';

		return $subject !== '' ? $subject : 'Allgemein';
	}
}
