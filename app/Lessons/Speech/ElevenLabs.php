<?php

namespace App\Lessons\Speech;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Text to speech with the ElevenLabs API.
 */
class ElevenLabs
{
	/**
	 * @param  string  $lang  BCP 47, e.g. fr-FR
	 *
	 * @throws SpeechFailed
	 */
	public function synthesize(string $text, string $lang, string $voiceId): SpeechResult
	{
		$model = (string) config('speech.model');
		$body = ['text' => $text, 'model_id' => $model];

		if (in_array($model, config('speech.language_code_models'), true)) {
			$body['language_code'] = strtolower(explode('-', $lang)[0]);
		}

		try {
			$response = Http::withHeaders(['xi-api-key' => (string) config('speech.key')])
				->timeout(60)
				->post("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}?output_format=".config('speech.output_format'), $body);
		} catch (ConnectionException $e) {
			throw new SpeechFailed($e->getMessage());
		}

		if ($response->failed()) {
			$detail = $response->json('detail');
			$status = is_array($detail) ? ($detail['status'] ?? $detail['code'] ?? null) : null;
			$message = is_array($detail) ? ($detail['message'] ?? '') : (string) $response->body();

			throw new SpeechFailed(
				"ElevenLabs {$response->status()}: {$message}",
				quotaExceeded: $status === 'quota_exceeded',
			);
		}

		$factor = (float) (config('speech.credits_per_character')[$model] ?? 1.0);

		return new SpeechResult(
			audio: $response->body(),
			credits: (int) ceil(mb_strlen($text) * $factor),
		);
	}
}
