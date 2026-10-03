<?php

use App\Actions\Generation\SpeakLesson;
use App\Actions\Lessons\UpdateLessonContent;
use App\Enums\LessonStatus;
use App\Jobs\CheckLesson;
use App\Jobs\FinishLesson;
use App\Jobs\SpeakLesson as SpeakLessonJob;
use App\Jobs\WriteLesson;
use App\Lessons\ClozeParser;
use App\Lessons\GenerationPipeline;
use App\Lessons\Speech\Texts;
use App\Models\Child;
use App\Models\Generation;
use App\Models\Lesson;
use App\Models\SpeechClip;
use Database\Factories\LessonFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

function frenchLesson(array $content): Lesson
{
	return Lesson::factory()->for(Child::factory())->fromFixture('passe-compose')->create([
		'subject' => 'Französisch',
		'content' => $content,
	]);
}

function vocabulary(string ...$words): array
{
	$content = LessonFactory::fixture('passe-compose');
	$content['sections'] = [['title' => 'Wörter', 'blocks' => [[
		'type' => 'vocabulary',
		'title' => 'Wörter',
		'entries' => array_map(fn ($word) => ['foreign' => $word, 'german' => '…', 'info' => null], $words),
		'origin' => 'photo',
	]]]];
	$content['modules']['flashcards'] = null;

	return $content;
}

beforeEach(function () {
	Storage::fake('speech');
	config()->set('speech.key', 'test-key');
	config()->set('speech.model', 'eleven_v4');
	config()->set('speech.voices.fr', 'voice-fr');
	config()->set('speech.max_characters_per_lesson', 1500);
	config()->set('speech.price_per_1000_characters', 0.0);
});

it('creates one clip per spoken word and logs each call', function () {
	Http::fake(['api.elevenlabs.io/*' => Http::response('MP3')]);
	$lesson = frenchLesson(vocabulary('parlé (parler)', 'le livre / les livres', 'chat'));

	$run = app(SpeakLesson::class)->handle($lesson);

	expect($run)->toMatchArray(['created' => 3, 'reused' => 0, 'credits' => 5 + 8 + 4, 'stopped' => null])
		->and(SpeechClip::pluck('text')->all())->toBe(['parlé', 'le livre', 'chat']);

	$clip = SpeechClip::firstWhere('text', 'chat');
	expect($clip->hash)->toBe(Texts::hash('chat', 'fr-FR', 'voice-fr', 'eleven_v4'))
		->and($clip->credits)->toBe(4);
	Storage::disk('speech')->assertExists($clip->path());

	$logs = Generation::where('step', 'speech')->get();
	expect($logs)->toHaveCount(3)
		->and($logs->pluck('status')->unique()->all())->toBe(['ok'])
		->and($logs->pluck('lesson_id')->unique()->all())->toBe([$lesson->id])
		->and($logs->pluck('user_id')->unique()->all())->toBe([$lesson->child->user_id])
		->and($logs->pluck('model')->unique()->all())->toBe(['eleven_v4'])
		->and($logs->sum('credits'))->toBe(17);

	Http::assertSent(fn (Request $request) => str_contains($request->url(), '/text-to-speech/voice-fr'));
});

it('logs the price when one is configured', function () {
	config()->set('speech.price_per_1000_characters', 0.08);
	Http::fake(['api.elevenlabs.io/*' => Http::response('MP3')]);

	app(SpeakLesson::class)->handle(frenchLesson(vocabulary('le livre')));

	expect((float) Generation::sole()->cost_usd)->toBe(0.00064);
});

it('reuses existing clips across lessons', function () {
	Http::fake(['api.elevenlabs.io/*' => Http::response('MP3')]);
	app(SpeakLesson::class)->handle(frenchLesson(vocabulary('chat', 'le livre')));

	$run = app(SpeakLesson::class)->handle(frenchLesson(vocabulary('chat', 'le chien', 'chat (m.)')));

	expect($run)->toMatchArray(['created' => 1, 'reused' => 2])
		->and(SpeechClip::count())->toBe(3);
	Http::assertSentCount(3);
});

it('makes the clip again when its file is missing', function () {
	Http::fake(['api.elevenlabs.io/*' => Http::response('MP3')]);
	app(SpeakLesson::class)->handle(frenchLesson(vocabulary('chat')));
	Storage::disk('speech')->delete(SpeechClip::sole()->path());

	$run = app(SpeakLesson::class)->handle(frenchLesson(vocabulary('chat')));

	expect($run['created'])->toBe(1)
		->and(SpeechClip::count())->toBe(1);
	Storage::disk('speech')->assertExists(SpeechClip::sole()->path());
});

it('includes the flashcard fronts', function () {
	Http::fake(['api.elevenlabs.io/*' => Http::response('MP3')]);
	$content = vocabulary('chat');
	$content['modules']['flashcards'] = ['instructions' => null, 'entries' => [
		['id' => 'k1', 'front' => 'le chien', 'back' => 'der Hund', 'origin' => 'photo'],
	]];

	app(SpeakLesson::class)->handle(frenchLesson($content));

	expect(SpeechClip::pluck('text')->all())->toBe(['chat', 'le chien']);
});

it('does nothing without a key, a voice, a language or content', function (Closure $setup) {
	Http::fake();
	$lesson = $setup();

	expect(app(SpeakLesson::class)->handle($lesson))->toMatchArray(['created' => 0, 'reused' => 0, 'stopped' => null]);

	Http::assertNothingSent();
})->with([
	'no key' => function () {
		config()->set('speech.key', null);

		return frenchLesson(vocabulary('chat'));
	},
	'no voice' => function () {
		config()->set('speech.voices.fr', null);

		return frenchLesson(vocabulary('chat'));
	},
	'not a language lesson' => fn () => Lesson::factory()->for(Child::factory())->fromFixture()->create(['subject' => 'Biologie']),
	'no content' => fn () => tap(frenchLesson(vocabulary('chat')), fn (Lesson $lesson) => $lesson->update(['content' => null])),
]);

it('stops at the character limit per lesson', function () {
	config()->set('speech.max_characters_per_lesson', 10);
	Http::fake(['api.elevenlabs.io/*' => Http::response('MP3')]);

	$run = app(SpeakLesson::class)->handle(frenchLesson(vocabulary('le livre', 'chat', 'le chien')));

	expect($run)->toMatchArray(['created' => 1, 'stopped' => 'limit'])
		->and(SpeechClip::pluck('text')->all())->toBe(['le livre']);
});

it('stops at the first error and keeps what it made', function (int $status, array $body, string $stopped) {
	Http::fake(['api.elevenlabs.io/*' => Http::sequence()
		->push('MP3')
		->push($body, $status)]);

	$run = app(SpeakLesson::class)->handle(frenchLesson(vocabulary('chat', 'le livre', 'le chien')));

	expect($run)->toMatchArray(['created' => 1, 'stopped' => $stopped])
		->and(SpeechClip::pluck('text')->all())->toBe(['chat']);
	Http::assertSentCount(2);

	$failed = Generation::where('status', 'error')->sole();
	expect($failed->error)->toContain((string) $status)
		->and($failed->credits)->toBe(0);
})->with([
	'quota' => [401, ['detail' => ['status' => 'quota_exceeded', 'message' => 'Quota exceeded.']], 'quota'],
	'other' => [402, ['detail' => ['status' => 'payment_required', 'message' => 'Paid plan required.']], 'error'],
]);

describe('in the pipeline', function () {
	it('speaks after the check for a language lesson', function () {
		$lesson = frenchLesson(vocabulary('chat'));
		Bus::fake();

		GenerationPipeline::write($lesson);

		Bus::assertChained([WriteLesson::class, CheckLesson::class, SpeakLessonJob::class, FinishLesson::class]);
	});

	it('leaves the step out without a key or for other subjects', function (Closure $lesson) {
		config()->set('speech.key', null);
		$lesson = $lesson();
		Bus::fake();

		GenerationPipeline::write($lesson);

		Bus::assertChained([WriteLesson::class, CheckLesson::class, FinishLesson::class]);
	})->with([
		'no key' => fn () => frenchLesson(vocabulary('chat')),
		'biology' => fn () => Lesson::factory()->for(Child::factory())->fromFixture()->create(['subject' => 'Biologie']),
	]);

	it('speaks on a retry with finished content', function () {
		$lesson = frenchLesson(vocabulary('chat'));
		$lesson->update(['status' => LessonStatus::Failed]);
		Bus::fake();

		GenerationPipeline::start($lesson);

		Bus::assertChained([SpeakLessonJob::class, FinishLesson::class]);
	});

	it('tells the progress display whether the step runs', function (bool $withKey) {
		config()->set('speech.key', $withKey ? 'test-key' : null);
		$lesson = frenchLesson(vocabulary('chat'));

		$this->actingAs($lesson->child->user)->get(route('lessons.show', $lesson))
			->assertOk()
			->assertInertia(fn (AssertableInertia $page) => $page->where('lesson.speaks', $withKey));
	})->with([true, false]);

	it('never fails the lesson', function () {
		Http::fake(['api.elevenlabs.io/*' => Http::response(['detail' => ['status' => 'quota_exceeded']], 429)]);
		$lesson = frenchLesson(vocabulary('chat'));
		$lesson->update(['status' => LessonStatus::Generating]);

		(new SpeakLessonJob($lesson))->handle();

		expect($lesson->fresh()->status)->toBe(LessonStatus::Generating)
			->and($lesson->fresh()->step)->toBe('speech');
	});
});

describe('after an edit', function () {
	it('speaks new words once the parents save', function () {
		Http::fake(['api.elevenlabs.io/*' => Http::response('MP3')]);
		// The validator wants at least four words in a list
		$lesson = frenchLesson(vocabulary('chat', 'le livre', 'la table', 'la porte'));
		$content = $lesson->content;
		$content['sections'][0]['blocks'][0]['entries'][] = ['foreign' => 'le chien', 'german' => 'der Hund', 'info' => null];

		$this->actingAs($lesson->child->user)->put(route('lessons.update', $lesson), [
			'content' => $content,
			'clozeMarkup' => ClozeParser::toMarkup($content['modules']['cloze']['segments']),
		])->assertSessionHasNoErrors();

		expect(SpeechClip::pluck('text')->all())->toBe(['chat', 'le livre', 'la table', 'la porte', 'le chien'])
			->and($lesson->fresh()->step)->toBeNull();
	});

	it('does not queue anything for other lessons', function () {
		Bus::fake();
		$lesson = Lesson::factory()->for(Child::factory())->fromFixture()->create(['subject' => 'Biologie']);

		app(UpdateLessonContent::class)->handle($lesson, $lesson->content, ClozeParser::toMarkup($lesson->content['modules']['cloze']['segments']));

		Bus::assertNothingDispatched();
	});
});

describe('lessons:speak', function () {
	function fakeElevenLabs(mixed $speech = 'MP3', int $status = 200): void
	{
		Http::fake([
			'api.elevenlabs.io/v1/text-to-speech/*' => Http::response($speech, $status),
			'api.elevenlabs.io/v1/user/subscription' => Http::response(['character_count' => 120, 'character_limit' => 10000]),
		]);
	}

	it('speaks one lesson and shows the credits left', function () {
		fakeElevenLabs();
		$lesson = frenchLesson(vocabulary('chat', 'le livre'));

		$this->artisan('lessons:speak', ['lesson' => $lesson->id])
			->expectsOutputToContain('9’880')
			->assertSuccessful();

		expect(SpeechClip::count())->toBe(2);
	});

	it('speaks every language lesson and skips the others', function () {
		fakeElevenLabs();
		frenchLesson(vocabulary('chat'));
		frenchLesson(vocabulary('chat', 'le chien'));
		Lesson::factory()->for(Child::factory())->fromFixture()->create(['subject' => 'Biologie']);

		$this->artisan('lessons:speak')->assertSuccessful();

		expect(SpeechClip::pluck('text')->all())->toBe(['chat', 'le chien']);
		Http::assertSentCount(3);
	});

	it('stops when the quota is used up', function () {
		fakeElevenLabs(['detail' => ['status' => 'quota_exceeded']], 401);
		frenchLesson(vocabulary('chat'));
		frenchLesson(vocabulary('le chien'));

		$this->artisan('lessons:speak')->assertFailed();

		Http::assertSentCount(2);
	});

	it('fails without a key', function () {
		config()->set('speech.key', null);

		$this->artisan('lessons:speak')->assertFailed();
	});
});

it('shows the credits on the Kosten page', function () {
	Http::fake(['api.elevenlabs.io/*' => Http::response('MP3')]);
	$lesson = frenchLesson(vocabulary('chat', 'le livre'));
	app(SpeakLesson::class)->handle($lesson);

	$this->actingAs($lesson->child->user)->get(route('costs'))
		->assertOk()
		->assertInertia(fn (AssertableInertia $page) => $page
			->where('months.0.credits', 12)
			->where('steps', fn ($steps) => collect($steps)->firstWhere('step', 'speech')['credits'] === 12)
		);
});
