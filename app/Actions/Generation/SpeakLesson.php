<?php

namespace App\Actions\Generation;

use App\Lessons\Speech\ElevenLabs;
use App\Lessons\Speech\SpeechFailed;
use App\Lessons\Speech\Texts;
use App\Models\Lesson;
use App\Models\SpeechClip;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Has ElevenLabs read the foreign words of a language lesson aloud. Clips are shared by all lessons,
 * so only new words cost credits. Never fails: missing clips fall back to the browser voice.
 */
class SpeakLesson
{
	public function __construct(private ElevenLabs $elevenLabs) {}

	/**
	 * @return array{created: int, reused: int, credits: int, stopped: 'limit'|'quota'|'error'|null}
	 */
	public function handle(Lesson $lesson): array
	{
		$run = ['created' => 0, 'reused' => 0, 'credits' => 0, 'stopped' => null];
		$userId = $lesson->child?->user_id;

		if ($lesson->content === null || $userId === null || ! $lesson->speaksWithElevenLabs()) {
			return $run;
		}

		$lang = (string) $lesson->speechLang();
		$voiceId = (string) config('speech.voices.'.strtolower(explode('-', $lang)[0]));
		$model = (string) config('speech.model');
		$disk = Storage::disk('speech');
		$characters = 0;

		foreach (Texts::texts($lesson->content) as $text) {
			$spoken = Texts::spokenText($text);
			$hash = Texts::hash($spoken, $lang, $voiceId, $model);
			$clip = SpeechClip::firstWhere('hash', $hash);

			if ($clip !== null && $disk->exists($clip->path())) {
				$run['reused']++;

				continue;
			}

			$characters += mb_strlen($spoken);
			if ($characters > config('speech.max_characters_per_lesson')) {
				$run['stopped'] = 'limit';

				break;
			}

			$started = hrtime(true);
			$log = fn (string $status, int $credits, ?string $error = null) => $lesson->generations()->create([
				'user_id' => $userId,
				'step' => 'speech',
				'model' => $model,
				'status' => $status,
				'credits' => $credits,
				'cost_usd' => $credits * (float) config('speech.price_per_1000_characters') / 1000,
				'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
				'error' => $error ? mb_substr($error, 0, 2000) : null,
			]);

			try {
				$result = $this->elevenLabs->synthesize($spoken, $lang, $voiceId);
			} catch (SpeechFailed $e) {
				$log('error', 0, $e->getMessage());
				Log::warning("Aussprache für Lernseite {$lesson->id} abgebrochen: {$e->getMessage()}");
				// Another word would fail the same way (quota, key, voice): stop here
				$run['stopped'] = $e->quotaExceeded ? 'quota' : 'error';

				break;
			}

			$log('ok', $result->credits);

			$clip = SpeechClip::updateOrCreate(['hash' => $hash], [
				'lang' => $lang,
				'voice_id' => $voiceId,
				'model' => $model,
				'text' => $spoken,
				'credits' => $result->credits,
			]);
			$disk->put($clip->path(), $result->audio);

			$run['created']++;
			$run['credits'] += $result->credits;
		}

		return $run;
	}
}
