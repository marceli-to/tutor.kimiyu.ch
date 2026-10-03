<?php

use App\Lessons\Speech\ElevenLabs;
use App\Lessons\Speech\SpeechFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
	config()->set('speech.key', 'test-key');
	config()->set('speech.model', 'eleven_v4');
	config()->set('speech.output_format', 'mp3_44100_64');
	config()->set('speech.language_code_models', ['eleven_v4', 'eleven_flash_v2_5']);
	config()->set('speech.credits_per_character', ['eleven_v4' => 1.0, 'eleven_flash_v2_5' => 0.5]);
});

it('sends the text with model and language to the voice', function () {
	Http::fake(['api.elevenlabs.io/*' => Http::response('MP3', 200, ['Content-Type' => 'audio/mpeg'])]);

	$result = app(ElevenLabs::class)->synthesize('le livre', 'fr-FR', 'voice-1');

	expect($result->audio)->toBe('MP3');

	Http::assertSent(fn (Request $request) => $request->url() === 'https://api.elevenlabs.io/v1/text-to-speech/voice-1?output_format=mp3_44100_64'
		&& $request->method() === 'POST'
		&& $request->hasHeader('xi-api-key', 'test-key')
		&& $request->data() === ['text' => 'le livre', 'model_id' => 'eleven_v4', 'language_code' => 'fr']);
});

it('leaves out the language for models that reject it', function () {
	config()->set('speech.model', 'eleven_multilingual_v2');
	Http::fake(['api.elevenlabs.io/*' => Http::response('MP3')]);

	app(ElevenLabs::class)->synthesize('chat', 'fr-FR', 'voice-1');

	Http::assertSent(fn (Request $request) => $request->data() === ['text' => 'chat', 'model_id' => 'eleven_multilingual_v2']);
});

it('counts the credits from the characters and the model', function () {
	Http::fake(['api.elevenlabs.io/*' => Http::response('MP3')]);

	expect(app(ElevenLabs::class)->synthesize('parlé', 'fr-FR', 'voice-1')->credits)->toBe(5);

	config()->set('speech.model', 'eleven_flash_v2_5');

	expect(app(ElevenLabs::class)->synthesize('le livre', 'fr-FR', 'voice-1')->credits)->toBe(4);
});

it('reports an exhausted quota', function (int $status) {
	Http::fake(['api.elevenlabs.io/*' => Http::response(['detail' => ['status' => 'quota_exceeded', 'message' => 'This request exceeds your quota.']], $status)]);

	try {
		app(ElevenLabs::class)->synthesize('chat', 'fr-FR', 'voice-1');
		$this->fail('No exception');
	} catch (SpeechFailed $e) {
		expect($e->quotaExceeded)->toBeTrue()
			->and($e->getMessage())->toContain('This request exceeds your quota.');
	}
})->with([401, 429]);

it('reports other errors without the quota flag', function () {
	Http::fake(['api.elevenlabs.io/*' => Http::response(['detail' => ['status' => 'payment_required', 'message' => 'Free users cannot use library voices via the API.']], 402)]);

	try {
		app(ElevenLabs::class)->synthesize('chat', 'fr-FR', 'voice-1');
		$this->fail('No exception');
	} catch (SpeechFailed $e) {
		expect($e->quotaExceeded)->toBeFalse()
			->and($e->getMessage())->toContain('402')
			->and($e->getMessage())->toContain('Free users cannot use library voices');
	}
});

it('reports connection errors', function () {
	Http::fake(fn () => throw new ConnectionException('timeout'));

	expect(fn () => app(ElevenLabs::class)->synthesize('chat', 'fr-FR', 'voice-1'))
		->toThrow(SpeechFailed::class, 'timeout');
});
