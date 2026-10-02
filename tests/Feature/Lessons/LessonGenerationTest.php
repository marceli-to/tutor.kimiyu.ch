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
use App\Models\Child;
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
        'source' => 'fotos',
        'child_id' => test()->child->id,
        'subject' => 'Biologie',
        'level' => '2. Sek',
        'notes' => 'Prüfung am Freitag',
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
        ->and($lesson->generations()->where('status', 'ok')->count())->toBe(4);

    $this->actingAs($this->user)->get(route('lessons.show', $lesson))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lesson.status', 'review')
            ->where('lesson.content.meta.palette', 'gruen')
            ->has('lesson.hero.url')
            ->where('lesson.heroError', null)
        );
});

it('sends the photos, subject, level and notes, but never the child name', function () {
    upload();

    $request = $this->fake->requestsFor('analyse')[0];

    expect($request->images)->toHaveCount(1)
        ->and($request->prompt)->toContain('Fach: Biologie')
        ->toContain('Stufe: 2. Sek')
        ->toContain('Hinweise der Eltern: Prüfung am Freitag');

    foreach ($this->fake->requests as $sent) {
        expect($sent->system.$sent->prompt)->not->toContain('Mia');
    }
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

it('applies the corrections of the check and lists them for the parents', function () {
    $this->fake->push('pruefung', ['korrekturen' => [
        ['pfad' => '/module/quiz/0/tipp', 'wert' => 'Denk an die Zutaten, nicht an das Ergebnis.', 'bereich' => 'Quiz, Frage 1', 'aenderung' => 'Tipp präzisiert.'],
    ]]);

    upload();

    $lesson = Lesson::sole();
    expect($lesson->check_notes)->toBe([['bereich' => 'Quiz, Frage 1', 'aenderung' => 'Tipp präzisiert.']])
        ->and($lesson->content['module']['quiz'][0]['tipp'])->toBe('Denk an die Zutaten, nicht an das Ergebnis.');
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
    upload(['images' => [], 'subject' => ''])
        ->assertSessionHasErrors([
            'images' => 'Lade mindestens ein Foto hoch.',
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

describe('from a topic', function () {
    function uploadTopic(array $data = []): TestResponse
    {
        return upload(['source' => 'thema', 'topic' => 'Biodiversität', 'images' => [], ...$data]);
    }

    it('creates a lesson without photos', function () {
        uploadTopic()->assertRedirect();

        $lesson = Lesson::sole();
        $request = $this->fake->requestsFor('analyse')[0];

        expect($lesson->topic)->toBe('Biodiversität')
            ->and($lesson->status)->toBe(LessonStatus::Review)
            ->and($lesson->images()->count())->toBe(0)
            ->and($request->images)->toBe([])
            ->and($request->prompt)->toContain('zum Thema «Biodiversität»')
            ->toContain('Fach: Biologie')
            ->and($request->system)->toContain('Nur ein Thema, keine Fotos');

        $this->actingAs($this->user)->get(route('lessons.show', $lesson))
            ->assertInertia(fn (Assert $page) => $page->where('lesson.fromTopic', true));
    });

    it('marks photo lessons as not from a topic', function () {
        upload(['topic' => 'wird ignoriert']);

        expect(Lesson::sole()->topic)->toBeNull();

        $this->actingAs($this->user)->get(route('lessons.show', Lesson::sole()))
            ->assertInertia(fn (Assert $page) => $page->where('lesson.fromTopic', false));
    });

    it('requires a topic but no photos', function () {
        uploadTopic(['topic' => ''])
            ->assertSessionHasErrors(['topic' => 'Gib ein Thema ein.'])
            ->assertSessionDoesntHaveErrors('images');

        upload(['source' => 'irgendwas'])->assertSessionHasErrors('source');

        expect(Lesson::count())->toBe(0);
    });

    it('explains when the topic does not work', function () {
        $this->fake->push('analyse', analysis([
            'quelle' => ['lesbar' => false, 'problem' => 'Das ist kein Thema aus dem Schulstoff.'],
            'seite' => null,
        ]));

        uploadTopic(['topic' => 'Fussballresultate vom Wochenende']);

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
