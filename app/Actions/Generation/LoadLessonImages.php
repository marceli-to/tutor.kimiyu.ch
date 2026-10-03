<?php

namespace App\Actions\Generation;

use App\Lessons\GenerationFailed;
use App\Models\Lesson;
use Illuminate\Support\Facades\Storage;

/**
 * The photos of a lesson for an API call. Planning and writing both send them.
 */
class LoadLessonImages
{
	/**
	 * @return list<array{mime: string, data: string}>
	 *
	 * @throws GenerationFailed when photos are missing
	 */
	public function handle(Lesson $lesson): array
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

		return $images;
	}
}
