<?php

use App\Models\Generation;
use App\Models\SpeechClip;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;

function speechClip(string $hash = 'abc'): SpeechClip
{
	return SpeechClip::create([
		'hash' => $hash,
		'lang' => 'fr-FR',
		'voice_id' => 'voice-1',
		'model' => 'eleven_v4',
		'text' => 'chat',
		'credits' => 4,
	]);
}

it('stores a clip once per hash', function () {
	expect(speechClip()->path())->toBe('abc.mp3');

	expect(fn () => speechClip())->toThrow(UniqueConstraintViolationException::class);
});

it('keeps the audio on its own private disk', function () {
	expect(config('filesystems.disks.speech.root'))->toBe(storage_path('app/private/speech'))
		->and(config('filesystems.disks.speech.serve'))->toBeFalse();

	Storage::fake('speech');
	Storage::disk('speech')->put(speechClip()->path(), 'MP3');

	Storage::disk('speech')->assertExists('abc.mp3');
});

it('logs the credits of a speech call', function () {
	$generation = Generation::create([
		'user_id' => User::factory()->create()->id,
		'step' => 'speech',
		'model' => 'eleven_v4',
		'status' => 'ok',
		'credits' => 8,
	]);

	expect($generation->fresh()->credits)->toBe(8);
});
