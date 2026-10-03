<?php

namespace App\Actions\Generation;

use App\Lessons\Ai\ModelException;
use App\Lessons\Ai\Prompts;
use App\Lessons\ContentValidator;
use App\Lessons\GenerationFailed;
use App\Models\Lesson;

/**
 * Step 2 of the generation, after the plan is confirmed: text part, modules, repair and validation.
 */
class WriteLesson
{
	public function __construct(
		private CallModel $callModel,
		private LoadLessonImages $loadImages,
		private DeleteLessonImages $deleteImages,
	) {}

	/**
	 * The text part from the photos and the confirmed plan (a call of its own; with the analysis the schema
	 * is too large for the API), then the modules. On rule violations one repair call per affected part.
	 * If a call fails, a new attempt starts again at the text part.
	 *
	 * @throws GenerationFailed when no usable content comes out
	 * @throws ModelException on API errors
	 */
	public function handle(Lesson $lesson): void
	{
		$images = $this->loadImages->handle($lesson);

		// The text part with the same photos, so the terms follow the book
		$page = $this->callModel->handle($lesson, Prompts::pageRequest($lesson, $images))->data['page'] ?? null;

		if (! is_array($page)) {
			throw new GenerationFailed('Die KI hat keinen gültigen Inhalt geliefert.', 'Der Textteil fehlt in der Antwort.');
		}

		// The whole page is too large for one structured answer, so the modules come separately
		$lesson->update(['step' => 'modules']);
		$modules = $this->callModel->handle($lesson, Prompts::modules($lesson, $page))->data['modules'] ?? [];
		$content = self::assemble($lesson, $page, $modules);

		// The subject is known now: profile chosen by the parents or derived from the (detected) subject
		$profile = $lesson->resolvedProfile();

		// Repair faulty parts once
		foreach (ContentValidator::errorsByPart($content, strict: true, profile: $profile) as $part => $errors) {
			if ($errors === []) {
				continue;
			}

			$repaired = $this->callModel->handle($lesson, Prompts::repair($lesson, $content, $part, $errors))->data[$part] ?? null;

			if (is_array($repaired)) {
				$content = $part === 'modules' ? self::assemble($lesson, $content, $repaired) : self::assemble($lesson, $repaired, $content['modules']);
			}
		}

		$errors = ContentValidator::errors($content, strict: true, profile: $profile);

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
	 * Joins text part and modules into one page, in the order of the fixtures. Parts the profile's schema
	 * leaves out («try_it», modules) become null, so stored content always has the same shape.
	 * The title comes from the confirmed plan.
	 *
	 * @param  array<string, mixed>  $page  text part (or whole page whose modules are replaced)
	 * @param  array<string, mixed>  $modules
	 * @return array<string, mixed>
	 */
	private static function assemble(Lesson $lesson, array $page, array $modules): array
	{
		$profile = $lesson->resolvedProfile();
		$reflect = $page['reflect'] ?? null;
		unset($page['modules'], $page['reflect']);

		if (! $profile->allowsExperiments()) {
			$page['try_it'] = null;
		}

		// The parents may have renamed the page in the plan; the title is theirs
		if ($lesson->plan !== null && is_array($page['meta'] ?? null)) {
			$page['meta']['title'] = $lesson->plan['title'];
		}

		return [...$page, 'modules' => self::onlyAllowed($lesson, $modules), 'reflect' => $reflect];
	}

	/**
	 * Sets modules the parents or the profile don't allow to null, even if the AI delivers them anyway.
	 * If none is left, the content validation reports it like any other error.
	 *
	 * @param  array<string, mixed>  $modules
	 * @return array<string, mixed>
	 */
	private static function onlyAllowed(Lesson $lesson, array $modules): array
	{
		foreach (array_diff(ContentValidator::MODULES, $lesson->generatedModules()) as $module) {
			$modules[$module] = null;
		}

		return $modules;
	}
}
