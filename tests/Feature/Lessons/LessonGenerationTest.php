<?php

use App\Enums\LessonStatus;
use App\Jobs\AnalyzeLesson;
use App\Jobs\CheckLesson;
use App\Jobs\FinishLesson;
use App\Jobs\GenerateLessonHero;
use App\Lessons\Ai\FakeLanguageModel;
use App\Lessons\Ai\ModelException;
use App\Lessons\Ai\ModelRequest;
use App\Lessons\Ai\Prompts;
use App\Lessons\GenerationFailed;
use App\Lessons\LessonGenerator;
use App\Models\Child;
use App\Models\Generation;
use App\Models\Lesson;
use App\Models\User;
use Database\Factories\LessonFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Ein JPEG mit EXIF-Block (wie von einer Handykamera), grösser als erlaubt.
 */
function photo(string $name = 'seite.jpg', int $width = 3000, int $height = 2000): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 240, 240, 230));
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();

    // APP1-Segment mit «Exif» und einem erkennbaren Marker direkt nach dem SOI einfügen
    $payload = "Exif\0\0II*\0\x08\0\0\0\0\0GPS-MARKER-47.3769N";
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return UploadedFile::fake()->createWithContent($name, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));
}

function analysis(array $changes = []): array
{
    return array_replace_recursive(FakeLanguageModel::defaultResponse('analyse'), $changes);
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
        ->and($lesson->hero['muster'])->toBe('regler')
        ->and($lesson->hero_plan['muster'])->toBe('regler')
        ->and($lesson->source_summary)->toContain('Fotosynthese')
        ->and($lesson->schema_version)->toBe(1);

    expect($lesson->generations()->pluck('step')->all())->toBe(['analyse', 'module', 'pruefung', 'grafik'])
        ->and($lesson->generations()->where('status', 'ok')->count())->toBe(4)
        ->and($lesson->generations()->pluck('user_id')->unique()->all())->toBe([$this->user->id]);

    $this->actingAs($this->user)->get(route('lessons.show', $lesson))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lesson.status', 'review')
            ->where('lesson.content.meta.palette', 'gruen')
            ->has('lesson.hero.url')
            ->where('lesson.heroError', null)
        );
});

it('sends the photos, subject and level, but never the child name', function () {
    upload();

    $request = $this->fake->requestsFor('analyse')[0];

    expect($request->images)->toHaveCount(1)
        ->and($request->prompt)->toContain('Fach: Biologie')
        ->toContain('Stufe: 2. Sek');

    foreach ($this->fake->requests as $sent) {
        expect($sent->system.$sent->prompt)->not->toContain('Mia');
    }
});

it('treats the photos as the frame and the prompt as the focus', function () {
    upload();

    $prompt = $this->fake->requestsFor('analyse')[0]->prompt;

    expect($prompt)->toContain('Die Fotos sind der Rahmen')
        ->toContain('Auftrag der Eltern: Prüfung am Freitag')
        ->not->toContain('zum Thema');
});

it('sends photos without a prompt as before', function () {
    upload(['prompt' => '']);

    expect($this->fake->requestsFor('analyse')[0]->prompt)
        ->toContain('Erstelle den Textteil einer Lernseite aus diesem Foto.')
        ->not->toContain('Auftrag der Eltern');
});

it('passes the prompt to every later step', function (string $step) {
    upload();

    expect($this->fake->requestsFor($step)[0]->prompt)->toContain('Auftrag der Eltern: Prüfung am Freitag');
})->with(['module', 'pruefung', 'grafik']);

it('stores the additions of the analysis and passes them on', function () {
    $this->fake->push('analyse', analysis(['ergaenzungen' => ['Zellatmung ergänzt.']]));

    upload();

    expect(Lesson::sole()->additions)->toBe(['Zellatmung ergänzt.'])
        ->and($this->fake->requestsFor('module')[0]->prompt)
        ->toContain("Ergänzt (nicht auf den Fotos):\n- Zellatmung ergänzt.");
});

it('stores no additions when nothing was added', function () {
    upload();

    expect(Lesson::sole()->additions)->toBeNull()
        ->and($this->fake->requestsFor('module')[0]->prompt)->not->toContain('Ergänzt (nicht auf den Fotos)');
});

it('re-encodes photos without metadata and scales them down', function () {
    $this->fake->push('analyse', function (ModelRequest $request) {
        $data = $request->images[0]['data'];
        [$width, $height] = getimagesizefromstring($data);

        expect($request->images[0]['mime'])->toBe('image/jpeg')
            ->and(max($width, $height))->toBe(1600)
            ->and($data)->not->toContain('Exif')
            ->not->toContain('GPS-MARKER');

        return analysis();
    });

    upload();

    expect($this->fake->requestsFor('analyse'))->toHaveCount(1);
});

it('deletes the photos after a successful analysis', function () {
    upload();

    expect(Lesson::sole()->images()->count())->toBe(0)
        ->and(Storage::disk('lesson-images')->allFiles())->toBe([]);
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

    expect($this->fake->requestsFor('pruefung'))->toBe([])
        ->and(Lesson::sole()->status)->toBe(LessonStatus::Review);
});

it('checks the whole page in one call', function () {
    upload();

    $request = $this->fake->requestsFor('pruefung');
    expect($request)->toHaveCount(1)
        ->and($request[0]->prompt)->toContain('"module"')
        ->and($request[0]->prompt)->toContain('"abschnitte"');
});

it('sends one example graphic to the graphic step', function () {
    upload();

    $system = $this->fake->requestsFor('grafik')[0]->system;
    expect($system)->toContain(LessonFactory::fixture('fotosynthese')['meta']['titel'])
        ->and($system)->not->toContain(LessonFactory::fixture('oekosystem')['meta']['titel']);
});

it('applies the corrections of the check and lists them for the parents', function () {
    $this->fake->push('pruefung', ['korrekturen' => [
        ['pfad' => '/module/quiz/0/tipp', 'wert' => 'Denk an die Zutaten, nicht an das Ergebnis.', 'bereich' => 'Quiz, Frage 1', 'aenderung' => 'Tipp präzisiert.'],
    ]]);

    upload();

    $lesson = Lesson::sole();
    expect($lesson->check_notes)->toBe([['bereich' => 'Quiz, Frage 1', 'aenderung' => 'Tipp präzisiert.']])
        ->and($lesson->content['module']['quiz'][0]['tipp'])->toBe('Denk an die Zutaten, nicht an das Ergebnis.');
});

it('lists one note for several corrections with the same description', function () {
    $this->fake->push('pruefung', ['korrekturen' => [
        ['pfad' => '/module/quiz/0/optionen', 'wert' => '["Kohlenstoffdioxid und Wasser","Sauerstoff und Wasser","Traubenzucker und Sauerstoff","Kohlenstoffdioxid und Traubenzucker"]', 'bereich' => 'Quiz, Frage 1', 'aenderung' => 'Richtige Antwort an den Anfang gestellt.'],
        ['pfad' => '/module/quiz/0/loesung', 'wert' => '0', 'bereich' => 'Quiz, Frage 1', 'aenderung' => 'Richtige Antwort an den Anfang gestellt.'],
    ]]);

    upload();

    $lesson = Lesson::sole();
    expect($lesson->content['module']['quiz'][0]['loesung'])->toBe(0)
        ->and($lesson->check_notes)->toBe([['bereich' => 'Quiz, Frage 1', 'aenderung' => 'Richtige Antwort an den Anfang gestellt.']]);
});

it('drops corrections that would break the content', function () {
    $this->fake->push('pruefung', ['korrekturen' => [
        ['pfad' => '/module/quiz/0/loesung', 'wert' => '99', 'bereich' => 'Quiz, Frage 1', 'aenderung' => 'Lösung korrigiert.'],
    ]]);

    upload();

    expect(Lesson::sole()->content)->toBe(LessonFactory::fixture('fotosynthese'))
        ->and(Lesson::sole()->check_notes)->toBe([])
        ->and(Lesson::sole()->status)->toBe(LessonStatus::Review);
});

it('keeps going when the check call fails', function () {
    $this->fake->push('pruefung', new ModelException('Die KI ist gerade ausgelastet.', retryable: false));

    upload();

    expect(Lesson::sole()->status)->toBe(LessonStatus::Review)
        ->and(Lesson::sole()->content)->toBe(LessonFactory::fixture('fotosynthese'))
        ->and(Lesson::sole()->generations()->where('step', 'pruefung')->value('status'))->toBe('error');
});

it('repairs invalid content once', function () {
    $broken = LessonFactory::fixture('fotosynthese');
    array_pop($broken['module']['quiz']);

    $this->fake->push('module', ['module' => $broken['module']]);

    upload();

    $repair = $this->fake->requestsFor('reparatur-module');
    expect($repair)->toHaveCount(1)
        ->and($this->fake->requestsFor('reparatur-seite'))->toBe([])
        ->and($repair[0]->prompt)->toContain('Das Feld module.quiz muss 5 Elemente enthalten.')
        ->toContain('Gib diesen Teil korrigiert zurück: module')
        ->toContain('Zusammenfassung des Stoffs:')
        ->and(Lesson::sole()->status)->toBe(LessonStatus::Review)
        ->and(Lesson::sole()->content['module']['quiz'])->toHaveCount(5);
});

it('fails when the content is still invalid after the repair', function () {
    $broken = LessonFactory::fixture('fotosynthese');
    $broken['meta']['palette'] = 'neonpink';

    $this->fake->push('analyse', [...analysis(), 'seite' => Prompts::page($broken)]);
    $this->fake->push('reparatur-seite', ['seite' => Prompts::page($broken)]);

    upload();

    $lesson = Lesson::sole();
    expect($lesson->status)->toBe(LessonStatus::Failed)
        ->and($lesson->error)->toBe('Die KI hat keinen gültigen Inhalt geliefert.')
        ->and($this->fake->requestsFor('grafik'))->toBe([]);
});

it('accepts content that only breaks the strict rules after the repair', function () {
    $content = LessonFactory::fixture('fotosynthese');
    foreach ($content['module']['quiz'] as &$question) {
        $question['loesung'] = 1;
    }

    $this->fake->push('module', ['module' => $content['module']]);
    $this->fake->push('reparatur-module', ['module' => $content['module']]);

    upload();

    expect(Lesson::sole()->status)->toBe(LessonStatus::Review);
});

it('tells the parents when the photos are unreadable', function () {
    $this->fake->push('analyse', analysis([
        'quelle' => ['lesbar' => false, 'problem' => 'Die Fotos sind zu unscharf.'],
        'seite' => null,
    ]));

    upload();

    $lesson = Lesson::sole();
    expect($lesson->status)->toBe(LessonStatus::Failed)
        ->and($lesson->error)->toBe('Die Fotos sind zu unscharf.')
        ->and($lesson->images()->count())->toBe(1);

    $this->actingAs($this->user)->get(route('lessons.show', $lesson))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lesson.status', 'failed')
            ->where('lesson.error', 'Die Fotos sind zu unscharf.')
            ->where('lesson.canRetry', true)
        );
});

it('shows a clear message when the api fails', function () {
    $this->fake->push('analyse', new ModelException('Der Zugang zur KI ist falsch eingerichtet.', 'invalid x-api-key'));

    upload();

    $lesson = Lesson::sole();
    expect($lesson->status)->toBe(LessonStatus::Failed)
        ->and($lesson->error)->toBe('Der Zugang zur KI ist falsch eingerichtet.')
        ->and($lesson->generations()->value('error'))->toBe('invalid x-api-key');
});

it('logs the model of the step when a call fails without a response', function () {
    config()->set('lessons.models.analyse', ['model' => 'claude-test-analyse', 'effort' => 'high']);
    $this->fake->push('analyse', new ModelException('Die KI war nicht erreichbar.'));

    upload();

    expect(Lesson::sole()->generations()->where('step', 'analyse')->value('model'))->toBe('claude-test-analyse');
});

it('publishes the page without a graphic when the hero stays broken', function () {
    $broken = [...LessonFactory::fixture('fotosynthese.hero'), 'markup' => '<button onclick="x()">Los</button>'];

    $this->fake->push('grafik', $broken);
    $this->fake->push('grafik-reparatur', $broken);

    upload();

    $lesson = Lesson::sole();
    expect($lesson->status)->toBe(LessonStatus::Review)
        ->and($lesson->hero)->toBeNull()
        ->and($lesson->hero_error)->toContain('Inline-Event-Handler')
        ->and($this->fake->requestsFor('grafik-reparatur'))->toHaveCount(1);
});

it('repairs a broken hero once', function () {
    $this->fake->push('grafik', [...LessonFactory::fixture('fotosynthese.hero'), 'script' => 'fetch("x")']);

    upload();

    $lesson = Lesson::sole();
    expect($lesson->hero['script'])->toBe(LessonFactory::fixture('fotosynthese.hero')['script'])
        ->and($lesson->hero_error)->toBeNull();
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
    upload(['images' => [], 'prompt' => '', 'subject' => ''])
        ->assertSessionHasErrors([
            'images' => 'Lade mindestens ein Foto hoch oder schreib einen Auftrag.',
            'subject' => 'Gib das Fach an.',
        ]);

    upload(['images' => array_fill(0, 5, photo())])
        ->assertSessionHasErrors(['images' => 'Höchstens 4 Fotos pro Lernseite.']);

    upload(['images' => [UploadedFile::fake()->create('seite.pdf', 100, 'application/pdf')]])
        ->assertSessionHasErrors(['images.0' => 'Nur Fotos im Format JPEG, PNG oder WebP.']);

    expect(Lesson::count())->toBe(0);
});

it('rejects a damaged photo', function () {
    upload(['images' => [UploadedFile::fake()->createWithContent('seite.jpg', "\xFF\xD8\xFFkaputt")]])
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

    Bus::assertChained([AnalyzeLesson::class, CheckLesson::class, GenerateLessonHero::class, FinishLesson::class]);

    $lesson = Lesson::sole();
    expect($lesson->status)->toBe(LessonStatus::Generating)
        ->and($lesson->step)->toBe('warteschlange');

    $this->actingAs($this->user)->get(route('lessons.show', $lesson))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lesson.status', 'generating')
            ->where('lesson.step', 'warteschlange')
            ->where('lesson.content', null)
        );
});

describe('retry', function () {
    it('starts again after a failed analysis', function () {
        $this->fake->push('analyse', new ModelException('Die KI war nicht erreichbar.'));
        upload();
        $lesson = Lesson::sole();

        $this->actingAs($this->user)->post(route('lessons.retry', $lesson))
            ->assertRedirect(route('lessons.show', $lesson));

        expect($lesson->fresh()->status)->toBe(LessonStatus::Review)
            ->and($this->fake->requestsFor('analyse'))->toHaveCount(2);
    });

    it('only regenerates what is missing', function () {
        $lesson = Lesson::factory()->for($this->child)->fromFixture()->create([
            'status' => LessonStatus::Failed,
            'hero' => null,
            'hero_plan' => ['muster' => 'regler', 'idee' => 'Regler'],
        ]);

        $this->actingAs($this->user)->post(route('lessons.retry', $lesson));

        expect(collect($this->fake->requests)->pluck('step')->all())->toBe(['grafik'])
            ->and($lesson->fresh()->status)->toBe(LessonStatus::Review);
    });

    it('still sends the topic and notes of an old lesson', function () {
        $lesson = Lesson::factory()->for($this->child)->create([
            'status' => LessonStatus::Failed,
            'topic' => 'Fotosynthese',
            'notes' => 'Bitte mit Beispielen aus dem Garten',
        ]);

        $this->actingAs($this->user)->post(route('lessons.retry', $lesson));

        expect($this->fake->requestsFor('analyse')[0]->prompt)
            ->toContain('zum Thema «Fotosynthese»')
            ->toContain('Hinweise der Eltern: Bitte mit Beispielen aus dem Garten')
            ->not->toContain('Auftrag der Eltern')
            ->and($this->fake->requestsFor('module')[0]->prompt)
            ->toContain('Hinweise der Eltern: Bitte mit Beispielen aus dem Garten')
            ->and($lesson->fresh()->status)->toBe(LessonStatus::Review);
    });

    it('is not possible without photos and content', function () {
        $lesson = Lesson::factory()->for($this->child)->create(['status' => LessonStatus::Failed]);

        $this->actingAs($this->user)->post(route('lessons.retry', $lesson))->assertStatus(422);
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
        );
});

describe('from a prompt', function () {
    function uploadPrompt(array $data = []): TestResponse
    {
        return upload(['prompt' => 'Biodiversität: Arten, Lebensräume und Gefährdung', 'images' => [], ...$data]);
    }

    it('creates a lesson without photos', function () {
        uploadPrompt()->assertRedirect();

        $lesson = Lesson::sole();
        $request = $this->fake->requestsFor('analyse')[0];

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

        $request = $this->fake->requestsFor('analyse')[0];

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

    it('requires photos or a prompt', function () {
        upload(['prompt' => null, 'images' => []])
            ->assertSessionHasErrors(['images' => 'Lade mindestens ein Foto hoch oder schreib einen Auftrag.']);

        uploadPrompt(['prompt' => str_repeat('a', 1001)])
            ->assertSessionHasErrors(['prompt' => 'Der Auftrag darf höchstens 1000 Zeichen lang sein.']);

        expect(Lesson::count())->toBe(0);
    });

    it('explains when the topic does not work', function () {
        $this->fake->push('analyse', analysis([
            'quelle' => ['lesbar' => false, 'problem' => 'Das ist kein Thema aus dem Schulstoff.'],
            'seite' => null,
        ]));

        uploadPrompt(['prompt' => 'Fussballresultate vom Wochenende']);

        $lesson = Lesson::sole();
        expect($lesson->status)->toBe(LessonStatus::Failed)
            ->and($lesson->error)->toBe('Das ist kein Thema aus dem Schulstoff.');

        $this->actingAs($this->user)->post(route('lessons.retry', $lesson));

        expect($lesson->fresh()->status)->toBe(LessonStatus::Review);
    });
});

describe('without a graphic', function () {
    it('skips the graphic when the parents switch it off', function () {
        $this->fake->push('analyse', [...analysis(), 'hero_plan' => null]);

        upload(['with_hero' => false]);

        $lesson = Lesson::sole();
        expect($lesson->with_hero)->toBeFalse()
            ->and($lesson->status)->toBe(LessonStatus::Review)
            ->and($lesson->hero)->toBeNull()
            ->and($lesson->hero_error)->toBeNull()
            ->and($this->fake->requestsFor('grafik'))->toBe([])
            ->and($this->fake->requestsFor('analyse')[0]->prompt)->toContain('Interaktive Grafik: nein');

        $this->actingAs($this->user)->get(route('lessons.show', $lesson))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lesson.withHero', false)
                ->where('lesson.hero', null)
                ->where('parent.canRegenerate.grafik', false)
            );
    });

    it('lets the ai leave out the graphic when nothing fits', function () {
        $this->fake->push('analyse', [...analysis(), 'hero_plan' => null]);

        upload();

        $lesson = Lesson::sole();
        expect($lesson->with_hero)->toBeTrue()
            ->and($lesson->hero)->toBeNull()
            ->and($lesson->hero_error)->toBeNull()
            ->and($this->fake->requestsFor('grafik'))->toBe([])
            ->and($this->fake->requestsFor('analyse')[0]->prompt)->toContain('Interaktive Grafik: ja')
            ->and($this->fake->requestsFor('module')[0]->prompt)->toContain('Diese Seite hat keine interaktive Grafik.');
    });

    it('creates a graphic by default', function () {
        upload();

        expect(Lesson::sole()->with_hero)->toBeTrue()
            ->and($this->fake->requestsFor('grafik'))->toHaveCount(1);
    });
});

describe('deleted lessons', function () {
    it('stops the generation when the lesson was deleted', function () {
        $lesson = Lesson::factory()->for($this->child)->create([
            'status' => LessonStatus::Generating,
            'step' => 'warteschlange',
            'prompt' => 'Fotosynthese',
        ]);
        $lesson->delete();

        (new AnalyzeLesson($lesson))->handle(app(LessonGenerator::class));
        (new GenerateLessonHero($lesson))->handle(app(LessonGenerator::class));
        (new FinishLesson($lesson))->handle(app(LessonGenerator::class));

        $fresh = Lesson::withTrashed()->find($lesson->id);
        expect($this->fake->requests)->toBe([])
            ->and(Generation::count())->toBe(0)
            ->and($fresh->status)->toBe(LessonStatus::Generating)
            ->and($fresh->step)->toBe('warteschlange');
    });

    it('does not call the api when the child is gone', function () {
        $lesson = Lesson::factory()->for($this->child)->create(['prompt' => 'Fotosynthese']);
        $lesson->setRelation('child', null);

        expect(fn () => app(LessonGenerator::class)->analyze($lesson))->toThrow(GenerationFailed::class);
        expect($this->fake->requests)->toBe([])
            ->and(Generation::count())->toBe(0);
    });
});
