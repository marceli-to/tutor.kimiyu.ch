<?php

use App\Enums\LessonStatus;
use App\Models\Child;
use App\Models\Lesson;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->child = Child::factory()->for($this->user)->create();

    $content = Lesson::factory()->fromFixture('fotosynthese')->raw()['content'];
    $content['abschnitte'][0]['bloecke'][0]['herkunft'] = 'ergaenzt';

    $this->lesson = Lesson::factory()->for($this->child)->fromFixture('fotosynthese')->create([
        'photo_count' => 2,
        'content' => $content,
        'additions' => ['Zellatmung als Gegenstück'],
    ]);
});

it('shows the origin and the additions to the parent of a photo lesson', function () {
    $this->actingAs($this->user)
        ->get(route('lessons.show', $this->lesson))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lesson.content.abschnitte.0.bloecke.0.herkunft', 'ergaenzt')
            ->where('parent.additions', ['Zellatmung als Gegenstück'])
        );
});

it('hides the origin from the child', function () {
    $this->get(route('shared.show', [$this->child->share_token, $this->lesson]))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            expect(json_encode($page->toArray()['props']['lesson']))->not->toContain('herkunft');
        });
});

it('hides the origin for lessons without photos', function () {
    $this->lesson->update(['photo_count' => 0, 'prompt' => 'Fotosynthese für die Prüfung']);

    $this->actingAs($this->user)
        ->get(route('lessons.show', $this->lesson))
        ->assertInertia(function (Assert $page) {
            $props = $page->toArray()['props'];

            expect(json_encode($props['lesson']))->not->toContain('herkunft')
                ->and($props['parent']['additions'])->toBe([]);
        });
});

it('sends the origin to the editor only for photo lessons', function () {
    $this->actingAs($this->user)
        ->get(route('lessons.edit', $this->lesson))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lesson.content.abschnitte.0.bloecke.0.herkunft', 'ergaenzt')
            ->where('showOrigin', true)
        );

    $this->lesson->update(['photo_count' => 0, 'prompt' => 'Fotosynthese']);

    $this->actingAs($this->user)
        ->get(route('lessons.edit', $this->lesson))
        ->assertInertia(fn (Assert $page) => $page->where('showOrigin', false));
});

it('keeps the origin when the content is edited', function () {
    $this->lesson->update(['status' => LessonStatus::Review, 'published_at' => null]);

    $content = $this->lesson->content;
    $content['meta']['titel'] = 'Zucker aus Licht';
    // Der Lückentext kommt als Markup zurück, ohne Herkunft im Objekt
    unset($content['module']['lueckentext']['herkunft']);

    $this->actingAs($this->user)->put(route('lessons.update', $this->lesson), [
        'content' => $content,
        'clozeMarkup' => 'Blätter sind [grün] wegen [Chlorophyll].',
    ])->assertSessionHasNoErrors();

    $stored = $this->lesson->fresh()->content;

    expect($stored['abschnitte'][0]['bloecke'][0]['herkunft'])->toBe('ergaenzt')
        ->and($stored['module']['lueckentext']['herkunft'])->toBe('foto');
});
