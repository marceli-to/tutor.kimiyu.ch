<?php

namespace App\Actions\Generation;

use App\Lessons\Ai\ModelException;
use App\Lessons\Ai\Prompts;
use App\Lessons\Corrections;
use App\Models\Lesson;
use Illuminate\Support\Facades\Log;

class CheckLesson
{
	public function __construct(private CallModel $callModel) {}

	/**
	 * Second pass that corrects factual errors. The check returns only corrections;
	 * invalid ones are discarded. If the call fails, the page stays as it is.
	 */
	public function handle(Lesson $lesson): void
	{
		try {
			$corrections = $this->callModel->handle($lesson, Prompts::check($lesson, $lesson->content))->data['corrections'] ?? [];
		} catch (ModelException $e) {
			Log::warning('Prüf-Call fehlgeschlagen', ['lesson' => $lesson->id, 'error' => $e->detail ?? $e->getMessage()]);

			return;
		}

		$result = Corrections::apply($lesson->content, $corrections);

		if ($result['rejected'] !== []) {
			Log::warning('Korrekturen der Prüfung verworfen', ['lesson' => $lesson->id, 'rejected' => $result['rejected']]);
		}

		$lesson->update([
			'title' => $result['content']['meta']['title'],
			'content' => $result['content'],
			// One change can need several corrections (e.g. options and solution): one note is enough.
			'check_notes' => array_values(array_unique(array_map(
				fn (array $c) => ['area' => $c['area'], 'change' => $c['change']],
				$result['applied'],
			), SORT_REGULAR)),
		]);
	}
}
