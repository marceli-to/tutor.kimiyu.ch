<?php

use App\Lessons\LessonView;
use App\Lessons\Speech\Texts;
use App\Models\Child;
use App\Models\Generation;
use App\Models\Lesson;
use App\Models\SpeechClip;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

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

describe('serving', function () {
	beforeEach(fn () => Storage::fake('speech'));

	it('plays a clip without login and lets the browser keep it', function () {
		$hash = str_repeat('a', 64);
		Storage::disk('speech')->put(speechClip($hash)->path(), 'MP3');

		$response = $this->get(route('speech.clip', $hash))->assertOk();

		expect($response->headers->get('Content-Type'))->toBe('audio/mpeg')
			->and($response->headers->get('Cache-Control'))->toContain('max-age=31536000')
			->and($response->headers->get('Cache-Control'))->toContain('immutable')
			->and($response->streamedContent())->toBe('MP3');
	});

	it('answers 404 for an unknown clip or a missing file', function () {
		speechClip(str_repeat('b', 64));

		$this->get(route('speech.clip', str_repeat('c', 64)))->assertNotFound();
		$this->get(route('speech.clip', str_repeat('b', 64)))->assertNotFound();
	});

	it('only accepts hashes', function () {
		$this->get('/audio/..%2F..%2F.env.mp3')->assertNotFound();
		$this->get('/audio/abc.mp3')->assertNotFound();
	});
});

describe('on the lesson page', function () {
	beforeEach(function () {
		config()->set('speech.key', 'test-key');
		config()->set('speech.model', 'eleven_v4');
		config()->set('speech.voices.fr', 'voice-fr');

		$this->lesson = Lesson::factory()->for(Child::factory())->fromFixture('passe-compose')->create(['subject' => 'Französisch']);
		$this->clip = SpeechClip::create([
			'hash' => Texts::hash('parlé', 'fr-FR', 'voice-fr', 'eleven_v4'),
			'lang' => 'fr-FR',
			'voice_id' => 'voice-fr',
			'model' => 'eleven_v4',
			'text' => 'parlé',
			'credits' => 5,
		]);
	});

	it('maps the original texts to their clips', function () {
		$clips = LessonView::page($this->lesson)['speechClips'];

		expect($clips)->toBe(['parlé (parler)' => route('speech.clip', $this->clip->hash)]);
	});

	it('ignores clips of another voice or model', function (string $key, string $value) {
		config()->set($key, $value);

		expect(LessonView::page($this->lesson)['speechClips'])->toBe([]);
	})->with([['speech.voices.fr', 'voice-other'], ['speech.model', 'eleven_flash_v2_5']]);

	it('gives no clips without a key', function () {
		config()->set('speech.key', null);

		expect(LessonView::page($this->lesson)['speechClips'])->toBe([]);
	});

	it('gives the child the same clips', function () {
		$this->get(route('shared.show', [$this->lesson->child->share_token, $this->lesson]))
			->assertOk()
			->assertInertia(fn (AssertableInertia $page) => $page->where('lesson.speechClips', ['parlé (parler)' => route('speech.clip', $this->clip->hash)]));
	});
});
