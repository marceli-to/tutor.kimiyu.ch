<?php

namespace App\Actions\Lessons;

use App\Enums\LessonStatus;
use App\Lessons\GenerationPipeline;
use App\Lessons\ImageProcessor;
use App\Models\Child;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A new lesson from photos and/or a prompt: stores photos, child (if new), lesson and graphic wishes,
 * then starts the generation.
 */
class CreateLesson
{
	/**
	 * @param  array<string, mixed>  $data  validated StoreLessonRequest data (trimmed, empty strings as null)
	 * @param  string|null  $childLevel  level of the chosen child, if it has one
	 *
	 * @throws ValidationException when a photo cannot be read
	 */
	public function handle(User $user, array $data, ?string $childLevel): Lesson
	{
		// Process all photos first, so a broken image doesn't leave anything half stored
		$images = [];
		/** @var array<int, UploadedFile> $files */
		$files = $data['images'] ?? [];
		foreach ($files as $index => $file) {
			try {
				$images[] = ImageProcessor::process($file);
			} catch (InvalidArgumentException) {
				throw ValidationException::withMessages([
					"images.$index" => 'Foto '.($index + 1).' konnte nicht gelesen werden. Bitte als JPEG speichern und nochmals hochladen.',
				]);
			}
		}

		$lesson = DB::transaction(function () use ($user, $data, $childLevel, $images) {
			$level = $data['level'] ?? $childLevel;

			$child = isset($data['child_id'])
				? $user->children()->findOrFail($data['child_id'])
				: $user->children()->create([
					'name' => $data['child_name'],
					'level' => $level,
				]);

			/** @var Child $child */
			$lesson = $child->lessons()->create([
				'status' => LessonStatus::Draft,
				// Empty: the AI detects the subject in the analysis
				'subject' => $data['subject'] ?? null,
				// Empty: derived from the subject
				'profile' => $data['profile'] ?? null,
				'level' => $level,
				'prompt' => $data['prompt'] ?? null,
				'photo_count' => count($images),
				'graphics_mode' => $data['graphics_mode'],
				'purpose' => $data['purpose'],
				'scope' => $data['scope'],
				'modules' => array_values($data['modules']),
			]);

			// The parents' wishes as graphics 1 to 3
			foreach (array_values($data['graphics'] ?? []) as $index => $wish) {
				$lesson->graphics()->create([
					'position' => $index + 1,
					'request' => $wish['description'],
					'pattern' => $wish['pattern'] ?? null,
				]);
			}

			foreach ($images as $position => $image) {
				$path = $lesson->id.'/'.Str::random(32).'.jpg';
				Storage::disk('lesson-images')->put($path, $image['data']);

				$lesson->images()->create([
					'path' => $path,
					'mime_type' => $image['mime'],
					'size' => strlen($image['data']),
					'position' => $position,
				]);
			}

			return $lesson;
		});

		GenerationPipeline::start($lesson);

		return $lesson;
	}
}
