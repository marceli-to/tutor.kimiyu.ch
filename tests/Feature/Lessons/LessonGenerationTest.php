<?php

use App\Enums\LessonStatus;
use App\Jobs\AnalyzeLesson;
use App\Jobs\CheckLesson;
use App\Jobs\FinishLesson;
use App\Jobs\GenerateLessonGraphic;
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
        'graphics_mode' => 'auto',
        'purpose' => 'neu',
        'scope' => 'normal',
        'modules' => ['quiz', 'sortieren', 'karten', 'lueckentext'],
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
        ->and($lesson->graphic(1)->graphic)->toBe(LessonFactory::fixture('fotosynthese.hero'))
        ->and($lesson->graphic(1)->plan['muster'])->toBe('regler')
        ->and($lesson->graphic(1)->error)->toBeNull()
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
            ->where('parent.graphics', [['nr' => 1, 'error' => null, 'canRegenerate' => true, 'hidden' => false]])
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

it('keeps parent-only information out of the graphic request', function () {
    $this->fake->push('analyse', analysis([
        'ergaenzungen' => ['Zellatmung ergänzt.'],
        'zusammenfassung' => 'Pflanzen machen Zucker. Zellatmung (ergänzt) setzt Energie frei.',
    ]));

    upload();

    $request = $this->fake->requestsFor('grafik')[0];
    expect($request->prompt)
        ->not->toContain('herkunft')
        ->not->toContain('Ergänzt (nicht auf den Fotos)')
        ->not->toContain('(ergänzt)')
        ->toContain('Zellatmung setzt Energie frei.')
        ->toContain('Auftrag der Eltern: Prüfung am Freitag')
        ->and($request->system)->toContain('Den Auftrag der Eltern nie wörtlich in die Grafik übernehmen.');
});

it('tells the system prompts to keep the origin marker away from the child', function () {
    upload();

    expect($this->fake->requestsFor('analyse')[0]->system)->toContain('«(ergänzt)» erscheint nie in Texten, die das Kind sieht')
        ->and($this->fake->requestsFor('module')[0]->system)->toContain('«(ergänzt)» erscheint nie in Texten, die das Kind sieht');
});

it('tells the repair to keep origin and ids', function () {
    $broken = LessonFactory::fixture('fotosynthese');
    $broken['module']['quiz'] = array_slice($broken['module']['quiz'], 0, 2);
    $this->fake->push('module', ['module' => $broken['module']]);

    upload();

    expect($this->fake->requestsFor('reparatur-module')[0]->system)
        ->toContain('`herkunft` und IDs bestehender Einträge übernimmst du unverändert');
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

it('names the photo order when there are several photos', function () {
    config()->set('lessons.delete_images', false);

    upload(['images' => [photo('a.jpg'), photo('b.jpg')]]);

    expect($this->fake->requestsFor('analyse')[0]->prompt)
        ->toContain('Die Fotos sind in der Reihenfolge der Seiten: Foto 1 ist die erste Seite.')
        ->and(Lesson::sole()->images()->reorder('id')->pluck('position')->all())->toBe([0, 1]);
});

it('does not name a photo order for a single photo', function () {
    upload();

    expect($this->fake->requestsFor('analyse')[0]->prompt)->not->toContain('Reihenfolge der Seiten');
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
    $broken['module']['quiz'] = array_slice($broken['module']['quiz'], 0, 2);

    $this->fake->push('module', ['module' => $broken['module']]);

    upload();

    $repair = $this->fake->requestsFor('reparatur-module');
    expect($repair)->toHaveCount(1)
        ->and($this->fake->requestsFor('reparatur-seite'))->toBe([])
        ->and($repair[0]->prompt)->toContain('Das Feld module.quiz muss mindestens 3 Elemente haben.')
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
        ->and($lesson->graphic(1)->graphic)->toBeNull()
        ->and($lesson->graphic(1)->error)->toContain('Inline-Event-Handler')
        ->and($this->fake->requestsFor('grafik-reparatur'))->toHaveCount(1);
});

it('repairs a broken hero once', function () {
    $this->fake->push('grafik', [...LessonFactory::fixture('fotosynthese.hero'), 'script' => 'fetch("x")']);

    upload();

    $lesson = Lesson::sole();
    expect($lesson->graphic(1)->graphic['script'])->toBe(LessonFactory::fixture('fotosynthese.hero')['script'])
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

    Bus::assertChained([AnalyzeLesson::class, CheckLesson::class, GenerateLessonGraphic::class, FinishLesson::class]);

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
        ]);
        $lesson->graphic(1)->update(['graphic' => null, 'error' => 'Die KI war nicht erreichbar.']);

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
            ->where('patterns.0', ['value' => 'regler', 'label' => 'Regler'])
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

        lessonFor($this->child, ['subject' => 'Biologie', 'purpose' => 'neu', 'scope' => 'normal', 'modules' => null, 'graphics_mode' => 'auto', 'created_at' => now()->subDays(3)]);
        lessonFor($this->child, ['subject' => ' biologie ', 'purpose' => 'pruefung', 'scope' => 'kurz', 'modules' => ['quiz', 'karten'], 'graphics_mode' => 'none', 'created_at' => now()->subDay()]);
        lessonFor($this->child, ['subject' => 'Mathematik', 'scope' => 'ausfuehrlich', 'created_at' => now()->subDays(2)]);
        lessonFor($leo, ['subject' => 'Biologie', 'purpose' => 'neu', 'scope' => 'ausfuehrlich', 'modules' => ['sortieren'], 'graphics_mode' => 'custom']);

        $this->actingAs($this->user)->get(route('lessons.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('lastSettings', 3)
                ->where("lastSettings.{$this->child->id}|biologie", [
                    'purpose' => 'pruefung',
                    'scope' => 'kurz',
                    'modules' => ['quiz', 'karten'],
                    'graphics_mode' => 'none',
                ])
                ->where("lastSettings.{$this->child->id}|mathematik.scope", 'ausfuehrlich')
                // Alte Lernseiten ohne Liste: alle Module waren erlaubt
                ->where("lastSettings.{$this->child->id}|mathematik.modules", ['quiz', 'sortieren', 'karten', 'lueckentext'])
                ->where("lastSettings.{$leo->id}|biologie.modules", ['sortieren'])
                ->where("lastSettings.{$leo->id}|biologie.graphics_mode", 'custom')
            );
    });

    it('ignores deleted lessons and lessons of other parents', function () {
        lessonFor($this->child, ['subject' => 'Biologie', 'scope' => 'kurz', 'created_at' => now()->subDays(2)]);
        lessonFor($this->child, ['subject' => 'Biologie', 'scope' => 'ausfuehrlich', 'created_at' => now()->subDay()])->delete();
        lessonFor(Child::factory()->create(), ['subject' => 'Chemie', 'scope' => 'ausfuehrlich']);

        $this->actingAs($this->user)->get(route('lessons.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('lastSettings', 1)
                ->where("lastSettings.{$this->child->id}|biologie.scope", 'kurz')
            );
    });

    it('passes the counts per scope for the labels', function () {
        $this->actingAs($this->user)->get(route('lessons.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('scopeInfo.kurz.quiz', 3)
                ->where('scopeInfo.kurz.abschnitte', '1–2')
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

    it('tells the later steps that there are no photos', function () {
        $this->fake->push('analyse', analysis(['ergaenzungen' => ['Alles ergänzt.']]));

        uploadPrompt();

        expect(Lesson::sole()->additions)->toBeNull()
            ->and($this->fake->requestsFor('module')[0]->prompt)
            ->toContain('Quelle: keine Fotos (Auftrag oder Thema)')
            ->not->toContain('Ergänzt (nicht auf den Fotos)');
    });

    it('does not mention missing photos for a photo lesson', function () {
        upload();

        expect($this->fake->requestsFor('module')[0]->prompt)->not->toContain('Quelle: keine Fotos');
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

it('names the prompt or the topic when no lesson could be made', function () {
    $this->fake->push('analyse', analysis(['quelle' => ['lesbar' => false, 'problem' => null], 'seite' => null]));
    uploadPrompt();
    expect(Lesson::sole()->error)->toBe('Zu diesem Auftrag konnte keine Lernseite erstellt werden.');

    $this->fake->push('analyse', analysis(['quelle' => ['lesbar' => false, 'problem' => null], 'seite' => null]));
    $legacy = Lesson::factory()->for($this->child)->create(['status' => LessonStatus::Failed, 'topic' => 'Fotosynthese']);
    $this->actingAs($this->user)->post(route('lessons.retry', $legacy));
    expect($legacy->fresh()->error)->toBe('Zu diesem Thema konnte keine Lernseite erstellt werden.');
});

describe('without a graphic', function () {
    it('skips the graphic when the parents switch it off', function () {
        $this->fake->push('analyse', [...analysis(), 'grafik_plaene' => []]);

        upload(['graphics_mode' => 'none']);

        $lesson = Lesson::sole();
        expect($lesson->graphics_mode)->toBe('none')
            ->and($lesson->status)->toBe(LessonStatus::Review)
            ->and($lesson->graphics)->toBeEmpty()
            ->and($this->fake->requestsFor('grafik'))->toBe([])
            ->and($this->fake->requestsFor('analyse')[0]->prompt)->toContain('Grafiken: keine (von den Eltern abgewählt)');

        $this->actingAs($this->user)->get(route('lessons.show', $lesson))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lesson.plannedGraphics', [])
                ->where('lesson.hero', null)
                ->where('parent.graphics', [])
            );
    });

    it('lets the ai leave out the graphic when nothing fits', function () {
        $this->fake->push('analyse', [...analysis(), 'grafik_plaene' => []]);

        upload();

        $lesson = Lesson::sole();
        expect($lesson->graphics_mode)->toBe('auto')
            ->and($lesson->graphic(1)?->graphic)->toBeNull()
            ->and($lesson->graphic(1)?->error)->toBeNull()
            ->and($this->fake->requestsFor('grafik'))->toBe([])
            ->and($this->fake->requestsFor('analyse')[0]->prompt)->toContain('Grafiken: höchstens eine, wenn ein Muster den Stoff sichtbar macht')
            ->and($this->fake->requestsFor('module')[0]->prompt)->toContain('Diese Seite hat keine interaktive Grafik.');
    });

    it('creates a graphic by default', function () {
        upload();

        expect(Lesson::sole()->graphics_mode)->toBe('auto')
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
        (new GenerateLessonGraphic($lesson, 1))->handle(app(LessonGenerator::class));
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
        upload(['with_hero' => false, 'graphics_mode' => null])->assertSessionHasErrors('graphics_mode');
        upload(['graphics_mode' => 'alle'])->assertSessionHasErrors('graphics_mode');

        expect(Lesson::count())->toBe(0);
    });

    it('ignores wishes unless the parents describe the graphics', function () {
        upload(['graphics_mode' => 'auto', 'graphics' => [['beschreibung' => 'Ein Zeitstrahl']]])->assertSessionHasNoErrors();

        expect(Lesson::sole()->graphics()->count())->toBe(0);
    });

    it('stores the wishes in order', function () {
        upload(['graphics_mode' => 'custom', 'graphics' => [
            ['beschreibung' => 'Ein Blatt mit Reglern für Licht und Wasser', 'muster' => 'regler'],
            ['beschreibung' => 'Die Schritte der Fotosynthese', 'muster' => null],
            ['beschreibung' => '  Zellatmung im Vergleich  '],
        ]])->assertSessionHasNoErrors();

        $lesson = Lesson::sole();
        expect($lesson->graphics_mode)->toBe('custom')
            ->and($lesson->graphics->map->only(['position', 'request', 'pattern'])->all())->toBe([
                ['position' => 1, 'request' => 'Ein Blatt mit Reglern für Licht und Wasser', 'pattern' => 'regler'],
                ['position' => 2, 'request' => 'Die Schritte der Fotosynthese', 'pattern' => null],
                ['position' => 3, 'request' => 'Zellatmung im Vergleich', 'pattern' => null],
            ]);
    });

    it('validates the wishes', function () {
        upload(['graphics_mode' => 'custom'])
            ->assertSessionHasErrors(['graphics' => 'Beschreib mindestens eine Grafik.']);

        upload(['graphics_mode' => 'custom', 'graphics' => []])
            ->assertSessionHasErrors(['graphics' => 'Beschreib mindestens eine Grafik.']);

        upload(['graphics_mode' => 'custom', 'graphics' => array_fill(0, 4, ['beschreibung' => 'Eine Grafik'])])
            ->assertSessionHasErrors(['graphics' => 'Höchstens 3 Grafiken.']);

        upload(['graphics_mode' => 'custom', 'graphics' => [['beschreibung' => '']]])
            ->assertSessionHasErrors(['graphics.0.beschreibung' => 'Beschreib, was die Grafik zeigen soll.']);

        upload(['graphics_mode' => 'custom', 'graphics' => [['beschreibung' => str_repeat('a', 501)]]])
            ->assertSessionHasErrors('graphics.0.beschreibung');

        upload(['graphics_mode' => 'custom', 'graphics' => [['beschreibung' => 'Eine Grafik', 'muster' => 'karussell']]])
            ->assertSessionHasErrors('graphics.0.muster');

        upload(['graphics_mode' => 'alle'])->assertSessionHasErrors('graphics_mode');

        expect(Lesson::count())->toBe(0);
    });
});

describe('graphic plans', function () {
    function plan(string $muster, string $idee): array
    {
        return ['muster' => $muster, 'idee' => $idee];
    }

    function wishes(): array
    {
        return ['graphics_mode' => 'custom', 'graphics' => [
            ['beschreibung' => 'Ein Blatt mit Reglern', 'muster' => 'regler'],
            ['beschreibung' => 'Ein Vulkan'],
            ['beschreibung' => 'Die Schritte im Chloroplasten', 'muster' => 'schritte'],
        ]];
    }

    it('tells the analysis what the parents chose', function (array $data, string $line) {
        upload($data);

        expect($this->fake->requestsFor('analyse')[0]->prompt)->toContain($line)
            ->not->toContain('Interaktive Grafik');
    })->with([
        'none' => [['graphics_mode' => 'none'], 'Grafiken: keine (von den Eltern abgewählt)'],
        'auto' => [['graphics_mode' => 'auto'], 'Grafiken: höchstens eine, wenn ein Muster den Stoff sichtbar macht'],
        'custom' => [wishes(), "Grafiken nach Wunsch der Eltern:\nGrafik 1: Ein Blatt mit Reglern (Muster: regler – Regler)\nGrafik 2: Ein Vulkan\nGrafik 3: Die Schritte im Chloroplasten (Muster: schritte – Schritt für Schritt)"],
    ]);

    it('stores a plan or a hint for every wish', function () {
        $page = Prompts::page(LessonFactory::fixture('fotosynthese'));
        $page['abschnitte'][1]['bloecke'][] = ['typ' => 'grafik', 'nr' => 3, 'herkunft' => 'foto'];

        $this->fake->push('analyse', [...analysis(), 'seite' => $page, 'grafik_plaene' => [
            ['nr' => 1, 'plan' => plan('regler', 'Drei Regler'), 'hinweis' => null],
            ['nr' => 2, 'plan' => null, 'hinweis' => 'Ein Vulkan kommt auf den Fotos nicht vor.'],
            ['nr' => 3, 'plan' => plan('schritte', 'Vier Schritte'), 'hinweis' => null],
            ['nr' => 4, 'plan' => plan('rechner', 'Zu viel'), 'hinweis' => null],
        ]]);

        upload(wishes());

        $lesson = Lesson::sole();
        expect($lesson->graphics->map->only(['position', 'request', 'plan', 'error'])->all())->toBe([
            ['position' => 1, 'request' => 'Ein Blatt mit Reglern', 'plan' => plan('regler', 'Drei Regler'), 'error' => null],
            ['position' => 2, 'request' => 'Ein Vulkan', 'plan' => null, 'error' => 'Ein Vulkan kommt auf den Fotos nicht vor.'],
            ['position' => 3, 'request' => 'Die Schritte im Chloroplasten', 'plan' => plan('schritte', 'Vier Schritte'), 'error' => null],
        ])
            ->and($lesson->content['abschnitte'][1]['bloecke'][1])->toBe(['typ' => 'grafik', 'nr' => 3, 'herkunft' => 'foto']);

        expect($this->fake->requestsFor('module')[0]->prompt)
            ->toContain("Grafik 1 (oben): Muster regler\nDrei Regler")
            ->toContain("Grafik 3 (im Abschnitt «Was man wissen muss»): Muster schritte\nVier Schritte")
            ->not->toContain('Grafik 2')
            ->not->toContain('keine interaktive Grafik');
    });

    it('notes a wish the analysis forgot', function () {
        $this->fake->push('analyse', [...analysis(), 'grafik_plaene' => [
            ['nr' => 1, 'plan' => plan('regler', 'Drei Regler'), 'hinweis' => null],
        ]]);

        upload(wishes());

        expect(Lesson::sole()->graphics->pluck('error', 'position')->all())->toBe([
            1 => null,
            2 => 'Für diese Grafik hat die KI keinen Plan erstellt.',
            3 => 'Für diese Grafik hat die KI keinen Plan erstellt.',
        ]);
    });

    it('only plans graphic 1 when the ai decides', function () {
        $this->fake->push('analyse', [...analysis(), 'grafik_plaene' => [
            ['nr' => 1, 'plan' => plan('regler', 'Drei Regler'), 'hinweis' => null],
            ['nr' => 2, 'plan' => plan('schritte', 'Vier Schritte'), 'hinweis' => null],
        ]]);

        upload(['graphics_mode' => 'auto']);

        $lesson = Lesson::sole();
        expect($lesson->graphics->pluck('plan', 'position')->all())->toBe([1 => plan('regler', 'Drei Regler')])
            ->and($this->fake->requestsFor('module')[0]->prompt)->toContain("Grafik 1 (oben): Muster regler\nDrei Regler");
    });

    it('stores no plan when the parents switched the graphics off', function () {
        $this->fake->push('analyse', [...analysis(), 'grafik_plaene' => [
            ['nr' => 1, 'plan' => plan('regler', 'Drei Regler'), 'hinweis' => null],
        ]]);

        upload(['graphics_mode' => 'none']);

        expect(Lesson::sole()->graphics()->count())->toBe(0)
            ->and($this->fake->requestsFor('module')[0]->prompt)->toContain('Diese Seite hat keine interaktive Grafik.');
    });
});

describe('graphic generation', function () {
    function threePlans(): array
    {
        $page = Prompts::page(LessonFactory::fixture('fotosynthese'));
        $page['abschnitte'][1]['bloecke'][] = ['typ' => 'grafik', 'nr' => 2, 'herkunft' => 'foto'];

        return [...analysis(), 'seite' => $page, 'grafik_plaene' => [
            ['nr' => 1, 'plan' => plan('regler', 'Drei Regler'), 'hinweis' => null],
            ['nr' => 2, 'plan' => plan('schritte', 'Vier Schritte'), 'hinweis' => null],
            ['nr' => 3, 'plan' => plan('rechner', 'Ein Rechner'), 'hinweis' => null],
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
        $broken = [...LessonFactory::fixture('fotosynthese.hero'), 'markup' => '<button onclick="x()">Los</button>'];
        $this->fake->push('analyse', threePlans());
        $this->fake->push('grafik', function (ModelRequest $request) {
            expect(Lesson::sole()->step)->toBe('grafik-1');

            return LessonFactory::fixture('fotosynthese.hero');
        });
        $this->fake->push('grafik', function (ModelRequest $request) use ($broken) {
            expect(Lesson::sole()->step)->toBe('grafik-2');

            return $broken;
        });
        $this->fake->push('grafik-reparatur', $broken);
        $this->fake->push('grafik', LessonFactory::fixture('oekosystem.hero'));

        upload(wishes());

        $lesson = Lesson::sole();
        $requests = $this->fake->requestsFor('grafik');

        expect($lesson->status)->toBe(LessonStatus::Review)
            ->and($requests)->toHaveCount(3)
            ->and($lesson->graphic(1)->graphic)->toBe(LessonFactory::fixture('fotosynthese.hero'))
            ->and($lesson->graphic(1)->error)->toBeNull()
            ->and($lesson->graphic(2)->graphic)->toBeNull()
            ->and($lesson->graphic(2)->error)->toContain('Inline-Event-Handler')
            ->and($lesson->graphic(3)->graphic)->toBe(LessonFactory::fixture('oekosystem.hero'))
            ->and($lesson->graphic(3)->error)->toBeNull();

        expect($requests[0]->prompt)->toContain("Muster: regler\nDrei Regler")->not->toContain('nicht oben auf der Seite')
            ->and($requests[1]->prompt)->toContain("Muster: schritte\nVier Schritte")
            ->toContain('Diese Grafik steht im Abschnitt «Was man wissen muss» neben dem Text, nicht oben auf der Seite.')
            ->and($requests[2]->prompt)->toContain("Muster: rechner\nEin Rechner")
            ->toContain('Diese Grafik steht weiter unten auf der Seite neben dem Text, nicht oben auf der Seite.');

        foreach ($requests as $request) {
            expect($request->prompt)->not->toContain('herkunft')
                ->not->toContain('Ein Blatt mit Reglern');
        }
    });

    it('skips a graphic whose wish did not fit', function () {
        $this->fake->push('analyse', [...threePlans(), 'grafik_plaene' => [
            ['nr' => 1, 'plan' => plan('regler', 'Drei Regler'), 'hinweis' => null],
            ['nr' => 2, 'plan' => null, 'hinweis' => 'Passt nicht zu den Fotos.'],
        ]]);

        upload(wishes());

        $lesson = Lesson::sole();
        expect($this->fake->requestsFor('grafik'))->toHaveCount(1)
            ->and($lesson->graphic(2)->error)->toBe('Passt nicht zu den Fotos.')
            ->and($lesson->graphic(3)->error)->toBe('Für diese Grafik hat die KI keinen Plan erstellt.')
            ->and($lesson->status)->toBe(LessonStatus::Review);
    });

    it('only builds the missing graphics on a retry', function () {
        $lesson = Lesson::factory()->for($this->child)->fromFixture()->create([
            'status' => LessonStatus::Failed,
            'graphics_mode' => 'custom',
        ]);
        $lesson->graphics()->create(['position' => 2, 'plan' => plan('schritte', 'Vier Schritte'), 'error' => 'Die KI war nicht erreichbar.']);
        $lesson->graphics()->create(['position' => 3, 'request' => 'Ein Vulkan', 'error' => 'Passt nicht zu den Fotos.']);

        $this->actingAs($this->user)->post(route('lessons.retry', $lesson));

        $lesson->refresh();
        expect(collect($this->fake->requests)->pluck('step')->all())->toBe(['grafik'])
            ->and($this->fake->requests[0]->prompt)->toContain("Muster: schritte\nVier Schritte")
            ->and($lesson->graphic(2)->graphic)->toBe(LessonFactory::fixture('fotosynthese.hero'))
            ->and($lesson->graphic(2)->error)->toBeNull()
            ->and($lesson->graphic(3)->error)->toBe('Passt nicht zu den Fotos.')
            ->and($lesson->status)->toBe(LessonStatus::Review);
    });

    it('uses the model settings of the graphic step', function () {
        config()->set('lessons.models.grafik', ['model' => 'claude-test-grafik', 'effort' => 'medium']);
        $this->fake->push('analyse', threePlans());

        upload(wishes());

        expect(collect($this->fake->requestsFor('grafik'))->map->model()->unique()->all())->toBe(['claude-test-grafik'])
            ->and($this->fake->requestsFor('grafik'))->toHaveCount(3);
    });
});

describe('purpose, scope and modules', function () {
    beforeEach(fn () => Bus::fake());

    it('stores purpose, scope and allowed modules', function () {
        upload(['purpose' => 'pruefung', 'scope' => 'kurz', 'modules' => ['karten', 'quiz']])->assertSessionHasNoErrors();

        $lesson = Lesson::sole();
        expect($lesson->purpose)->toBe('pruefung')
            ->and($lesson->scope)->toBe('kurz')
            ->and($lesson->modules)->toBe(['karten', 'quiz']);
    });

    it('has defaults for lessons without settings', function () {
        $lesson = Lesson::factory()->create()->fresh();

        expect($lesson->purpose)->toBe('neu')
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
        upload(['purpose' => 'pruefung', 'scope' => 'kurz', 'modules' => ['karten', 'quiz']]);

        $analysis = $this->fake->requestsFor('analyse')[0]->prompt;
        $modules = $this->fake->requestsFor('module')[0]->prompt;

        expect($analysis)->toContain('Zweck: Prüfungsvorbereitung')
            ->toContain('Umfang: kurz (1–2 Abschnitte)')
            ->and($modules)->toContain('Zweck: Prüfungsvorbereitung')
            ->toContain('Umfang: kurz (1–2 Abschnitte)')
            ->toContain('Erlaubte Lernmodule: Quiz (genau 3 Fragen), Karteikarten (4–6 Karten)')
            ->and($this->fake->requestsFor('pruefung')[0]->prompt)->toContain('Erlaubte Lernmodule: Quiz (genau 3 Fragen), Karteikarten (4–6 Karten)');
    });

    it('explains purpose, scope and modules in the system prompts', function () {
        upload();

        expect($this->fake->requestsFor('analyse')[0]->system)->toContain('«Prüfungsvorbereitung»')
            ->toContain('«Das Wichtigste für die Prüfung»')
            ->toContain('Die Zeile «Umfang»')
            ->and($this->fake->requestsFor('module')[0]->system)->toContain('Die Zeile «Erlaubte Lernmodule»')
            ->not->toContain('genau 5 Fragen');
    });

    it('names each purpose and scope', function (array $data, array $lines) {
        upload($data);

        expect($this->fake->requestsFor('module')[0]->prompt)->toContain(...$lines);
    })->with([
        'defaults' => [[], ['Zweck: Neuer Stoff', 'Umfang: normal (1–3 Abschnitte)', 'Erlaubte Lernmodule: Quiz (genau 5 Fragen), Sortierspiel (8–12 Begriffe), Karteikarten (5–10 Karten), Lückentext (4–8 Lücken)']],
        'detailed' => [['scope' => 'ausfuehrlich', 'modules' => ['lueckentext', 'sortieren']], ['Umfang: ausführlich (2–4 Abschnitte)', 'Erlaubte Lernmodule: Sortierspiel (10–16 Begriffe), Lückentext (6–10 Lücken)']],
    ]);

    it('keeps these lines away from the graphic', function () {
        upload(['purpose' => 'pruefung', 'scope' => 'kurz']);

        expect($this->fake->requestsFor('grafik')[0]->prompt)->not->toContain('Zweck:')
            ->not->toContain('Umfang:')
            ->not->toContain('Erlaubte Lernmodule');
    });

    it('drops modules the parents did not allow', function () {
        upload(['modules' => ['karten']]);

        $lesson = Lesson::sole();
        expect($lesson->status)->toBe(LessonStatus::Review)
            ->and($lesson->content['module']['quiz'])->toBeNull()
            ->and($lesson->content['module']['lueckentext'])->toBeNull()
            ->and($lesson->content['module']['karten'])->toBe(LessonFactory::fixture('fotosynthese')['module']['karten'])
            ->and($this->fake->requestsFor('reparatur-module'))->toBe([]);
    });

    it('repairs a page left without any module', function () {
        // Die Fotosynthese hat kein Sortierspiel: ohne die anderen Module bleibt nichts übrig
        upload(['modules' => ['sortieren']]);

        expect($this->fake->requestsFor('reparatur-module')[0]->prompt)->toContain('Die Seite braucht mindestens ein Lernmodul.')
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
