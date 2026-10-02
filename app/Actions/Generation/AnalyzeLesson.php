<?php

namespace App\Actions\Generation;

use App\Lessons\Ai\ModelException;
use App\Lessons\Ai\Prompts;
use App\Lessons\ContentValidator;
use App\Lessons\GenerationFailed;
use App\Lessons\LessonGone;
use App\Models\Lesson;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Step 1 of the generation: analysis, text part, modules, repair and validation.
 */
class AnalyzeLesson
{
	public function __construct(
		private CallModel $callModel,
		private DeleteLessonImages $deleteImages,
	) {}

	/**
	 * Photos, request or (for old lessons) topic → summary and plans for the graphics; then the text part
	 * (a call of its own; together the schema is too large for the API) and the modules.
	 * On rule violations one repair call per affected part. If a call fails, a new attempt starts from the beginning.
	 *
	 * @throws GenerationFailed when no usable content comes out
	 * @throws ModelException on API errors
	 */
	public function handle(Lesson $lesson): void
	{
		$images = [];
		$disk = Storage::disk('lesson-images');

		foreach ($lesson->images as $image) {
			// The disk throws on missing files; missing photos are reported clearly below
			$data = $disk->exists($image->path) ? $disk->get($image->path) : null;

			if (is_string($data)) {
				$images[] = ['mime' => $image->mime_type, 'data' => $data];
			}
		}

		$missingImages = $images === [] || count($images) !== $lesson->images->count();

		if (! $lesson->isFromTopic() && $missingImages) {
			throw new GenerationFailed('Es sind keine Fotos mehr vorhanden. Bitte die Lernseite neu erstellen.');
		}

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

		// Store subject, summary and plans first; the next calls need them.
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

		// The text part with the same photos, so the terms follow the book
		$page = $this->callModel->handle($lesson, Prompts::pageRequest($lesson, $images))->data['page'] ?? null;

		if (! is_array($page)) {
			throw new GenerationFailed('Die KI hat keinen gültigen Inhalt geliefert.', 'Der Textteil fehlt in der Antwort.');
		}

		// The whole page is too large for one structured answer, so the modules come separately
		$lesson->update(['step' => 'modules']);
		$modules = $this->callModel->handle($lesson, Prompts::modules($lesson, $page))->data['modules'] ?? [];
		$content = self::assemble($page, self::onlyAllowed($lesson, $modules));

		// Repair faulty parts once
		foreach (ContentValidator::errorsByPart($content, strict: true) as $part => $errors) {
			if ($errors === []) {
				continue;
			}

			$repaired = $this->callModel->handle($lesson, Prompts::repair($lesson, $content, $part, $errors))->data[$part] ?? null;

			if (is_array($repaired)) {
				$content = $part === 'modules' ? self::assemble($content, self::onlyAllowed($lesson, $repaired)) : self::assemble($repaired, $content['modules']);
			}
		}

		$errors = ContentValidator::errors($content, strict: true);

		// After the repair the normal rules are enough; the parents check the rest
		if ($errors !== [] && ContentValidator::errors($content) !== []) {
			throw new GenerationFailed(
				'Die KI hat keinen gültigen Inhalt geliefert.',
				implode(' | ', ContentValidator::errors($content)),
			);
		}

		$lesson->update([
			'title' => $content['meta']['title'],
			'content' => $content,
			'schema_version' => ContentValidator::SCHEMA_VERSION,
		]);

		if (config('lessons.delete_images')) {
			$this->deleteImages->handle($lesson);
		}
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

	/**
	 * Joins text part and modules into one page, in the order of the fixtures.
	 *
	 * @param  array<string, mixed>  $page  text part (or whole page whose modules are replaced)
	 * @param  array<string, mixed>  $modules
	 * @return array<string, mixed>
	 */
	private static function assemble(array $page, array $modules): array
	{
		$reflect = $page['reflect'] ?? null;
		unset($page['modules'], $page['reflect']);

		return [...$page, 'modules' => $modules, 'reflect' => $reflect];
	}

	/**
	 * Sets modules the parents didn't allow to null, even if the AI delivers them anyway.
	 * If none is left, the content validation reports it like any other error.
	 *
	 * @param  array<string, mixed>  $modules
	 * @return array<string, mixed>
	 */
	private static function onlyAllowed(Lesson $lesson, array $modules): array
	{
		foreach (array_diff(Lesson::MODULES, $lesson->allowedModules()) as $module) {
			$modules[$module] = null;
		}

		return $modules;
	}
}
