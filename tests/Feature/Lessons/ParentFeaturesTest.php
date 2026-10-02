<?php

use App\Enums\LessonStatus;
use App\Lessons\Ai\FakeLanguageModel;
use App\Lessons\Ai\ModelException;
use App\Models\Attempt;
use App\Models\Child;
use App\Models\Generation;
use App\Models\Lesson;
use App\Models\User;
use Database\Factories\LessonFactory;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('lesson-images');
    $this->fake = FakeLanguageModel::install();
    $this->user = User::factory()->create();
    $this->child = Child::factory()->for($this->user)->create(['name' => 'Mia']);
    $this->lesson = Lesson::factory()->for($this->child)->fromFixture('oekosystem')->create([
        'status' => LessonStatus::Review,
        'published_at' => null,
        'hero_plan' => ['muster' => 'ansichten', 'idee' => 'Weiher'],
    ]);
});

describe('children', function () {
    it('lists the parent’s children with their share link', function () {
        Child::factory()->create(['name' => 'Fremd']);

        $this->actingAs($this->user)->get(route('children.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('children/Index')
                ->has('children', 1)
                ->where('children.0.name', 'Mia')
                ->where('children.0.lessons', 1)
                ->where('children.0.published', 0)
                ->where('children.0.shareUrl', route('shared.index', $this->child->share_token))
            );
    });

    it('adds, renames and validates children', function () {
        $this->actingAs($this->user)->post(route('children.store'), ['name' => '  Noah ', 'level' => '1. Sek'])
            ->assertSessionHasNoErrors();

        expect($this->user->children()->where('name', 'Noah')->value('level'))->toBe('1. Sek');

        $this->actingAs($this->user)->patch(route('children.update', $this->child), ['name' => 'Mia Sophie', 'level' => '3. Sek']);
        expect($this->child->fresh()->name)->toBe('Mia Sophie');

        $this->actingAs($this->user)->post(route('children.store'), ['name' => ''])
            ->assertSessionHasErrors(['name' => 'Gib einen Namen ein.']);
    });

    it('deletes a child with all lessons and photos', function () {
        Storage::disk('lesson-images')->put("{$this->lesson->id}/a.jpg", 'x');
        $this->lesson->images()->create(['path' => "{$this->lesson->id}/a.jpg", 'mime_type' => 'image/jpeg', 'size' => 1]);

        $this->actingAs($this->user)->delete(route('children.destroy', $this->child))->assertRedirect();

        expect(Child::count())->toBe(0)
            ->and(Lesson::withTrashed()->count())->toBe(0)
            ->and(Storage::disk('lesson-images')->allFiles())->toBe([]);
    });

    it('keeps the costs when a child is deleted', function () {
        $this->lesson->generations()->createMany([
            ['user_id' => $this->user->id, 'step' => 'analyse', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'cost_usd' => 0.4],
            ['user_id' => $this->user->id, 'step' => 'grafik', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'cost_usd' => 0.2],
        ]);

        $this->actingAs($this->user)->delete(route('children.destroy', $this->child))->assertRedirect();

        expect(Generation::count())->toBe(2)
            ->and(Generation::whereNotNull('lesson_id')->count())->toBe(0)
            ->and(Generation::where('user_id', $this->user->id)->count())->toBe(2);

        $this->actingAs($this->user)->get(route('costs'))
            ->assertInertia(fn (Assert $page) => $page->where('total', 0.6));
    });

    it('does not count deleted lessons per child', function () {
        Lesson::factory()->for($this->child)->fromFixture('fotosynthese')->create(['status' => LessonStatus::Published])->delete();

        $this->actingAs($this->user)->get(route('children.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('children.0.lessons', 1)
                ->where('children.0.published', 0)
            );
    });

    it('creates a new link and the old one stops working', function () {
        $old = $this->child->share_token;

        $this->actingAs($this->user)->post(route('children.renew-link', $this->child));

        expect($this->child->fresh()->share_token)->not->toBe($old);
        $this->get(route('shared.index', $old))->assertNotFound();
    });

    it('does not let other parents change a child', function () {
        $other = User::factory()->create();

        $this->actingAs($other)->patch(route('children.update', $this->child), ['name' => 'X'])->assertForbidden();
        $this->actingAs($other)->delete(route('children.destroy', $this->child))->assertForbidden();
        $this->actingAs($other)->post(route('children.renew-link', $this->child))->assertForbidden();
    });
});

describe('library', function () {
    it('groups lessons by subject, newest first', function () {
        Lesson::factory()->for($this->child)->fromFixture('fotosynthese')->create(['subject' => 'Biologie', 'created_at' => now()->addDay()]);
        Lesson::factory()->for($this->child)->create(['subject' => 'Deutsch', 'title' => null, 'topic' => 'Kommaregeln']);

        $this->actingAs($this->user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('children', 1)
                ->where('children.0.subjects.0.name', 'Biologie')
                ->where('children.0.subjects.0.lessons.0.title', 'Wie macht ein Blatt Zucker aus Licht?')
                ->where('children.0.subjects.0.lessons.1.title', 'Biotop + Biozönose = Ökosystem')
                ->where('children.0.subjects.1.name', 'Deutsch')
                ->where('children.0.subjects.1.lessons.0.title', 'Kommaregeln')
                ->where('children.0.subjects.1.lessons.0.statusLabel', 'Entwurf')
            );
    });
});

describe('publishing and sharing', function () {
    it('publishes a lesson so the child sees it', function () {
        $this->get(route('shared.index', $this->child->share_token))
            ->assertInertia(fn (Assert $page) => $page->where('childName', 'Mia')->has('subjects', 0));

        $this->actingAs($this->user)->post(route('lessons.publish', $this->lesson))->assertRedirect();

        expect($this->lesson->fresh()->status)->toBe(LessonStatus::Published)
            ->and($this->lesson->fresh()->published_at)->not->toBeNull();

        auth()->logout();

        $this->get(route('shared.index', $this->child->share_token))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page
                ->component('shared/Index')
                ->where('subjects.0.lessons.0.title', 'Biotop + Biozönose = Ökosystem')
            );

        $this->get(route('shared.show', [$this->child->share_token, $this->lesson]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('shared/Show')
                ->where('lesson.content.meta.titel', 'Biotop + Biozönose = Ökosystem')
                ->has('lesson.hero.url')
                ->missing('lesson.checkNotes')
                ->missing('lesson.error')
            );
    });

    it('hides lessons that are not published', function () {
        $this->get(route('shared.show', [$this->child->share_token, $this->lesson]))->assertNotFound();
    });

    it('does not show another child’s lesson with a valid link', function () {
        $sibling = Child::factory()->for($this->user)->create();
        $this->lesson->update(['status' => LessonStatus::Published, 'published_at' => now()]);

        $this->get(route('shared.show', [$sibling->share_token, $this->lesson]))->assertNotFound();
        $this->get(route('shared.index', 'erfundener-link'))->assertNotFound();
    });

    it('withdraws a published lesson', function () {
        $this->lesson->update(['status' => LessonStatus::Published, 'published_at' => now()]);

        $this->actingAs($this->user)->post(route('lessons.unpublish', $this->lesson));

        expect($this->lesson->fresh()->status)->toBe(LessonStatus::Review);
        $this->get(route('shared.show', [$this->child->share_token, $this->lesson]))->assertNotFound();
    });

    it('only publishes lessons that are ready', function () {
        $this->lesson->update(['status' => LessonStatus::Generating]);

        $this->actingAs($this->user)->post(route('lessons.publish', $this->lesson))->assertStatus(422);
    });

    it('gives the parent the share link and actions', function () {
        $this->actingAs($this->user)->get(route('lessons.show', $this->lesson))
            ->assertInertia(fn (Assert $page) => $page
                ->where('parent.childName', 'Mia')
                ->where('parent.canPublish', true)
                ->where('parent.shareUrl', null)
                ->where('parent.canRegenerate', ['quiz' => true, 'grafik' => true])
            );

        $this->lesson->update(['status' => LessonStatus::Published, 'published_at' => now()]);

        $this->actingAs($this->user)->get(route('lessons.show', $this->lesson))
            ->assertInertia(fn (Assert $page) => $page
                ->where('parent.canPublish', false)
                ->where('parent.shareUrl', route('shared.show', [$this->child->share_token, $this->lesson]))
            );
    });
});

describe('editing', function () {
    it('shows the content and the cloze text as markup', function () {
        $lesson = Lesson::factory()->for($this->child)->fromFixture('fotosynthese')->create();

        $this->actingAs($this->user)->get(route('lessons.edit', $lesson))
            ->assertInertia(fn (Assert $page) => $page
                ->component('lessons/Edit')
                ->where('lesson.content.meta.titel', 'Wie macht ein Blatt Zucker aus Licht?')
                ->where('clozeMarkup', 'Die Pflanze nimmt [Kohlenstoffdioxid|CO₂|CO2] aus der Luft und [Wasser] aus dem Boden auf. Mit der Energie des [Lichts|Sonnenlichts|Licht] stellt sie daraus [Traubenzucker|Glucose|Glukose] her. Dabei entsteht [Sauerstoff|O₂|O2].')
                ->has('palettes', 7)
            );
    });

    it('saves corrected texts and answers', function () {
        $content = $this->lesson->content;
        $content['meta']['titel'] = 'Was ist ein Ökosystem?';
        $content['module']['quiz'][0]['loesung'] = 3;
        $content['module']['sortieren']['begriffe'][0]['kategorie'] = 'cat2';

        $this->actingAs($this->user)->put(route('lessons.update', $this->lesson), ['content' => $content])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $lesson = $this->lesson->fresh();
        expect($lesson->title)->toBe('Was ist ein Ökosystem?')
            ->and($lesson->content['module']['quiz'][0]['loesung'])->toBe(3)
            ->and($lesson->content['module']['sortieren']['begriffe'][0]['kategorie'])->toBe('cat2');
    });

    it('turns the cloze markup into gaps', function () {
        $lesson = Lesson::factory()->for($this->child)->fromFixture('fotosynthese')->create();

        $this->actingAs($this->user)->put(route('lessons.update', $lesson), [
            'content' => $lesson->content,
            'clozeMarkup' => 'Blätter sind [grün|gruen] wegen [Chlorophyll].',
        ])->assertSessionHasNoErrors();

        expect($lesson->fresh()->content['module']['lueckentext']['segmente'])->toBe([
            ['text' => 'Blätter sind '],
            ['id' => 'g1', 'loesungen' => ['grün', 'gruen']],
            ['text' => ' wegen '],
            ['id' => 'g2', 'loesungen' => ['Chlorophyll']],
            ['text' => '.'],
        ]);
    });

    it('explains what is wrong', function () {
        $content = $this->lesson->content;
        $content['meta']['titel'] = '';
        $content['module']['quiz'][1]['optionen'][0] = 'Straße';

        $this->actingAs($this->user)->put(route('lessons.update', $this->lesson), ['content' => $content])
            ->assertSessionHasErrors(['content.meta.titel']);

        $content['meta']['titel'] = 'Titel';
        $this->actingAs($this->user)->put(route('lessons.update', $this->lesson), ['content' => $content])
            ->assertSessionHasErrors(['content.module.quiz.1.optionen.0' => 'Im Feld module.quiz.1.optionen.0 steht ein «ß». In der Schweiz schreibt man «ss».']);

        expect($this->lesson->fresh()->title)->toBe('Biotop + Biozönose = Ökosystem');
    });

    it('rejects broken cloze markup', function () {
        $lesson = Lesson::factory()->for($this->child)->fromFixture('fotosynthese')->create();

        $this->actingAs($this->user)->put(route('lessons.update', $lesson), [
            'content' => $lesson->content,
            'clozeMarkup' => 'Die Pflanze nimmt [CO2 auf.',
        ])->assertSessionHasErrors(['clozeMarkup' => 'Eine eckige Klammer ist nicht geschlossen.']);
    });

    it('is only possible for the parent and for finished lessons', function () {
        $this->actingAs(User::factory()->create())->get(route('lessons.edit', $this->lesson))->assertForbidden();

        $this->lesson->update(['status' => LessonStatus::Generating]);
        $this->actingAs($this->user)->get(route('lessons.edit', $this->lesson))->assertNotFound();
    });
});

describe('regenerating', function () {
    it('replaces the quiz and asks the parent to check again', function () {
        $this->lesson->update(['status' => LessonStatus::Published, 'published_at' => now()]);
        $newQuiz = LessonFactory::fixture('fotosynthese')['module']['quiz'];
        $this->fake->push('neu-quiz', ['quiz' => $newQuiz]);

        $this->actingAs($this->user)->post(route('lessons.regenerate', [$this->lesson, 'quiz']))
            ->assertRedirect(route('lessons.show', $this->lesson));

        $lesson = $this->lesson->fresh();
        expect($lesson->content['module']['quiz'])->toBe($newQuiz)
            ->and($lesson->content['module']['sortieren'])->toBe(LessonFactory::fixture('oekosystem')['module']['sortieren'])
            ->and($lesson->status)->toBe(LessonStatus::Review)
            ->and($lesson->published_at)->toBeNull()
            ->and($this->fake->requestsFor('neu-quiz')[0]->prompt)->toContain('Bisheriges Quiz:');
    });

    it('keeps the old quiz and the published state when the new one is broken', function () {
        $this->lesson->update(['status' => LessonStatus::Published, 'published_at' => now()]);
        $this->fake->push('neu-quiz', ['quiz' => []]);

        $this->actingAs($this->user)->post(route('lessons.regenerate', [$this->lesson, 'quiz']));

        $lesson = $this->lesson->fresh();
        expect($lesson->content)->toBe(LessonFactory::fixture('oekosystem'))
            ->and($lesson->status)->toBe(LessonStatus::Published)
            ->and($lesson->published_at)->not->toBeNull()
            ->and($lesson->error)->toBe('Das neue Quiz war fehlerhaft. Das bisherige Quiz bleibt.');
    });

    it('draws a new graphic', function () {
        $this->actingAs($this->user)->post(route('lessons.regenerate', [$this->lesson, 'grafik']));

        expect($this->lesson->fresh()->hero['muster'])->toBe('regler')
            ->and($this->lesson->fresh()->status)->toBe(LessonStatus::Review);
    });

    it('keeps the old graphic when the new one fails', function () {
        $this->fake->push('grafik', new ModelException('Die KI war nicht erreichbar.'));

        $this->actingAs($this->user)->post(route('lessons.regenerate', [$this->lesson, 'grafik']));

        $lesson = $this->lesson->fresh();
        expect($lesson->hero)->toBe(LessonFactory::fixture('oekosystem.hero'))
            ->and($lesson->hero_error)->toBe('Die KI war nicht erreichbar. Die bisherige Grafik bleibt.');
    });

    it('is not possible while the lesson is being generated', function () {
        $this->lesson->update(['status' => LessonStatus::Generating]);

        $this->actingAs($this->user)->post(route('lessons.regenerate', [$this->lesson, 'quiz']))->assertStatus(422);
        $this->actingAs($this->user)->post(route('lessons.regenerate', [$this->lesson, 'text']))->assertNotFound();
    });
});

it('deletes a lesson', function () {
    $this->lesson->update(['status' => LessonStatus::Published, 'published_at' => now()]);
    Storage::disk('lesson-images')->put("{$this->lesson->id}/a.jpg", 'x');
    $this->lesson->images()->create(['path' => "{$this->lesson->id}/a.jpg", 'mime_type' => 'image/jpeg', 'size' => 1]);
    $this->lesson->generations()->create(['user_id' => $this->user->id, 'step' => 'analyse', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'cost_usd' => 0.4]);

    $this->actingAs($this->user)->delete(route('lessons.destroy', $this->lesson))
        ->assertRedirect(route('dashboard'));

    // Weich gelöscht: die Kosten bleiben, die Fotos sind weg
    expect(Lesson::count())->toBe(0)
        ->and($this->lesson->fresh()->trashed())->toBeTrue()
        ->and($this->lesson->images()->count())->toBe(0)
        ->and(Storage::disk('lesson-images')->allFiles())->toBe([])
        ->and(Generation::where('lesson_id', $this->lesson->id)->count())->toBe(1);

    $this->actingAs($this->user)->get(route('lessons.show', $this->lesson))->assertNotFound();
    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->has('children.0.subjects', 0));
    $this->get(route('shared.index', $this->child->share_token))
        ->assertInertia(fn (Assert $page) => $page->has('subjects', 0));
    $this->get(route('shared.show', [$this->child->share_token, $this->lesson]))->assertNotFound();

    $other = Lesson::factory()->fromFixture()->create();
    $this->actingAs($this->user)->delete(route('lessons.destroy', $other))->assertForbidden();
});

it('removes the content and the progress of a deleted lesson but keeps its costs', function () {
    $this->lesson->update([
        'status' => LessonStatus::Published,
        'published_at' => now(),
        'prompt' => 'Prüfung am Freitag',
        'notes' => 'Alte Hinweise',
        'topic' => 'Ökosystem',
        'source_summary' => 'Zusammenfassung',
        'additions' => ['Ergänzt.'],
        'hero' => LessonFactory::fixture('fotosynthese.hero'),
        'hero_error' => 'Fehler',
        'check_notes' => [['bereich' => 'Quiz', 'aenderung' => 'Korrigiert']],
        'error' => 'Alter Fehler',
        'step' => 'module',
    ]);
    $this->lesson->generations()->create(['user_id' => $this->user->id, 'step' => 'analyse', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'cost_usd' => 0.4]);
    $other = Lesson::factory()->for($this->child)->fromFixture('fotosynthese')->create(['status' => LessonStatus::Published, 'published_at' => now()]);
    $old = Attempt::forceCreate(['child_id' => $this->child->id, 'lesson_id' => $other->id, 'module' => 'quiz', 'item_id' => 'q1', 'correct' => true, 'created_at' => now()->subDays(3)]);
    Attempt::forceCreate(['child_id' => $this->child->id, 'lesson_id' => $this->lesson->id, 'module' => 'quiz', 'item_id' => 'q1', 'correct' => true, 'created_at' => now()]);

    $this->actingAs($this->user)->delete(route('lessons.destroy', $this->lesson))->assertRedirect(route('dashboard'));

    $deleted = Lesson::withTrashed()->find($this->lesson->id);
    expect($deleted->trashed())->toBeTrue()
        ->and($deleted->status)->toBe(LessonStatus::Failed)
        ->and($deleted->published_at)->toBeNull()
        ->and($deleted->only(['content', 'prompt', 'notes', 'topic', 'source_summary', 'additions', 'hero', 'hero_plan', 'hero_error', 'check_notes', 'error', 'step']))
        ->each->toBeNull()
        ->and($deleted->title)->toBe('Biotop + Biozönose = Ökosystem')
        ->and($deleted->subject)->toBe($this->lesson->subject)
        ->and($deleted->child_id)->toBe($this->child->id)
        ->and(Attempt::where('lesson_id', $this->lesson->id)->count())->toBe(0)
        ->and(Attempt::where('lesson_id', $other->id)->count())->toBe(1);

    $this->actingAs($this->user)->get(route('costs'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lessons.0.id', $this->lesson->id)
            ->where('lessons.0.title', 'Biotop + Biozönose = Ökosystem')
            ->where('lessons.0.deleted', true)
        );

    $this->actingAs($this->user)->get(route('children.progress', $this->child))
        ->assertInertia(fn (Assert $page) => $page->where('child.lastActivity', $old->created_at->diffForHumans()));
});

it('shows the public pages', function () {
    $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Welcome'));
    $this->get(route('privacy'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Privacy'));
});
