<?php

use App\Actions\Generation\AnalyzeLesson as AnalyzeLessonAction;
use App\Enums\LessonStatus;
use App\Jobs\AnalyzeLesson;
use App\Jobs\CheckLesson;
use App\Jobs\FinishLesson;
use App\Jobs\GenerateLessonGraphic;
use App\Jobs\RegenerateGraphic;
use App\Jobs\RegenerateQuiz;
use App\Lessons\Ai\FakeLanguageModel;
use App\Lessons\Ai\ModelException;
use App\Lessons\Ai\ModelRequest;
use App\Lessons\Ai\Prompts;
use App\Lessons\Ai\Schemas;
use App\Lessons\ContentValidator;
use App\Lessons\GenerationFailed;
use App\Lessons\GenerationPipeline;
use App\Lessons\Profile;
use App\Models\Child;
use App\Models\Generation;
use App\Models\Lesson;
use App\Models\User;
use Database\Factories\LessonFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A JPEG with an EXIF block (as from a phone camera), larger than allowed.
 */
function photo(string $name = 'page.jpg', int $width = 3000, int $height = 2000): UploadedFile
{
	$image = imagecreatetruecolor($width, $height);
	imagefill($image, 0, 0, imagecolorallocate($image, 240, 240, 230));
	ob_start();
	imagejpeg($image);
	$jpeg = (string) ob_get_clean();

	// Insert an APP1 segment with «Exif» and a recognisable marker right after the SOI
	$payload = "Exif\0\0II*\0\x08\0\0\0\0\0GPS-MARKER-47.3769N";
	$app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

	return UploadedFile::fake()->createWithContent($name, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));
}

function analysis(array $changes = []): array
{
	return array_replace_recursive(FakeLanguageModel::defaultResponse('analysis'), $changes);
}

beforeEach(function () {
	Storage::fake('lesson-images');
	$this->fake = FakeLanguageModel::install();
	$this->user = User::factory()->create();
	$this->child = Child::factory()->for($this->user)->create(['name' => 'Mia', 'level' => '2. Sek']);
});

function upload(array $data = []): TestResponse
{
	return test()->actingAs(test()->user)->post(route('lessons.store'), [
		'child_id' => test()->child->id,
		'subject' => 'Biologie',
		'level' => '2. Sek',
		'prompt' => 'Prüfung am Freitag',
		'images' => [photo()],
		'graphics_mode' => 'auto',
		'purpose' => 'new',
		'scope' => 'normal',
		'modules' => ['quiz', 'sorting', 'flashcards', 'cloze'],
		...$data,
	]);
}

it('turns uploaded photos into a lesson ready for review', function () {
	$response = upload();

	$lesson = Lesson::sole();
	$response->assertRedirect(route('lessons.show', $lesson));

	expect($lesson->status)->toBe(LessonStatus::Review)
		->and($lesson->step)->toBeNull()
		->and($lesson->title)->toBe('Wie macht ein Blatt Zucker aus Licht?')
		->and($lesson->content)->toBe(LessonFactory::fixture('fotosynthese'))
		->and($lesson->graphic(1)->graphic)->toBe(LessonFactory::fixture('fotosynthese.graphic'))
		->and($lesson->graphic(1)->plan['pattern'])->toBe('sliders')
		->and($lesson->graphic(1)->error)->toBeNull()
		->and($lesson->source_summary)->toContain('Fotosynthese')
		->and($lesson->schema_version)->toBe(1);

	expect($lesson->generations()->pluck('step')->all())->toBe(['analysis', 'page', 'modules', 'check', 'graphic'])
		->and($lesson->generations()->where('status', 'ok')->count())->toBe(5)
		->and($lesson->generations()->pluck('user_id')->unique()->all())->toBe([$this->user->id]);

	$this->actingAs($this->user)->get(route('lessons.show', $lesson))
		->assertInertia(fn (Assert $page) => $page
			->where('lesson.status', 'review')
			->where('lesson.content.meta.palette', 'green')
			->has('lesson.graphics.1.url')
			->where('parent.graphics', [['number' => 1, 'error' => null, 'canRegenerate' => true, 'hidden' => false]])
		);
});

it('sends the photos, subject and level, but never the child name', function () {
	upload();

	$request = $this->fake->requestsFor('analysis')[0];

	expect($request->images)->toHaveCount(1)
		->and($request->prompt)->toContain('Fach: Biologie')
		->toContain('Stufe: 2. Sek');

	foreach ($this->fake->requests as $sent) {
		expect($sent->system.$sent->prompt)->not->toContain('Mia');
	}
});

it('treats the photos as the frame and the prompt as the focus', function () {
	upload();

	$prompt = $this->fake->requestsFor('analysis')[0]->prompt;

	expect($prompt)->toContain('Die Fotos sind der Rahmen')
		->toContain('Auftrag der Eltern: Prüfung am Freitag')
		->not->toContain('zum Thema');
});

it('sends photos without a prompt as before', function () {
	upload(['prompt' => '']);

	expect($this->fake->requestsFor('analysis')[0]->prompt)
		->toContain('Erstelle eine Lernseite aus diesem Foto.')
		->not->toContain('Auftrag der Eltern');
});

it('passes the prompt to every later step', function (string $step) {
	upload();

	expect($this->fake->requestsFor($step)[0]->prompt)->toContain('Auftrag der Eltern: Prüfung am Freitag');
})->with(['page', 'modules', 'check', 'graphic']);

it('stores the additions of the analysis and passes them on', function () {
	$this->fake->push('analysis', analysis(['additions' => ['Zellatmung ergänzt.']]));

	upload();

	expect(Lesson::sole()->additions)->toBe(['Zellatmung ergänzt.'])
		->and($this->fake->requestsFor('modules')[0]->prompt)
		->toContain("Ergänzt (nicht auf den Fotos):\n- Zellatmung ergänzt.");
});

it('stores no additions when nothing was added', function () {
	upload();

	expect(Lesson::sole()->additions)->toBeNull()
		->and($this->fake->requestsFor('modules')[0]->prompt)->not->toContain('Ergänzt (nicht auf den Fotos)');
});

it('keeps parent-only information out of the graphic request', function () {
	$this->fake->push('analysis', analysis([
		'additions' => ['Zellatmung ergänzt.'],
		'summary' => 'Pflanzen machen Zucker. Zellatmung (ergänzt) setzt Energie frei.',
	]));

	upload();

	$request = $this->fake->requestsFor('graphic')[0];
	expect($request->prompt)
		->not->toContain('origin')
		->not->toContain('Ergänzt (nicht auf den Fotos)')
		->not->toContain('(ergänzt)')
		->toContain('Zellatmung setzt Energie frei.')
		->toContain('Auftrag der Eltern: Prüfung am Freitag')
		->and($request->system)->toContain('Den Auftrag der Eltern nie wörtlich in die Grafik übernehmen.');
});

it('tells the system prompts to keep the origin marker away from the child', function () {
	upload();

	expect($this->fake->requestsFor('analysis')[0]->system)->toContain('«(ergänzt)» erscheint nie in Texten, die das Kind sieht')
		->and($this->fake->requestsFor('modules')[0]->system)->toContain('«(ergänzt)» erscheint nie in Texten, die das Kind sieht');
});

it('gives the repair of the text part the graphic plans and the rules for graphic blocks', function () {
	$lesson = Lesson::factory()->for($this->child)->fromFixture()->create(['graphics_mode' => 'custom']);
	$lesson->graphics()->create(['position' => 2, 'plan' => ['pattern' => 'steps', 'idea' => 'Vier Schritte']]);
	$content = $lesson->content;
	$content['sections'][0]['blocks'][] = ['type' => 'graphic', 'number' => 2, 'origin' => 'photo'];

	$request = Prompts::repair($lesson, $content, 'page', ['Ein Fehler.']);

	expect($request->prompt)->toContain('Geplante Grafiken:')
		->toContain("Grafik 2 (im Abschnitt «{$content['sections'][0]['title']}»): Muster steps\nVier Schritte")
		->and($request->system)->toContain('Bausteine `graphic` nur für geplante Grafiken mit Nummer 2 oder 3')
		->and(Prompts::repair($lesson, $content, 'modules', ['Ein Fehler.'])->prompt)->not->toContain('Geplante Grafiken:');
});

it('tells the repair to keep origin and ids', function () {
	$broken = LessonFactory::fixture('fotosynthese');
	$broken['modules']['quiz'] = array_slice($broken['modules']['quiz'], 0, 2);
	$this->fake->push('modules', ['modules' => $broken['modules']]);

	upload();

	expect($this->fake->requestsFor('repair-modules')[0]->system)
		->toContain('`origin` und IDs bestehender Einträge übernimmst du unverändert');
});

it('re-encodes photos without metadata and scales them down', function () {
	$this->fake->push('analysis', function (ModelRequest $request) {
		$data = $request->images[0]['data'];
		[$width, $height] = getimagesizefromstring($data);

		expect($request->images[0]['mime'])->toBe('image/jpeg')
			->and(max($width, $height))->toBe(1600)
			->and($data)->not->toContain('Exif')
			->not->toContain('GPS-MARKER');

		return analysis();
	});

	upload();

	expect($this->fake->requestsFor('analysis'))->toHaveCount(1);
});

it('deletes the photos after a successful analysis', function () {
	upload();

	expect(Lesson::sole()->images()->count())->toBe(0)
		->and(Storage::disk('lesson-images')->allFiles())->toBe([]);
});

it('names the photo order when there are several photos', function () {
	config()->set('lessons.delete_images', false);

	upload(['images' => [photo('a.jpg'), photo('b.jpg')]]);

	expect($this->fake->requestsFor('analysis')[0]->prompt)
		->toContain('Die Fotos sind in der Reihenfolge der Seiten: Foto 1 ist die erste Seite.')
		->and(Lesson::sole()->images()->reorder('id')->pluck('position')->all())->toBe([0, 1]);
});

it('does not name a photo order for a single photo', function () {
	upload();

	expect($this->fake->requestsFor('analysis')[0]->prompt)->not->toContain('Reihenfolge der Seiten');
});

it('keeps the photos when deleting is switched off', function () {
	config()->set('lessons.delete_images', false);

	upload(['images' => [photo('a.jpg'), photo('b.jpg')]]);

	expect(Lesson::sole()->images()->count())->toBe(2)
		->and(Storage::disk('lesson-images')->allFiles())->toHaveCount(2);
});

it('skips the check when it is switched off', function () {
	config()->set('lessons.check_enabled', false);

	upload();

	expect($this->fake->requestsFor('check'))->toBe([])
		->and(Lesson::sole()->status)->toBe(LessonStatus::Review);
});

it('checks the whole page in one call', function () {
	upload();

	$request = $this->fake->requestsFor('check');
	expect($request)->toHaveCount(1)
		->and($request[0]->prompt)->toContain('"modules"')
		->and($request[0]->prompt)->toContain('"sections"');
});

it('sends one example graphic to the graphic step', function () {
	upload();

	$system = $this->fake->requestsFor('graphic')[0]->system;
	expect($system)->toContain(LessonFactory::fixture('fotosynthese')['meta']['title'])
		->and($system)->not->toContain(LessonFactory::fixture('oekosystem')['meta']['title']);
});

it('applies the corrections of the check and lists them for the parents', function () {
	$this->fake->push('check', ['corrections' => [
		['path' => '/modules/quiz/0/hint', 'value' => 'Denk an die Zutaten, nicht an das Ergebnis.', 'area' => 'Quiz, Frage 1', 'change' => 'Tipp präzisiert.'],
	]]);

	upload();

	$lesson = Lesson::sole();
	expect($lesson->check_notes)->toBe([['area' => 'Quiz, Frage 1', 'change' => 'Tipp präzisiert.']])
		->and($lesson->content['modules']['quiz'][0]['hint'])->toBe('Denk an die Zutaten, nicht an das Ergebnis.');
});

it('lists one note for several corrections with the same description', function () {
	$this->fake->push('check', ['corrections' => [
		['path' => '/modules/quiz/0/options', 'value' => '["Kohlenstoffdioxid und Wasser","Sauerstoff und Wasser","Traubenzucker und Sauerstoff","Kohlenstoffdioxid und Traubenzucker"]', 'area' => 'Quiz, Frage 1', 'change' => 'Richtige Antwort an den Anfang gestellt.'],
		['path' => '/modules/quiz/0/answer', 'value' => '0', 'area' => 'Quiz, Frage 1', 'change' => 'Richtige Antwort an den Anfang gestellt.'],
	]]);

	upload();

	$lesson = Lesson::sole();
	expect($lesson->content['modules']['quiz'][0]['answer'])->toBe(0)
		->and($lesson->check_notes)->toBe([['area' => 'Quiz, Frage 1', 'change' => 'Richtige Antwort an den Anfang gestellt.']]);
});

it('drops corrections that would break the content', function () {
	$this->fake->push('check', ['corrections' => [
		['path' => '/modules/quiz/0/answer', 'value' => '99', 'area' => 'Quiz, Frage 1', 'change' => 'Lösung korrigiert.'],
	]]);

	upload();

	expect(Lesson::sole()->content)->toBe(LessonFactory::fixture('fotosynthese'))
		->and(Lesson::sole()->check_notes)->toBe([])
		->and(Lesson::sole()->status)->toBe(LessonStatus::Review);
});

it('keeps going when the check call fails', function () {
	$this->fake->push('check', new ModelException('Die KI ist gerade ausgelastet.', retryable: false));

	upload();

	expect(Lesson::sole()->status)->toBe(LessonStatus::Review)
		->and(Lesson::sole()->content)->toBe(LessonFactory::fixture('fotosynthese'))
		->and(Lesson::sole()->generations()->where('step', 'check')->value('status'))->toBe('error');
});

it('repairs invalid content once', function () {
	$broken = LessonFactory::fixture('fotosynthese');
	$broken['modules']['quiz'] = array_slice($broken['modules']['quiz'], 0, 2);

	$this->fake->push('modules', ['modules' => $broken['modules']]);

	upload();

	$repair = $this->fake->requestsFor('repair-modules');
	expect($repair)->toHaveCount(1)
		->and($this->fake->requestsFor('repair-page'))->toBe([])
		->and($repair[0]->prompt)->toContain('Das Feld Quiz muss mindestens 3 Elemente haben.')
		->toContain('Gib diesen Teil korrigiert zurück: modules')
		->toContain('Zusammenfassung des Stoffs:')
		->and(Lesson::sole()->status)->toBe(LessonStatus::Review)
		->and(Lesson::sole()->content['modules']['quiz'])->toHaveCount(5);
});

it('fails when the content is still invalid after the repair', function () {
	$broken = LessonFactory::fixture('fotosynthese');
	$broken['meta']['palette'] = 'neonpink';

	$this->fake->push('page', ['page' => Prompts::page($broken)]);
	$this->fake->push('repair-page', ['page' => Prompts::page($broken)]);

	upload();

	$lesson = Lesson::sole();
	expect($lesson->status)->toBe(LessonStatus::Failed)
		->and($lesson->error)->toBe('Die KI hat keinen gültigen Inhalt geliefert.')
		->and($this->fake->requestsFor('graphic'))->toBe([]);
});

it('accepts content that only breaks the strict rules after the repair', function () {
	$content = LessonFactory::fixture('fotosynthese');
	foreach ($content['modules']['quiz'] as &$question) {
		$question['answer'] = 1;
	}

	$this->fake->push('modules', ['modules' => $content['modules']]);
	$this->fake->push('repair-modules', ['modules' => $content['modules']]);

	upload();

	expect(Lesson::sole()->status)->toBe(LessonStatus::Review);
});

it('tells the parents when the photos are unreadable', function () {
	$this->fake->push('analysis', analysis([
		'source' => ['readable' => false, 'problem' => 'Die Fotos sind zu unscharf.'],
	]));

	upload();

	$lesson = Lesson::sole();
	expect($lesson->status)->toBe(LessonStatus::Failed)
		->and($lesson->error)->toBe('Die Fotos sind zu unscharf.')
		->and($lesson->images()->count())->toBe(1)
		->and($this->fake->requestsFor('page'))->toBe([])
		->and($lesson->generations()->pluck('step')->all())->toBe(['analysis']);

	$this->actingAs($this->user)->get(route('lessons.show', $lesson))
		->assertInertia(fn (Assert $page) => $page
			->where('lesson.status', 'failed')
			->where('lesson.error', 'Die Fotos sind zu unscharf.')
			->where('lesson.canRetry', true)
		);
});

it('writes the page in a second call with the photos, the summary, the additions and the plans', function () {
	$this->fake->push('analysis', analysis([
		'summary' => 'Pflanzen bauen aus Licht, CO₂ und Wasser Zucker. Zellatmung (ergänzt).',
		'additions' => ['Zellatmung ergänzt.'],
		'graphic_plans' => [['number' => 1, 'plan' => ['pattern' => 'sliders', 'idea' => 'Drei Regler für Licht, CO₂ und Wasser.'], 'note' => null]],
	]));

	upload();

	$request = $this->fake->requestsFor('page')[0];
	expect($request->images)->toHaveCount(1)
		->and($request->schema)->toBe(Schemas::part('page', Profile::Science))
		->and($request->prompt)->toContain('Fach: Biologie')
		->toContain('Stufe: 2. Sek')
		->toContain('Zweck: Neuer Stoff')
		->toContain('Umfang: normal')
		->toContain('Auftrag der Eltern: Prüfung am Freitag')
		->toContain('Grafiken: höchstens eine')
		->toContain('Pflanzen bauen aus Licht, CO₂ und Wasser Zucker. Zellatmung (ergänzt).')
		->toContain('Zellatmung ergänzt.')
		->toContain("Grafik 1 (oben): Muster sliders\nDrei Regler für Licht, CO₂ und Wasser.")
		->toContain('Liefere nur `page`')
		->not->toContain('Mia')
		->and($this->fake->requestsFor('analysis')[0]->prompt)->toContain('Liefere nur `source`, `subject`, `summary`, `additions` und `graphic_plans`');
});

it('shows each call only the example of its own fields', function () {
	upload();

	expect($this->fake->requestsFor('analysis')[0]->system)->toContain('"graphic_plans": [')
		->not->toContain('"sections": [')
		->and($this->fake->requestsFor('page')[0]->system)->toContain('"sections": [')
		->not->toContain('"graphic_plans": [');
});

it('tells the page step when there is no graphic', function () {
	$this->fake->push('analysis', [...analysis(), 'graphic_plans' => []]);

	upload();

	expect($this->fake->requestsFor('page')[0]->prompt)->toContain('Diese Seite hat keine Grafik 1');
});

it('fails the lesson when the page call fails', function () {
	$this->fake->push('page', new ModelException('Die KI war nicht erreichbar.'));

	upload();

	$lesson = Lesson::sole();
	expect($lesson->status)->toBe(LessonStatus::Failed)
		->and($lesson->error)->toBe('Die KI war nicht erreichbar.')
		->and($lesson->content)->toBeNull()
		->and($lesson->images()->count())->toBe(1)
		->and($this->fake->requestsFor('modules'))->toBe([])
		->and($lesson->generations()->pluck('status', 'step')->all())->toBe(['analysis' => 'ok', 'page' => 'error']);
});

it('shows a clear message when the api fails', function () {
	$this->fake->push('analysis', new ModelException('Der Zugang zur KI ist falsch eingerichtet.', 'invalid x-api-key'));

	upload();

	$lesson = Lesson::sole();
	expect($lesson->status)->toBe(LessonStatus::Failed)
		->and($lesson->error)->toBe('Der Zugang zur KI ist falsch eingerichtet.')
		->and($lesson->generations()->value('error'))->toBe('invalid x-api-key');
});

it('logs the model of the step when a call fails without a response', function () {
	config()->set('lessons.models.analysis', ['model' => 'claude-test-analysis', 'effort' => 'high']);
	$this->fake->push('analysis', new ModelException('Die KI war nicht erreichbar.'));

	upload();

	expect(Lesson::sole()->generations()->where('step', 'analysis')->value('model'))->toBe('claude-test-analysis');
});

it('publishes the page without a graphic when the graphic stays broken', function () {
	$broken = [...LessonFactory::fixture('fotosynthese.graphic'), 'markup' => '<button onclick="x()">Los</button>'];

	$this->fake->push('graphic', $broken);
	$this->fake->push('graphic-repair', $broken);

	upload();

	$lesson = Lesson::sole();
	expect($lesson->status)->toBe(LessonStatus::Review)
		->and($lesson->graphic(1)->graphic)->toBeNull()
		->and($lesson->graphic(1)->error)->toContain('Inline-Event-Handler')
		->and($this->fake->requestsFor('graphic-repair'))->toHaveCount(1);
});

it('repairs a broken graphic once', function () {
	$this->fake->push('graphic', [...LessonFactory::fixture('fotosynthese.graphic'), 'script' => 'fetch("x")']);

	upload();

	$lesson = Lesson::sole();
	expect($lesson->graphic(1)->graphic['script'])->toBe(LessonFactory::fixture('fotosynthese.graphic')['script'])
		->and($lesson->graphic(1)->error)->toBeNull();
});

it('creates the first child from the name', function () {
	$this->child->delete();

	upload(['child_id' => null, 'child_name' => 'Noah', 'level' => '1. Sek']);

	$child = $this->user->children()->sole();
	expect($child->name)->toBe('Noah')
		->and($child->level)->toBe('1. Sek')
		->and(Lesson::sole()->child_id)->toBe($child->id);
});

it('validates the upload', function () {
	upload(['images' => [], 'prompt' => '', 'subject' => str_repeat('x', 61)])
		->assertSessionHasErrors([
			'images' => 'Lade mindestens ein Foto hoch oder schreib einen Auftrag.',
			'subject',
		]);

	upload(['images' => array_fill(0, 5, photo())])
		->assertSessionHasErrors(['images' => 'Höchstens 4 Fotos pro Lernseite.']);

	upload(['images' => [UploadedFile::fake()->create('page.pdf', 100, 'application/pdf')]])
		->assertSessionHasErrors(['images.0' => 'Nur Fotos im Format JPEG, PNG oder WebP.']);

	expect(Lesson::count())->toBe(0);
});

it('rejects a damaged photo', function () {
	upload(['images' => [UploadedFile::fake()->createWithContent('page.jpg', "\xFF\xD8\xFFkaputt")]])
		->assertSessionHasErrors('images.0');

	expect(Lesson::count())->toBe(0);
});

it('does not allow another parent’s child', function () {
	$other = Child::factory()->create();

	upload(['child_id' => $other->id])->assertSessionHasErrors('child_id');

	expect(Lesson::count())->toBe(0);
});

it('runs the steps as a chain', function () {
	Bus::fake();

	upload();

	Bus::assertChained([AnalyzeLesson::class, CheckLesson::class, GenerateLessonGraphic::class, FinishLesson::class]);

	$lesson = Lesson::sole();
	expect($lesson->status)->toBe(LessonStatus::Generating)
		->and($lesson->step)->toBe('queued');

	$this->actingAs($this->user)->get(route('lessons.show', $lesson))
		->assertInertia(fn (Assert $page) => $page
			->where('lesson.status', 'generating')
			->where('lesson.step', 'queued')
			->where('lesson.content', null)
		);
});

describe('retry', function () {
	it('starts again after a failed analysis', function () {
		$this->fake->push('analysis', new ModelException('Die KI war nicht erreichbar.'));
		upload();
		$lesson = Lesson::sole();

		$this->actingAs($this->user)->post(route('lessons.retry', $lesson))
			->assertRedirect(route('lessons.show', $lesson));

		expect($lesson->fresh()->status)->toBe(LessonStatus::Review)
			->and($this->fake->requestsFor('analysis'))->toHaveCount(2);
	});

	it('only regenerates what is missing', function () {
		$lesson = Lesson::factory()->for($this->child)->fromFixture()->create([
			'status' => LessonStatus::Failed,
		]);
		$lesson->graphic(1)->update(['graphic' => null, 'error' => 'Die KI war nicht erreichbar.']);

		$this->actingAs($this->user)->post(route('lessons.retry', $lesson));

		expect(collect($this->fake->requests)->pluck('step')->all())->toBe(['graphic'])
			->and($lesson->fresh()->status)->toBe(LessonStatus::Review);
	});

	it('still sends the topic and notes of an old lesson', function () {
		$lesson = Lesson::factory()->for($this->child)->create([
			'status' => LessonStatus::Failed,
			'topic' => 'Fotosynthese',
			'notes' => 'Bitte mit Beispielen aus dem Garten',
		]);

		$this->actingAs($this->user)->post(route('lessons.retry', $lesson));

		expect($this->fake->requestsFor('analysis')[0]->prompt)
			->toContain('zum Thema «Fotosynthese»')
			->toContain('Hinweise der Eltern: Bitte mit Beispielen aus dem Garten')
			->not->toContain('Auftrag der Eltern')
			->and($this->fake->requestsFor('modules')[0]->prompt)
			->toContain('Hinweise der Eltern: Bitte mit Beispielen aus dem Garten')
			->and($lesson->fresh()->status)->toBe(LessonStatus::Review);
	});

	it('is not possible without photos and content', function () {
		$lesson = Lesson::factory()->for($this->child)->create(['status' => LessonStatus::Failed]);

		$this->actingAs($this->user)->post(route('lessons.retry', $lesson))->assertStatus(422);
	});

	it('is not possible for a photo lesson with a prompt whose photos are gone', function () {
		$lesson = Lesson::factory()->for($this->child)->create([
			'status' => LessonStatus::Failed,
			'prompt' => 'Prüfung am Freitag',
			'photo_count' => 2,
			'error' => 'Die KI war nicht erreichbar.',
		]);

		$this->actingAs($this->user)->get(route('lessons.show', $lesson))
			->assertInertia(fn (Assert $page) => $page->where('lesson.canRetry', false));
		$this->actingAs($this->user)->post(route('lessons.retry', $lesson))->assertStatus(422);
		expect($this->fake->requests)->toBe([]);
	});

	it('explains that the photos are missing when only their files are gone', function () {
		$lesson = Lesson::factory()->for($this->child)->create([
			'status' => LessonStatus::Failed,
			'prompt' => 'Prüfung am Freitag',
			'photo_count' => 1,
		]);
		$lesson->images()->create(['path' => "{$lesson->id}/weg.jpg", 'mime_type' => 'image/jpeg', 'size' => 1]);

		$this->actingAs($this->user)->post(route('lessons.retry', $lesson));

		expect($lesson->fresh()->status)->toBe(LessonStatus::Failed)
			->and($lesson->fresh()->error)->toBe('Es sind keine Fotos mehr vorhanden. Bitte die Lernseite neu erstellen.')
			->and($this->fake->requests)->toBe([]);
	});

	it('is only allowed for the parent', function () {
		$lesson = Lesson::factory()->for($this->child)->fromFixture()->create(['status' => LessonStatus::Failed]);

		$this->actingAs(User::factory()->create())->post(route('lessons.retry', $lesson))->assertForbidden();
	});
});

it('shows the upload form with the parent’s children', function () {
	Child::factory()->create(['name' => 'Fremdes Kind']);

	$this->actingAs($this->user)->get(route('lessons.create'))
		->assertOk()
		->assertInertia(fn (Assert $page) => $page
			->component('lessons/Create')
			->has('children', 1)
			->where('children.0.name', 'Mia')
			->where('maxImages', 4)
			->where('maxEdge', 1600)
			->where('patterns.0', ['value' => 'sliders', 'label' => 'Regler'])
			->has('patterns', 6)
		);
});

describe('remembered settings', function () {
	function lessonFor(Child $child, array $attributes): Lesson
	{
		return Lesson::factory()->for($child)->create(['level' => '2. Sek', ...$attributes]);
	}

	it('passes the settings of the latest lesson per child and subject', function () {
		$leo = Child::factory()->for($this->user)->create(['name' => 'Leo']);

		lessonFor($this->child, ['subject' => 'Biologie', 'purpose' => 'new', 'scope' => 'normal', 'modules' => null, 'graphics_mode' => 'auto', 'created_at' => now()->subDays(3)]);
		lessonFor($this->child, ['subject' => ' biologie ', 'purpose' => 'exam', 'scope' => 'short', 'modules' => ['quiz', 'flashcards'], 'graphics_mode' => 'none', 'created_at' => now()->subDay()]);
		lessonFor($this->child, ['subject' => 'Mathematik', 'scope' => 'detailed', 'created_at' => now()->subDays(2)]);
		lessonFor($leo, ['subject' => 'Biologie', 'purpose' => 'new', 'scope' => 'detailed', 'modules' => ['sorting'], 'graphics_mode' => 'custom']);

		$this->actingAs($this->user)->get(route('lessons.create'))
			->assertInertia(fn (Assert $page) => $page
				->has('lastSettings', 3)
				->where("lastSettings.{$this->child->id}|biologie", [
					'purpose' => 'exam',
					'scope' => 'short',
					'modules' => ['quiz', 'flashcards'],
					'graphics_mode' => 'none',
				])
				->where("lastSettings.{$this->child->id}|mathematik.scope", 'detailed')
				// Old lessons without a list: all modules were allowed
				->where("lastSettings.{$this->child->id}|mathematik.modules", ['quiz', 'sorting', 'flashcards', 'cloze', 'exercises'])
				->where("lastSettings.{$leo->id}|biologie.modules", ['sorting'])
				->where("lastSettings.{$leo->id}|biologie.graphics_mode", 'custom')
			);
	});

	it('ignores deleted lessons and lessons of other parents', function () {
		lessonFor($this->child, ['subject' => 'Biologie', 'scope' => 'short', 'created_at' => now()->subDays(2)]);
		lessonFor($this->child, ['subject' => 'Biologie', 'scope' => 'detailed', 'created_at' => now()->subDay()])->delete();
		lessonFor(Child::factory()->create(), ['subject' => 'Chemie', 'scope' => 'detailed']);

		$this->actingAs($this->user)->get(route('lessons.create'))
			->assertInertia(fn (Assert $page) => $page
				->has('lastSettings', 1)
				->where("lastSettings.{$this->child->id}|biologie.scope", 'short')
			);
	});

	it('passes the settings of the latest lesson per child', function () {
		$leo = Child::factory()->for($this->user)->create(['name' => 'Leo']);

		lessonFor($this->child, ['subject' => 'Biologie', 'scope' => 'short', 'created_at' => now()->subDays(2)]);
		lessonFor($this->child, ['subject' => null, 'purpose' => 'exam', 'scope' => 'detailed', 'modules' => ['quiz'], 'graphics_mode' => 'none', 'created_at' => now()->subDay()]);
		lessonFor($leo, ['subject' => 'Mathematik', 'purpose' => 'new', 'scope' => 'normal', 'modules' => null, 'graphics_mode' => 'custom']);

		$this->actingAs($this->user)->get(route('lessons.create'))
			->assertInertia(fn (Assert $page) => $page
				->has('lastByChild', 2)
				->where("lastByChild.{$this->child->id}", [
					'purpose' => 'exam',
					'scope' => 'detailed',
					'modules' => ['quiz'],
					'graphics_mode' => 'none',
				])
				// Custom graphic wishes apply only to that one page: they become «auto»
				->where("lastByChild.{$leo->id}", [
					'purpose' => 'new',
					'scope' => 'normal',
					'modules' => ['quiz', 'sorting', 'flashcards', 'cloze', 'exercises'],
					'graphics_mode' => 'auto',
				])
			);
	});

	it('ignores deleted lessons and other parents for the settings per child', function () {
		lessonFor($this->child, ['scope' => 'short', 'created_at' => now()->subDays(2)]);
		lessonFor($this->child, ['scope' => 'detailed', 'created_at' => now()->subDay()])->delete();
		lessonFor(Child::factory()->create(), ['scope' => 'detailed']);

		$this->actingAs($this->user)->get(route('lessons.create'))
			->assertInertia(fn (Assert $page) => $page
				->has('lastByChild', 1)
				->where("lastByChild.{$this->child->id}.scope", 'short')
			);
	});

	it('allows the exercises in every stored choice, since they used to come with math anyway', function () {
		$migration = require database_path('migrations/2026_10_03_100000_allow_exercises_in_lesson_modules.php');
		$quiz = lessonFor($this->child, ['modules' => ['quiz', 'cloze']]);
		$old = lessonFor($this->child, ['modules' => null]);
		$both = lessonFor($this->child, ['modules' => ['quiz', 'exercises']]);

		$migration->up();
		expect($quiz->fresh()->modules)->toBe(['quiz', 'cloze', 'exercises'])
			->and($old->fresh()->modules)->toBeNull()
			->and($both->fresh()->modules)->toBe(['quiz', 'exercises']);

		$migration->down();
		expect($quiz->fresh()->modules)->toBe(['quiz', 'cloze'])
			->and($both->fresh()->modules)->toBe(['quiz']);
	});

	it('passes the counts per scope for the labels', function () {
		$this->actingAs($this->user)->get(route('lessons.create'))
			->assertInertia(fn (Assert $page) => $page
				->where('scopeInfo.short.quiz', 3)
				->where('scopeInfo.short.sections', '1–2')
				->where('scopeInfo.detailed.exercises', '20')
				->has('scopeInfo', 3)
			);
	});
});

describe('from a prompt', function () {
	function uploadPrompt(array $data = []): TestResponse
	{
		return upload(['prompt' => 'Biodiversität: Arten, Lebensräume und Gefährdung', 'images' => [], ...$data]);
	}

	it('creates a lesson without photos', function () {
		uploadPrompt()->assertRedirect();

		$lesson = Lesson::sole();
		$request = $this->fake->requestsFor('analysis')[0];

		expect($lesson->prompt)->toBe('Biodiversität: Arten, Lebensräume und Gefährdung')
			->and($lesson->photo_count)->toBe(0)
			->and($lesson->topic)->toBeNull()
			->and($lesson->isFromTopic())->toBeTrue()
			->and($lesson->status)->toBe(LessonStatus::Review)
			->and($lesson->images()->count())->toBe(0)
			->and($request->images)->toBe([]);

		$this->actingAs($this->user)->get(route('lessons.show', $lesson))
			->assertInertia(fn (Assert $page) => $page->where('lesson.fromTopic', true));
	});

	it('works from the prompt alone', function () {
		uploadPrompt();

		$request = $this->fake->requestsFor('analysis')[0];

		expect($request->images)->toBe([])
			->and($request->prompt)->toContain('keine Fotos')
			->toContain('Auftrag der Eltern: Biodiversität: Arten, Lebensräume und Gefährdung')
			->not->toContain('zum Thema');
	});

	it('stores photos and prompt together', function () {
		upload(['prompt' => '  Prüfung am Freitag  ', 'images' => [photo('a.jpg'), photo('b.jpg')]]);

		$lesson = Lesson::sole();
		expect($lesson->prompt)->toBe('Prüfung am Freitag')
			->and($lesson->photo_count)->toBe(2)
			->and($lesson->isFromTopic())->toBeFalse();

		$this->actingAs($this->user)->get(route('lessons.show', $lesson))
			->assertInertia(fn (Assert $page) => $page->where('lesson.fromTopic', false));
	});

	it('stores photos without a prompt', function () {
		upload(['prompt' => '']);

		expect(Lesson::sole()->prompt)->toBeNull()
			->and(Lesson::sole()->photo_count)->toBe(1);
	});

	it('tells the later steps that there are no photos', function () {
		$this->fake->push('analysis', analysis(['additions' => ['Alles ergänzt.']]));

		uploadPrompt();

		expect(Lesson::sole()->additions)->toBeNull()
			->and($this->fake->requestsFor('modules')[0]->prompt)
			->toContain('Quelle: keine Fotos (Auftrag oder Thema)')
			->not->toContain('Ergänzt (nicht auf den Fotos)');
	});

	it('does not mention missing photos for a photo lesson', function () {
		upload();

		expect($this->fake->requestsFor('modules')[0]->prompt)->not->toContain('Quelle: keine Fotos');
	});

	it('requires photos or a prompt', function () {
		upload(['prompt' => null, 'images' => []])
			->assertSessionHasErrors(['images' => 'Lade mindestens ein Foto hoch oder schreib einen Auftrag.']);

		uploadPrompt(['prompt' => str_repeat('a', 1001)])
			->assertSessionHasErrors(['prompt' => 'Der Auftrag darf höchstens 1000 Zeichen lang sein.']);

		expect(Lesson::count())->toBe(0);
	});

	it('explains when the topic does not work', function () {
		$this->fake->push('analysis', analysis([
			'source' => ['readable' => false, 'problem' => 'Das ist kein Thema aus dem Schulstoff.'],
		]));

		uploadPrompt(['prompt' => 'Fussballresultate vom Wochenende']);

		$lesson = Lesson::sole();
		expect($lesson->status)->toBe(LessonStatus::Failed)
			->and($lesson->error)->toBe('Das ist kein Thema aus dem Schulstoff.');

		$this->actingAs($this->user)->post(route('lessons.retry', $lesson));

		expect($lesson->fresh()->status)->toBe(LessonStatus::Review);
	});
});

it('names the prompt or the topic when no lesson could be made', function () {
	$this->fake->push('analysis', analysis(['source' => ['readable' => false, 'problem' => null]]));
	uploadPrompt();
	expect(Lesson::sole()->error)->toBe('Zu diesem Auftrag konnte keine Lernseite erstellt werden.');

	$this->fake->push('analysis', analysis(['source' => ['readable' => false, 'problem' => null]]));
	$legacy = Lesson::factory()->for($this->child)->create(['status' => LessonStatus::Failed, 'topic' => 'Fotosynthese']);
	$this->actingAs($this->user)->post(route('lessons.retry', $legacy));
	expect($legacy->fresh()->error)->toBe('Zu diesem Thema konnte keine Lernseite erstellt werden.');
});

describe('without a graphic', function () {
	it('skips the graphic when the parents switch it off', function () {
		$this->fake->push('analysis', [...analysis(), 'graphic_plans' => []]);

		upload(['graphics_mode' => 'none']);

		$lesson = Lesson::sole();
		expect($lesson->graphics_mode)->toBe('none')
			->and($lesson->status)->toBe(LessonStatus::Review)
			->and($lesson->graphics)->toBeEmpty()
			->and($this->fake->requestsFor('graphic'))->toBe([])
			->and($this->fake->requestsFor('analysis')[0]->prompt)->toContain('Grafiken: keine (von den Eltern abgewählt)');

		$this->actingAs($this->user)->get(route('lessons.show', $lesson))
			->assertInertia(fn (Assert $page) => $page
				->where('lesson.plannedGraphics', [])
				->where('lesson.graphics', [])
				->where('parent.graphics', [])
			);
	});

	it('lets the ai leave out the graphic when nothing fits', function () {
		$this->fake->push('analysis', [...analysis(), 'graphic_plans' => []]);

		upload();

		$lesson = Lesson::sole();
		expect($lesson->graphics_mode)->toBe('auto')
			->and($lesson->graphic(1)?->graphic)->toBeNull()
			->and($lesson->graphic(1)?->error)->toBeNull()
			->and($this->fake->requestsFor('graphic'))->toBe([])
			->and($this->fake->requestsFor('analysis')[0]->prompt)->toContain('Grafiken: höchstens eine, wenn ein Muster den Stoff sichtbar macht')
			->and($this->fake->requestsFor('modules')[0]->prompt)->toContain('Diese Seite hat keine interaktive Grafik.');
	});

	it('creates a graphic by default', function () {
		upload();

		expect(Lesson::sole()->graphics_mode)->toBe('auto')
			->and($this->fake->requestsFor('graphic'))->toHaveCount(1);
	});
});

describe('deleted lessons', function () {
	it('stops the generation when the lesson was deleted', function () {
		$lesson = Lesson::factory()->for($this->child)->create([
			'status' => LessonStatus::Generating,
			'step' => 'queued',
			'prompt' => 'Fotosynthese',
		]);
		$lesson->delete();

		(new AnalyzeLesson($lesson))->handle();
		(new GenerateLessonGraphic($lesson, 1))->handle();
		(new FinishLesson($lesson))->handle();

		$fresh = Lesson::withTrashed()->find($lesson->id);
		expect($this->fake->requests)->toBe([])
			->and(Generation::count())->toBe(0)
			->and($fresh->status)->toBe(LessonStatus::Generating)
			->and($fresh->step)->toBe('queued');
	});

	/**
	 * Deletes the lesson like the parent does, while the call for $step is still running.
	 */
	function deleteDuring(string $step, Lesson $lesson, ?array $response = null): void
	{
		test()->fake->push($step, function () use ($step, $lesson, $response) {
			test()->actingAs(test()->user)->delete(route('lessons.destroy', $lesson))->assertRedirect(route('dashboard'));

			return $response ?? FakeLanguageModel::defaultResponse($step);
		});
	}

	function expectStillEmpty(Lesson $lesson): void
	{
		$deleted = Lesson::withTrashed()->find($lesson->id);

		expect($deleted->trashed())->toBeTrue()
			->and($deleted->status)->toBe(LessonStatus::Failed)
			->and($deleted->only(['content', 'source_summary', 'additions', 'check_notes', 'error', 'step']))->each->toBeNull()
			->and($deleted->graphics()->count())->toBe(0);
	}

	it('does not write the analysis into a lesson deleted during a call', function (string $step) {
		$lesson = Lesson::factory()->for($this->child)->create([
			'status' => LessonStatus::Draft,
			'prompt' => 'Fotosynthese',
			'subject' => 'Biologie',
		]);
		deleteDuring($step, $lesson);

		GenerationPipeline::start($lesson);

		expectStillEmpty($lesson);
		// Costs of the calls made so far stay logged
		expect(Generation::where('lesson_id', $lesson->id)->pluck('step')->last())->toBe($step)
			->and($this->fake->requestsFor('graphic'))->toBe([]);
	})->with(['analysis', 'page', 'modules']);

	it('does not write the check or the graphic into a lesson deleted during a call', function (string $step, string $job) {
		$lesson = Lesson::factory()->for($this->child)->fromFixture()->create(['status' => LessonStatus::Generating]);
		$lesson->graphic(1)->update(['graphic' => null]);
		deleteDuring($step, $lesson);

		(new $job($lesson, 1))->handle();

		expectStillEmpty($lesson);
	})->with([
		'check' => ['check', CheckLesson::class],
		'graphic' => ['graphic', GenerateLessonGraphic::class],
	]);

	it('does not write a new quiz or graphic into a lesson deleted during a call', function (string $step, string $job) {
		$lesson = Lesson::factory()->for($this->child)->fromFixture()->create();
		deleteDuring($step, $lesson, $step === 'regenerate-quiz' ? ['quiz' => LessonFactory::fixture('oekosystem')['modules']['quiz']] : null);

		(new $job($lesson, 1))->handle();

		expectStillEmpty($lesson);
	})->with([
		'quiz' => ['regenerate-quiz', RegenerateQuiz::class],
		'graphic' => ['graphic', RegenerateGraphic::class],
	]);

	it('does not call the api when the child is gone', function () {
		$lesson = Lesson::factory()->for($this->child)->create(['prompt' => 'Fotosynthese']);
		$lesson->setRelation('child', null);

		expect(fn () => app(AnalyzeLessonAction::class)->handle($lesson))->toThrow(GenerationFailed::class);
		expect($this->fake->requests)->toBe([])
			->and(Generation::count())->toBe(0);
	});
});

describe('graphics mode', function () {
	beforeEach(fn () => Bus::fake());

	it('stores the mode without wishes', function (string $mode) {
		upload(['graphics_mode' => $mode])->assertSessionHasNoErrors();

		$lesson = Lesson::sole();
		expect($lesson->graphics_mode)->toBe($mode)
			->and($lesson->graphics()->count())->toBe(0);
	})->with(['none', 'auto']);

	it('rejects an upload without a mode', function () {
		$this->actingAs($this->user)->post(route('lessons.store'), [
			'child_id' => $this->child->id,
			'subject' => 'Biologie',
			'level' => '2. Sek',
			'prompt' => 'Prüfung am Freitag',
		])->assertSessionHasErrors(['graphics_mode' => 'Wähle aus, ob und welche Grafiken die Seite bekommt.']);
		// The old key `with_hero` does not replace the mode
		upload(['with_hero' => false, 'graphics_mode' => null])->assertSessionHasErrors('graphics_mode');
		upload(['graphics_mode' => 'alle'])->assertSessionHasErrors('graphics_mode');

		expect(Lesson::count())->toBe(0);
	});

	it('ignores wishes unless the parents describe the graphics', function () {
		upload(['graphics_mode' => 'auto', 'graphics' => [['description' => 'Ein Zeitstrahl']]])->assertSessionHasNoErrors();

		expect(Lesson::sole()->graphics()->count())->toBe(0);
	});

	it('stores the wishes in order', function () {
		upload(['graphics_mode' => 'custom', 'graphics' => [
			['description' => 'Ein Blatt mit Reglern für Licht und Wasser', 'pattern' => 'sliders'],
			['description' => 'Die Schritte der Fotosynthese', 'pattern' => null],
			['description' => '  Zellatmung im Vergleich  '],
		]])->assertSessionHasNoErrors();

		$lesson = Lesson::sole();
		expect($lesson->graphics_mode)->toBe('custom')
			->and($lesson->graphics->map->only(['position', 'request', 'pattern'])->all())->toBe([
				['position' => 1, 'request' => 'Ein Blatt mit Reglern für Licht und Wasser', 'pattern' => 'sliders'],
				['position' => 2, 'request' => 'Die Schritte der Fotosynthese', 'pattern' => null],
				['position' => 3, 'request' => 'Zellatmung im Vergleich', 'pattern' => null],
			]);
	});

	it('validates the wishes', function () {
		upload(['graphics_mode' => 'custom'])
			->assertSessionHasErrors(['graphics' => 'Beschreib mindestens eine Grafik.']);

		upload(['graphics_mode' => 'custom', 'graphics' => []])
			->assertSessionHasErrors(['graphics' => 'Beschreib mindestens eine Grafik.']);

		upload(['graphics_mode' => 'custom', 'graphics' => array_fill(0, 4, ['description' => 'Eine Grafik'])])
			->assertSessionHasErrors(['graphics' => 'Höchstens 3 Grafiken.']);

		upload(['graphics_mode' => 'custom', 'graphics' => [['description' => '']]])
			->assertSessionHasErrors(['graphics.0.description' => 'Beschreib, was die Grafik zeigen soll.']);

		upload(['graphics_mode' => 'custom', 'graphics' => [['description' => str_repeat('a', 501)]]])
			->assertSessionHasErrors('graphics.0.description');

		upload(['graphics_mode' => 'custom', 'graphics' => [['description' => 'Eine Grafik', 'pattern' => 'karussell']]])
			->assertSessionHasErrors('graphics.0.pattern');

		upload(['graphics_mode' => 'alle'])->assertSessionHasErrors('graphics_mode');

		expect(Lesson::count())->toBe(0);
	});
});

describe('graphic plans', function () {
	function plan(string $pattern, string $idea): array
	{
		return ['pattern' => $pattern, 'idea' => $idea];
	}

	function wishes(): array
	{
		return ['graphics_mode' => 'custom', 'graphics' => [
			['description' => 'Ein Blatt mit Reglern', 'pattern' => 'sliders'],
			['description' => 'Ein Vulkan'],
			['description' => 'Die Schritte im Chloroplasten', 'pattern' => 'steps'],
		]];
	}

	it('tells the analysis what the parents chose', function (array $data, string $line) {
		upload($data);

		expect($this->fake->requestsFor('analysis')[0]->prompt)->toContain($line)
			->not->toContain('Interaktive Grafik');
	})->with([
		'none' => [['graphics_mode' => 'none'], 'Grafiken: keine (von den Eltern abgewählt)'],
		'auto' => [['graphics_mode' => 'auto'], 'Grafiken: höchstens eine, wenn ein Muster den Stoff sichtbar macht'],
		'custom' => [wishes(), "Grafiken nach Wunsch der Eltern:\nGrafik 1: Ein Blatt mit Reglern (Muster: sliders – Regler)\nGrafik 2: Ein Vulkan\nGrafik 3: Die Schritte im Chloroplasten (Muster: steps – Schritt für Schritt)"],
	]);

	it('stores a plan or a hint for every wish', function () {
		$page = Prompts::page(LessonFactory::fixture('fotosynthese'));
		$page['sections'][1]['blocks'][] = ['type' => 'graphic', 'number' => 3, 'origin' => 'photo'];

		$this->fake->push('page', ['page' => $page]);
		$this->fake->push('analysis', [...analysis(), 'graphic_plans' => [
			['number' => 1, 'plan' => plan('sliders', 'Drei Regler'), 'note' => null],
			['number' => 2, 'plan' => null, 'note' => 'Ein Vulkan kommt auf den Fotos nicht vor.'],
			['number' => 3, 'plan' => plan('steps', 'Vier Schritte'), 'note' => null],
			['number' => 4, 'plan' => plan('calculator', 'Zu viel'), 'note' => null],
		]]);

		upload(wishes());

		$lesson = Lesson::sole();
		expect($lesson->graphics->map->only(['position', 'request', 'plan', 'error'])->all())->toBe([
			['position' => 1, 'request' => 'Ein Blatt mit Reglern', 'plan' => plan('sliders', 'Drei Regler'), 'error' => null],
			['position' => 2, 'request' => 'Ein Vulkan', 'plan' => null, 'error' => 'Ein Vulkan kommt auf den Fotos nicht vor.'],
			['position' => 3, 'request' => 'Die Schritte im Chloroplasten', 'plan' => plan('steps', 'Vier Schritte'), 'error' => null],
		])
			->and($lesson->content['sections'][1]['blocks'][1])->toBe(['type' => 'graphic', 'number' => 3, 'origin' => 'photo']);

		expect($this->fake->requestsFor('modules')[0]->prompt)
			->toContain("Grafik 1 (oben): Muster sliders\nDrei Regler")
			->toContain("Grafik 3 (im Abschnitt «Was man wissen muss»): Muster steps\nVier Schritte")
			->not->toContain('Grafik 2')
			->not->toContain('keine interaktive Grafik');
	});

	it('notes a wish the analysis forgot', function () {
		$this->fake->push('analysis', [...analysis(), 'graphic_plans' => [
			['number' => 1, 'plan' => plan('sliders', 'Drei Regler'), 'note' => null],
		]]);

		upload(wishes());

		expect(Lesson::sole()->graphics->pluck('error', 'position')->all())->toBe([
			1 => null,
			2 => 'Für diese Grafik hat die KI keinen Plan erstellt.',
			3 => 'Für diese Grafik hat die KI keinen Plan erstellt.',
		]);
	});

	it('only plans graphic 1 when the ai decides', function () {
		$this->fake->push('analysis', [...analysis(), 'graphic_plans' => [
			['number' => 1, 'plan' => plan('sliders', 'Drei Regler'), 'note' => null],
			['number' => 2, 'plan' => plan('steps', 'Vier Schritte'), 'note' => null],
		]]);

		upload(['graphics_mode' => 'auto']);

		$lesson = Lesson::sole();
		expect($lesson->graphics->pluck('plan', 'position')->all())->toBe([1 => plan('sliders', 'Drei Regler')])
			->and($this->fake->requestsFor('modules')[0]->prompt)->toContain("Grafik 1 (oben): Muster sliders\nDrei Regler");
	});

	it('forgets the plan of an earlier attempt when the ai now decides against a graphic', function () {
		$this->fake->push('page', new ModelException('Die KI war nicht erreichbar.'));
		upload(['graphics_mode' => 'auto']);
		$lesson = Lesson::sole();
		expect($lesson->status)->toBe(LessonStatus::Failed)
			->and($lesson->graphic(1)->plan)->not->toBeNull();

		$this->fake->push('analysis', [...analysis(), 'graphic_plans' => []]);
		$this->actingAs($this->user)->post(route('lessons.retry', $lesson));

		expect($lesson->fresh()->status)->toBe(LessonStatus::Review)
			->and($lesson->graphics()->count())->toBe(0)
			->and($this->fake->requestsFor('graphic'))->toBe([]);
	});

	it('keeps a finished graphic when a new analysis has no plan for it', function () {
		$lesson = Lesson::factory()->for($this->child)->fromFixture()->create(['graphics_mode' => 'auto', 'prompt' => 'Fotosynthese']);
		$this->fake->push('analysis', [...analysis(), 'graphic_plans' => []]);

		app(AnalyzeLessonAction::class)->handle($lesson);

		expect($lesson->graphic(1)->graphic)->toBe(LessonFactory::fixture('fotosynthese.graphic'));
	});

	it('stores no plan when the parents switched the graphics off', function () {
		$this->fake->push('analysis', [...analysis(), 'graphic_plans' => [
			['number' => 1, 'plan' => plan('sliders', 'Drei Regler'), 'note' => null],
		]]);

		upload(['graphics_mode' => 'none']);

		expect(Lesson::sole()->graphics()->count())->toBe(0)
			->and($this->fake->requestsFor('modules')[0]->prompt)->toContain('Diese Seite hat keine interaktive Grafik.');
	});
});

describe('graphic generation', function () {
	/**
	 * Text part with the block for graphic 2, as the answer of the step «page».
	 */
	function pageWithGraphic2(): array
	{
		$page = Prompts::page(LessonFactory::fixture('fotosynthese'));
		$page['sections'][1]['blocks'][] = ['type' => 'graphic', 'number' => 2, 'origin' => 'photo'];

		return ['page' => $page];
	}

	function threePlans(): array
	{
		return [...analysis(), 'graphic_plans' => [
			['number' => 1, 'plan' => plan('sliders', 'Drei Regler'), 'note' => null],
			['number' => 2, 'plan' => plan('steps', 'Vier Schritte'), 'note' => null],
			['number' => 3, 'plan' => plan('calculator', 'Ein Rechner'), 'note' => null],
		]];
	}

	it('queues one job per possible graphic', function (string $mode, int $jobs) {
		Bus::fake();

		upload(['graphics_mode' => $mode, ...($mode === 'custom' ? ['graphics' => wishes()['graphics']] : [])]);

		Bus::assertChained([
			AnalyzeLesson::class,
			CheckLesson::class,
			...array_fill(0, $jobs, GenerateLessonGraphic::class),
			FinishLesson::class,
		]);
	})->with([['none', 0], ['auto', 1], ['custom', 3]]);

	it('builds each graphic on its own and keeps the others when one breaks', function () {
		$broken = [...LessonFactory::fixture('fotosynthese.graphic'), 'markup' => '<button onclick="x()">Los</button>'];
		$this->fake->push('analysis', threePlans());
		$this->fake->push('page', pageWithGraphic2());
		$this->fake->push('graphic', function (ModelRequest $request) {
			expect(Lesson::sole()->step)->toBe('graphic-1');

			return LessonFactory::fixture('fotosynthese.graphic');
		});
		$this->fake->push('graphic', function (ModelRequest $request) use ($broken) {
			expect(Lesson::sole()->step)->toBe('graphic-2');

			return $broken;
		});
		$this->fake->push('graphic-repair', $broken);
		$this->fake->push('graphic', LessonFactory::fixture('oekosystem.graphic'));

		upload(wishes());

		$lesson = Lesson::sole();
		$requests = $this->fake->requestsFor('graphic');

		expect($lesson->status)->toBe(LessonStatus::Review)
			->and($requests)->toHaveCount(3)
			->and($lesson->graphic(1)->graphic)->toBe(LessonFactory::fixture('fotosynthese.graphic'))
			->and($lesson->graphic(1)->error)->toBeNull()
			->and($lesson->graphic(2)->graphic)->toBeNull()
			->and($lesson->graphic(2)->error)->toContain('Inline-Event-Handler')
			->and($lesson->graphic(3)->graphic)->toBe(LessonFactory::fixture('oekosystem.graphic'))
			->and($lesson->graphic(3)->error)->toBeNull();

		expect($requests[0]->prompt)->toContain("Muster: sliders\nDrei Regler")->not->toContain('nicht oben auf der Seite')
			->and($requests[1]->prompt)->toContain("Muster: steps\nVier Schritte")
			->toContain('Diese Grafik steht im Abschnitt «Was man wissen muss» neben dem Text, nicht oben auf der Seite.')
			->and($requests[2]->prompt)->toContain("Muster: calculator\nEin Rechner")
			->toContain('Diese Grafik steht weiter unten auf der Seite neben dem Text, nicht oben auf der Seite.');

		foreach ($requests as $request) {
			expect($request->prompt)->not->toContain('origin')
				->not->toContain('Ein Blatt mit Reglern');
		}
	});

	it('skips a graphic whose wish did not fit', function () {
		$this->fake->push('page', pageWithGraphic2());
		$this->fake->push('analysis', [...threePlans(), 'graphic_plans' => [
			['number' => 1, 'plan' => plan('sliders', 'Drei Regler'), 'note' => null],
			['number' => 2, 'plan' => null, 'note' => 'Passt nicht zu den Fotos.'],
		]]);

		upload(wishes());

		$lesson = Lesson::sole();
		expect($this->fake->requestsFor('graphic'))->toHaveCount(1)
			->and($lesson->graphic(2)->error)->toBe('Passt nicht zu den Fotos.')
			->and($lesson->graphic(3)->error)->toBe('Für diese Grafik hat die KI keinen Plan erstellt.')
			->and($lesson->status)->toBe(LessonStatus::Review);
	});

	it('only builds the missing graphics on a retry', function () {
		$lesson = Lesson::factory()->for($this->child)->fromFixture()->create([
			'status' => LessonStatus::Failed,
			'graphics_mode' => 'custom',
		]);
		$lesson->graphics()->create(['position' => 2, 'plan' => plan('steps', 'Vier Schritte'), 'error' => 'Die KI war nicht erreichbar.']);
		$lesson->graphics()->create(['position' => 3, 'request' => 'Ein Vulkan', 'error' => 'Passt nicht zu den Fotos.']);

		$this->actingAs($this->user)->post(route('lessons.retry', $lesson));

		$lesson->refresh();
		expect(collect($this->fake->requests)->pluck('step')->all())->toBe(['graphic'])
			->and($this->fake->requests[0]->prompt)->toContain("Muster: steps\nVier Schritte")
			->and($lesson->graphic(2)->graphic)->toBe(LessonFactory::fixture('fotosynthese.graphic'))
			->and($lesson->graphic(2)->error)->toBeNull()
			->and($lesson->graphic(3)->error)->toBe('Passt nicht zu den Fotos.')
			->and($lesson->status)->toBe(LessonStatus::Review);
	});

	it('uses the model settings of the graphic step', function () {
		config()->set('lessons.models.graphic', ['model' => 'claude-test-graphic', 'effort' => 'medium']);
		$this->fake->push('analysis', threePlans());
		$this->fake->push('page', pageWithGraphic2());

		upload(wishes());

		expect(collect($this->fake->requestsFor('graphic'))->map->model()->unique()->all())->toBe(['claude-test-graphic'])
			->and($this->fake->requestsFor('graphic'))->toHaveCount(3);
	});
});

describe('purpose, scope and modules', function () {
	beforeEach(fn () => Bus::fake());

	it('stores purpose, scope and allowed modules', function () {
		upload(['purpose' => 'exam', 'scope' => 'short', 'modules' => ['flashcards', 'quiz']])->assertSessionHasNoErrors();

		$lesson = Lesson::sole();
		expect($lesson->purpose)->toBe('exam')
			->and($lesson->scope)->toBe('short')
			->and($lesson->modules)->toBe(['flashcards', 'quiz']);
	});

	it('has defaults for lessons without settings', function () {
		$lesson = Lesson::factory()->create()->fresh();

		expect($lesson->purpose)->toBe('new')
			->and($lesson->scope)->toBe('normal')
			->and($lesson->modules)->toBeNull();
	});

	it('rejects invalid settings', function (array $data, string $field, ?string $message = null) {
		upload($data)->assertSessionHasErrors($message ? [$field => $message] : $field);

		expect(Lesson::count())->toBe(0);
	})->with([
		'no purpose' => [['purpose' => null], 'purpose', 'Wähle den Zweck der Lernseite.'],
		'unknown purpose' => [['purpose' => 'spass'], 'purpose', 'Wähle den Zweck der Lernseite.'],
		'no scope' => [['scope' => null], 'scope', 'Wähle den Umfang der Lernseite.'],
		'unknown scope' => [['scope' => 'riesig'], 'scope', 'Wähle den Umfang der Lernseite.'],
		'no modules' => [['modules' => []], 'modules', 'Wähle mindestens ein Lernmodul.'],
		'modules not a list' => [['modules' => 'quiz'], 'modules'],
		'unknown module' => [['modules' => ['quiz', 'memory']], 'modules.1', 'Wähle die Lernmodule aus der Liste.'],
		'duplicate module' => [['modules' => ['quiz', 'quiz']], 'modules.1'],
	]);

	it('rejects a form without purpose, scope and modules', function () {
		$this->actingAs($this->user)->post(route('lessons.store'), [
			'child_id' => $this->child->id,
			'subject' => 'Biologie',
			'level' => '2. Sek',
			'prompt' => 'Prüfung am Freitag',
			'graphics_mode' => 'auto',
		])->assertSessionHasErrors([
			'purpose' => 'Wähle den Zweck der Lernseite.',
			'scope' => 'Wähle den Umfang der Lernseite.',
			'modules' => 'Wähle mindestens ein Lernmodul.',
		]);

		expect(Lesson::count())->toBe(0);
	});
});

describe('prompts for purpose, scope and modules', function () {
	it('tells the steps the purpose, the scope and the allowed modules', function () {
		upload(['purpose' => 'exam', 'scope' => 'short', 'modules' => ['flashcards', 'quiz']]);

		$analysis = $this->fake->requestsFor('analysis')[0]->prompt;
		$modules = $this->fake->requestsFor('modules')[0]->prompt;

		expect($analysis)->toContain('Zweck: Prüfungsvorbereitung')
			->toContain('Umfang: kurz (1–2 Abschnitte)')
			->and($modules)->toContain('Zweck: Prüfungsvorbereitung')
			->toContain('Umfang: kurz (1–2 Abschnitte)')
			->toContain('Erlaubte Lernmodule: Quiz (genau 3 Fragen), Karteikarten (4–6 Karten)')
			->and($this->fake->requestsFor('check')[0]->prompt)->toContain('Erlaubte Lernmodule: Quiz (genau 3 Fragen), Karteikarten (4–6 Karten)');
	});

	it('explains purpose, scope and modules in the system prompts', function () {
		upload();

		expect($this->fake->requestsFor('analysis')[0]->system)->toContain('«Prüfungsvorbereitung»')
			->toContain('«Das Wichtigste für die Prüfung»')
			->toContain('Die Zeile «Umfang»')
			->and($this->fake->requestsFor('modules')[0]->system)->toContain('Die Zeile «Erlaubte Lernmodule»')
			->not->toContain('genau 5 Fragen');
	});

	it('names each purpose and scope', function (array $data, array $lines) {
		upload($data);

		expect($this->fake->requestsFor('modules')[0]->prompt)->toContain(...$lines);
	})->with([
		'defaults' => [[], ['Zweck: Neuer Stoff', 'Umfang: normal (1–3 Abschnitte)', 'Erlaubte Lernmodule: Quiz (genau 5 Fragen), Sortierspiel (8–12 Begriffe), Karteikarten (5–10 Karten), Lückentext (4–8 Lücken)']],
		'detailed' => [['scope' => 'detailed', 'modules' => ['cloze', 'sorting']], ['Umfang: ausführlich (2–4 Abschnitte)', 'Erlaubte Lernmodule: Sortierspiel (10–16 Begriffe), Lückentext (6–10 Lücken)']],
	]);

	it('keeps these lines away from the graphic', function () {
		upload(['purpose' => 'exam', 'scope' => 'short']);

		expect($this->fake->requestsFor('graphic')[0]->prompt)->not->toContain('Zweck:')
			->not->toContain('Umfang:')
			->not->toContain('Erlaubte Lernmodule');
	});

	it('drops modules the parents did not allow', function () {
		upload(['modules' => ['flashcards']]);

		$lesson = Lesson::sole();
		expect($lesson->status)->toBe(LessonStatus::Review)
			->and($lesson->content['modules']['quiz'])->toBeNull()
			->and($lesson->content['modules']['cloze'])->toBeNull()
			->and($lesson->content['modules']['flashcards'])->toBe(LessonFactory::fixture('fotosynthese')['modules']['flashcards'])
			->and($this->fake->requestsFor('repair-modules'))->toBe([]);
	});

	it('repairs a page left without any module', function () {
		// Photosynthesis has no sorting game: without the other modules nothing is left
		upload(['modules' => ['sorting']]);

		expect($this->fake->requestsFor('repair-modules')[0]->prompt)->toContain('Die Seite braucht mindestens ein Lernmodul.')
			->and(Lesson::sole()->status)->toBe(LessonStatus::Failed);
	});

	it('allows all modules on old lessons', function () {
		$lesson = Lesson::factory()->fromFixture()->create();

		expect($lesson->modules)->toBeNull()
			->and(Prompts::modules($lesson, Prompts::page($lesson->content))->prompt)
			->toContain('Erlaubte Lernmodule: Quiz (genau 5 Fragen), Sortierspiel (8–12 Begriffe), Karteikarten (5–10 Karten), Lückentext (4–8 Lücken)')
			->and(Prompts::quiz($lesson)->prompt)->toContain('genau 5 Fragen (IDs q1–q5)');
	});
});

describe('subject detected by the ai', function () {
	it('stores no subject and takes the level from the child when both are left out', function () {
		Bus::fake();

		upload(['subject' => '', 'level' => ''])->assertSessionHasNoErrors();

		$lesson = Lesson::sole();
		expect($lesson->subject)->toBeNull()
			->and($lesson->level)->toBe('2. Sek');
	});

	it('still takes a given level over the child’s level', function () {
		Bus::fake();

		upload(['subject' => ' Biologie ', 'level' => '3. Sek']);

		expect(Lesson::sole()->subject)->toBe('Biologie')
			->and(Lesson::sole()->level)->toBe('3. Sek');
	});

	it('requires the level when the child has none', function () {
		$this->child->update(['level' => null]);

		upload(['level' => ''])->assertSessionHasErrors(['level' => 'Gib die Stufe an.']);

		expect(Lesson::count())->toBe(0);
	});

	it('requires the level for a new child', function () {
		upload(['child_id' => null, 'child_name' => 'Noah', 'level' => ''])
			->assertSessionHasErrors(['level' => 'Gib die Stufe an.']);
	});

	it('asks the analysis to detect the subject and stores it before the page call', function () {
		$this->fake->push('analysis', analysis(['subject' => '  Natur und Technik ']));

		upload(['subject' => '']);

		expect($this->fake->requestsFor('analysis')[0]->prompt)
			->toContain('Fach: unbekannt, erkenne es aus den Fotos oder dem Auftrag')
			->and($this->fake->requestsFor('page')[0]->prompt)->toContain('Fach: Natur und Technik')
			->and(Lesson::sole()->subject)->toBe('Natur und Technik');
	});

	it('falls back to a general subject when the analysis returns none', function () {
		$this->fake->push('analysis', analysis(['subject' => ' ']));

		upload(['subject' => '']);

		expect(Lesson::sole()->subject)->toBe('Allgemein');
	});

	it('keeps the subject the parents gave', function () {
		$this->fake->push('analysis', analysis(['subject' => 'Natur und Technik']));

		upload();

		expect(Lesson::sole()->subject)->toBe('Biologie');
	});

	it('asks for the subject in the analysis schema', function () {
		expect(Schemas::analysis()['properties'])->toHaveKey('subject')
			->and(Schemas::analysis()['required'])->toContain('subject')
			->and(file_get_contents(resource_path('prompts/analysis.md')))->toContain('`subject`');
	});

	it('remembers whether the subject was detected', function () {
		$this->fake->push('analysis', analysis(['subject' => 'Chemie']));
		upload(['subject' => '']);
		expect(Lesson::sole()->subject_detected)->toBeTrue();

		Lesson::query()->forceDelete();
		upload();
		expect(Lesson::sole()->subject_detected)->toBeFalse();
	});

	it('detects the subject again on a retry', function () {
		$this->fake->push('analysis', analysis(['subject' => 'Chemie']));
		$this->fake->push('page', new ModelException('Die KI war nicht erreichbar.'));
		upload(['subject' => '']);
		$lesson = Lesson::sole();
		expect($lesson->subject)->toBe('Chemie');

		$this->fake->push('analysis', analysis(['subject' => 'Biologie']));
		$this->actingAs($this->user)->post(route('lessons.retry', $lesson));

		expect($this->fake->requestsFor('analysis')[1]->prompt)->toContain('Fach: unbekannt, erkenne es aus den Fotos oder dem Auftrag')
			->and($lesson->fresh()->subject)->toBe('Biologie')
			->and($lesson->fresh()->subject_detected)->toBeTrue();
	});

	it('keeps the subject of the parents on a retry', function () {
		$this->fake->push('page', new ModelException('Die KI war nicht erreichbar.'));
		upload(['subject' => 'Biologie']);
		$lesson = Lesson::sole();

		$this->fake->push('analysis', analysis(['subject' => 'Chemie']));
		$this->actingAs($this->user)->post(route('lessons.retry', $lesson));

		expect($this->fake->requestsFor('analysis')[1]->prompt)->toContain('Fach: Biologie')
			->and($lesson->fresh()->subject)->toBe('Biologie')
			->and($lesson->fresh()->subject_detected)->toBeFalse();
	});

	it('shows an unknown subject for lessons that failed before the detection', function () {
		$failed = lessonFor($this->child, ['subject' => null, 'title' => null, 'prompt' => 'Brüche', 'status' => LessonStatus::Failed]);
		lessonFor($this->child, ['subject' => null, 'title' => null, 'prompt' => 'Atome', 'status' => LessonStatus::Generating]);
		lessonFor($this->child, ['subject' => 'Biologie', 'status' => LessonStatus::Review]);

		$this->actingAs($this->user)->get(route('dashboard'))
			->assertInertia(fn (Assert $page) => $page
				->where('children.0.subjects.0.name', 'Fach unbekannt')
				->where('children.0.subjects.0.lessons.0.title', 'Brüche')
				->where('children.0.subjects.1.name', 'Fach wird erkannt …')
				->where('children.0.subjects.1.lessons.0.title', 'Atome')
				->where('children.0.subjects.2.name', 'Biologie')
			);
		$this->actingAs($this->user)->get(route('lessons.show', $failed))
			->assertInertia(fn (Assert $page) => $page->where('lesson.subject', 'Fach unbekannt'));
	});

	it('shows lessons without a subject in the library, the costs and the lesson', function () {
		$lesson = lessonFor($this->child, ['subject' => null, 'title' => null, 'prompt' => 'Brüche', 'status' => LessonStatus::Generating]);
		$lesson->generations()->create(['user_id' => $this->user->id, 'step' => 'analysis', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'cost_usd' => 0.1]);

		$this->actingAs($this->user)->get(route('dashboard'))
			->assertOk()
			->assertInertia(fn (Assert $page) => $page
				->where('children.0.subjects.0.name', 'Fach wird erkannt …')
				->where('children.0.subjects.0.lessons.0.title', 'Brüche')
			);

		$this->actingAs($this->user)->get(route('costs'))->assertOk();
		$this->actingAs($this->user)->get(route('lessons.create'))
			->assertOk()
			->assertInertia(fn (Assert $page) => $page->has('lastSettings', 0));
		$this->actingAs($this->user)->get(route('lessons.show', $lesson))
			->assertOk()
			->assertInertia(fn (Assert $page) => $page->where('lesson.subject', 'Fach wird erkannt …'));
	});
});

describe('subject profiles', function () {
	function addendum(Profile $profile): string
	{
		return trim((string) file_get_contents($profile->promptFile()));
	}

	it('derives the profile from a detected subject after the analysis', function () {
		$this->fake->push('analysis', analysis(['subject' => 'Chemie']));

		upload(['subject' => '']);

		$page = $this->fake->requestsFor('page')[0];
		expect(Lesson::sole()->profile)->toBeNull()
			->and(Lesson::sole()->resolvedProfile())->toBe(Profile::Science)
			->and($this->fake->requestsFor('analysis')[0]->prompt)->not->toContain('Fachprofil')
			->and($page->prompt)->toContain('Fachprofil: Naturwissenschaften')
			->toContain(addendum(Profile::Science))
			->and($page->schema)->toBe(Schemas::part('page', Profile::Science))
			->and($this->fake->requestsFor('modules')[0]->prompt)->toContain('Fachprofil: Naturwissenschaften')
			->toContain(addendum(Profile::Science));
	});

	it('takes the profile the parents chose over the subject', function () {
		upload(['subject' => 'Biologie', 'profile' => 'general'])->assertSessionHasNoErrors();

		$lesson = Lesson::sole();
		$page = $this->fake->requestsFor('page')[0];
		expect($lesson->profile)->toBe(Profile::General)
			->and($page->prompt)->toContain('Fachprofil: Allgemein')
			->toContain(addendum(Profile::General))
			->not->toContain('Fachprofil: Naturwissenschaften')
			->and($page->schema['properties']['page']['properties'])->not->toHaveKey('try_it')
			->and($this->fake->requestsFor('modules')[0]->schema)->toBe(Schemas::modulesResult(Profile::General));
	});

	it('sets the parts a profile does not allow to null', function () {
		// The fake returns the photosynthesis page with experiments anyway
		upload(['profile' => 'general']);

		$lesson = Lesson::sole();
		expect($lesson->status)->toBe(LessonStatus::Review)
			->and($lesson->content)->toHaveKey('try_it')
			->and($lesson->content['try_it'])->toBeNull()
			->and($lesson->content['modules'])->toHaveKeys(ContentValidator::MODULES)
			->and($lesson->content['modules']['exercises'])->toBeNull();
	});

	it('gives the graphic only the label of the profile', function () {
		upload();

		expect($this->fake->requestsFor('graphic')[0]->prompt)->toContain('Fachprofil: Naturwissenschaften')
			->not->toContain(addendum(Profile::Science));
	});

	it('shows the graphic example of the profile, else the photosynthesis one', function (string $subject, string $fixture, string $other) {
		$lesson = Lesson::factory()->fromFixture($fixture)->create(['subject' => $subject]);

		expect(Prompts::graphic($lesson, $lesson->graphics()->sole())->system)
			->toContain('### '.LessonFactory::fixture($fixture)['meta']['title'])
			->toContain(json_encode(LessonFactory::fixture("{$fixture}.graphic")['pattern']))
			->not->toContain(LessonFactory::fixture($other)['meta']['title']);
	})->with([
		'math' => ['Mathematik', 'dreisatz', 'fotosynthese'],
		'geometry' => ['Geometrie', 'winkel-parallelen', 'dreisatz'],
		'science' => ['Biologie', 'fotosynthese', 'dreisatz'],
		'languages without a graphic of its own' => ['Französisch', 'fotosynthese', 'dreisatz'],
	]);

	it('names the topic of the example the profile uses', function () {
		$lesson = Lesson::factory()->fromFixture('dreisatz')->create(['subject' => 'Mathematik']);

		expect(Prompts::pageRequest($lesson, [])->system)->toContain('(Thema Dreisatz)')->not->toContain('(Thema Fotosynthese)')
			->and(Prompts::modules($lesson, Prompts::page($lesson->content))->system)->toContain('(Thema Dreisatz)')->not->toContain('(Thema Fotosynthese)')
			->and(Prompts::analysis($lesson, [])->system)->toContain('(Thema Fotosynthese)');
	});

	it('tells the system prompts that the profile comes first', function () {
		$rule = 'Halte dich an den Abschnitt «Fachprofil», er geht den allgemeinen Regeln vor.';

		expect(file_get_contents(resource_path('prompts/analysis.md')))->toContain($rule)
			->and(file_get_contents(resource_path('prompts/modules.md')))->toContain($rule);
	});

	it('derives the profile again on a retry with a detected subject', function () {
		$this->fake->push('analysis', analysis(['subject' => 'Chemie']));
		$this->fake->push('page', new ModelException('Die KI war nicht erreichbar.'));
		upload(['subject' => '']);
		$lesson = Lesson::sole();

		$this->fake->push('analysis', analysis(['subject' => 'Geschichte']));
		$this->actingAs($this->user)->post(route('lessons.retry', $lesson));

		expect($this->fake->requestsFor('page')[1]->prompt)->toContain('Fachprofil: Allgemein')
			->and($lesson->fresh()->resolvedProfile())->toBe(Profile::General);
	});

	it('builds a languages page with vocabulary and conjugation from its own example', function () {
		$french = LessonFactory::fixture('passe-compose');
		$this->fake->push('analysis', analysis(['subject' => 'Französisch', 'graphic_plans' => []]));
		$this->fake->push('page', ['page' => Arr::except(Prompts::page($french), 'try_it')]);
		$this->fake->push('modules', ['modules' => $french['modules']]);

		upload(['subject' => '', 'graphics_mode' => 'auto']);

		$lesson = Lesson::sole();
		$page = $this->fake->requestsFor('page')[0];
		expect($lesson->status)->toBe(LessonStatus::Review)
			->and($lesson->resolvedProfile())->toBe(Profile::Languages)
			->and($lesson->content['sections'][1]['blocks'][1]['type'])->toBe('vocabulary')
			->and($page->prompt)->toContain('Fachprofil: Sprachen')
			->and($page->system)->toContain('"conjugation"')->not->toContain('Chloroplasten')
			->and(json_encode($page->schema))->toContain('"vocabulary"')
			->and($this->fake->requestsFor('modules')[0]->system)->toContain('nous avons fini');
	});

	it('builds a math page with a worked solution and exercises from its own example', function () {
		$math = LessonFactory::fixture('dreisatz');
		$this->fake->push('analysis', analysis(['subject' => 'Mathematik', 'graphic_plans' => []]));
		$this->fake->push('page', ['page' => Arr::except(Prompts::page($math), 'try_it')]);
		$this->fake->push('modules', ['modules' => $math['modules']]);

		upload(['subject' => '', 'graphics_mode' => 'auto', 'modules' => ['quiz', 'exercises']]);

		$lesson = Lesson::sole();
		$page = $this->fake->requestsFor('page')[0];
		$modules = $this->fake->requestsFor('modules')[0];
		expect($lesson->status)->toBe(LessonStatus::Review)
			->and($lesson->resolvedProfile())->toBe(Profile::Math)
			->and($lesson->content['sections'][1]['blocks'][0]['type'])->toBe('worked_solution')
			->and($lesson->content['modules']['exercises']['entries'])->toHaveCount(5)
			->and($lesson->content['modules']['cloze'])->toBeNull()
			->and($page->prompt)->toContain('Fachprofil: Mathematik')
			->and(json_encode($page->schema))->toContain('"worked_solution"')->not->toContain('"columns"')
			->and($modules->prompt)->toContain('Übungen (genau 12 Aufgaben)')
			->and($modules->system)->toContain('solution_path');
	});

	it('builds a german page with find the mistake and a case sensitive cloze from its own example', function () {
		$german = LessonFactory::fixture('das-dass');
		$this->fake->push('analysis', analysis(['subject' => 'Deutsch', 'graphic_plans' => []]));
		$this->fake->push('page', ['page' => Arr::except(Prompts::page($german), 'try_it')]);
		$this->fake->push('modules', ['modules' => Arr::except($german['modules'], 'exercises')]);

		upload(['subject' => '', 'graphics_mode' => 'auto', 'modules' => ['quiz', 'cloze']]);

		$lesson = Lesson::sole();
		$modules = $this->fake->requestsFor('modules')[0];
		expect($lesson->status)->toBe(LessonStatus::Review)
			->and($lesson->resolvedProfile())->toBe(Profile::German)
			// The parents can't choose find the mistake: it comes with the german profile
			->and($lesson->content['modules']['find_the_mistake']['entries'])->toHaveCount(6)
			->and($lesson->content['modules']['cloze']['case_sensitive'])->toBeTrue()
			->and($lesson->content['modules']['sorting'])->toBeNull()
			->and($lesson->content['modules']['exercises'])->toBeNull()
			->and($modules->prompt)->toContain('Fehler finden (')
			->and($modules->system)->toContain('mistake_word')->toContain('Ich hoffe, das du morgen kommst.')
			->and(json_encode($modules->schema))->toContain('"case_sensitive"');
	});

	it('leaves out the exercises of a math page when the parents did not choose them', function () {
		$math = LessonFactory::fixture('dreisatz');
		$this->fake->push('analysis', analysis(['subject' => 'Mathematik', 'graphic_plans' => []]));
		$this->fake->push('page', ['page' => Arr::except(Prompts::page($math), 'try_it')]);
		$this->fake->push('modules', ['modules' => $math['modules']]);

		upload(['subject' => '', 'graphics_mode' => 'auto', 'modules' => ['quiz', 'cloze']]);

		$lesson = Lesson::sole();
		expect($lesson->status)->toBe(LessonStatus::Review)
			->and($lesson->content['modules']['exercises'])->toBeNull()
			->and($lesson->content['modules']['cloze'])->not->toBeNull()
			->and($this->fake->requestsFor('modules')[0]->prompt)->not->toContain('Übungen');
	});

	it('builds a geometry page with a figure from its own example', function () {
		$geometry = LessonFactory::fixture('winkel-parallelen');
		$this->fake->push('analysis', analysis(['subject' => 'Geometrie', 'graphic_plans' => []]));
		$this->fake->push('page', ['page' => Arr::except(Prompts::page($geometry), 'try_it')]);
		$this->fake->push('modules', ['modules' => $geometry['modules']]);

		upload(['subject' => '', 'graphics_mode' => 'auto']);

		$lesson = Lesson::sole();
		$page = $this->fake->requestsFor('page')[0];
		expect($lesson->status)->toBe(LessonStatus::Review)
			->and($lesson->resolvedProfile())->toBe(Profile::Geometry)
			->and($lesson->content['sections'][0]['blocks'][1]['type'])->toBe('figure')
			->and($page->prompt)->toContain('Fachprofil: Geometrie')
			->and($page->system)->toContain('"vertex"')->toContain('(Thema Winkel an Parallelen)')
			->and(json_encode($page->schema))->toContain('"figure"')->not->toContain('"facts"');
	});

	it('repairs a fresh geometry page whose figure points to a missing point', function () {
		$geometry = LessonFactory::fixture('winkel-parallelen');
		$broken = Arr::except(Prompts::page($geometry), 'try_it');
		$broken['sections'][0]['blocks'][1]['lines'][0]['to'] = 'Z';
		$this->fake->push('analysis', analysis(['subject' => 'Geometrie', 'graphic_plans' => []]));
		$this->fake->push('page', ['page' => $broken]);
		$this->fake->push('modules', ['modules' => $geometry['modules']]);
		$this->fake->push('repair-page', ['page' => Arr::except(Prompts::page($geometry), 'try_it')]);

		upload(['subject' => '', 'graphics_mode' => 'auto']);

		expect($this->fake->requestsFor('repair-page')[0]->prompt)->toContain('Der Punkt «Z» ist nicht definiert.')
			->and(Lesson::sole()->content['sections'][0]['blocks'][1]['lines'][0]['to'])->toBe('G2');
	});

	it('rejects broken tex in a fresh math page and repairs it', function () {
		$math = LessonFactory::fixture('dreisatz');
		$broken = Arr::except(Prompts::page($math), 'try_it');
		$broken['sections'][0]['blocks'][0]['text'] = 'Es kostet $3 : 4.';
		$this->fake->push('analysis', analysis(['subject' => 'Mathematik', 'graphic_plans' => []]));
		$this->fake->push('page', ['page' => $broken]);
		$this->fake->push('modules', ['modules' => $math['modules']]);
		$this->fake->push('repair-page', ['page' => Arr::except(Prompts::page($math), 'try_it')]);

		upload(['subject' => '', 'graphics_mode' => 'auto']);

		expect($this->fake->requestsFor('repair-page')[0]->prompt)->toContain('ist ein «$» nicht geschlossen')
			->and(Lesson::sole()->content['sections'][0]['blocks'][0]['text'])->not->toContain('$3 : 4.');
	});

	it('treats old lessons without a profile like an automatic one', function () {
		$lesson = Lesson::factory()->fromFixture()->create(['subject' => 'Geschichte']);

		expect($lesson->profile)->toBeNull()
			->and(Prompts::quiz($lesson)->prompt)->toContain('Fachprofil: Allgemein');
	});

	it('rejects an unknown profile', function () {
		Bus::fake();

		upload(['profile' => 'kunst'])->assertSessionHasErrors(['profile' => 'Wähle ein Fachprofil aus der Liste.']);

		expect(Lesson::count())->toBe(0);
	});

	it('offers the profiles in the form', function () {
		$this->actingAs($this->user)->get(route('lessons.create'))
			->assertInertia(fn (Assert $page) => $page
				->has('profiles', 6)
				->where('profiles.0', ['value' => 'science', 'label' => 'Naturwissenschaften'])
			);
	});
});
