<?php

namespace App\Actions\Generation;

use App\Lessons\Ai\ModelException;
use App\Lessons\Ai\Prompts;
use App\Lessons\GraphicValidator;
use App\Models\Lesson;

class GenerateGraphic
{
	public function __construct(private CallModel $callModel) {}

	/**
	 * The graphic at position $position. If it fails, only this graphic is missing, with a note.
	 * When regenerating ($keepExisting) the previous graphic stays if the new one fails.
	 * Without a plan (deselected, no pattern fits or the wish doesn't fit the material) nothing happens.
	 *
	 * @return bool whether a new graphic was stored
	 */
	public function handle(Lesson $lesson, int $position, bool $keepExisting = false): bool
	{
		$graphic = $lesson->graphic($position);

		if ($graphic?->plan === null) {
			return false;
		}

		$fail = function (string $message) use ($graphic, $keepExisting) {
			$old = $keepExisting ? $graphic->graphic : null;

			$graphic->update([
				'graphic' => $old,
				'error' => $old ? $message.' Die bisherige Grafik bleibt.' : $message,
			]);
		};

		try {
			$result = $this->callModel->handle($lesson, Prompts::graphic($lesson, $graphic))->data;
			$errors = GraphicValidator::errors($result);

			if ($errors !== []) {
				$result = $this->callModel->handle($lesson, Prompts::graphicRepair($graphic, $result, $errors))->data;
				$errors = GraphicValidator::errors($result);
			}
		} catch (ModelException $e) {
			$fail($e->getMessage());

			return false;
		}

		if ($errors !== []) {
			$fail('Die Grafik war fehlerhaft: '.implode(' ', $errors));

			return false;
		}

		$graphic->update([
			'graphic' => [
				'pattern' => $result['pattern'],
				'description' => $result['description'],
				'css' => $result['css'],
				'markup' => $result['markup'],
				'script' => $result['script'],
			],
			'error' => null,
			// A regenerated graphic is visible again (at the end of the last section, without a block)
			'hidden' => false,
		]);

		return true;
	}
}
