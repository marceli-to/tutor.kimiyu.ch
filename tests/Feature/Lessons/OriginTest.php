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
    $content['sections'][0]['blocks'][0]['origin'] = 'added';

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
            ->where('lesson.content.sections.0.blocks.0.origin', 'added')
            ->where('parent.additions', ['Zellatmung als Gegenstück'])
        );
});

it('hides the origin from the child', function () {
    $this->get(route('shared.show', [$this->child->share_token, $this->lesson]))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            expect(json_encode($page->toArray()['props']['lesson']))->not->toContain('origin');
        });
});

it('hides the origin for lessons without photos', function () {
    $this->lesson->update(['photo_count' => 0, 'prompt' => 'Fotosynthese für die Prüfung']);

    $this->actingAs($this->user)
        ->get(route('lessons.show', $this->lesson))
        ->assertInertia(function (Assert $page) {
            $props = $page->toArray()['props'];

            expect(json_encode($props['lesson']))->not->toContain('origin')
                ->and($props['parent']['additions'])->toBe([]);
        });
});

it('sends the origin to the editor only for photo lessons', function () {
    $this->actingAs($this->user)
        ->get(route('lessons.edit', $this->lesson))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lesson.content.sections.0.blocks.0.origin', 'added')
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
    $content['meta']['title'] = 'Zucker aus Licht';
    // The cloze comes back as markup, without origin in the object
    unset($content['modules']['cloze']['origin']);

    $this->actingAs($this->user)->put(route('lessons.update', $this->lesson), [
        'content' => $content,
        'clozeMarkup' => 'Blätter sind [grün] wegen [Chlorophyll].',
    ])->assertSessionHasNoErrors();

    $stored = $this->lesson->fresh()->content;

    expect($stored['sections'][0]['blocks'][0]['origin'])->toBe('added')
        ->and($stored['modules']['cloze']['origin'])->toBe('photo');
});

it('keeps origin and prompt out of everything the child receives', function () {
    $this->lesson->update(['prompt' => 'Geheimer Auftrag der Eltern']);

    foreach ([
        $this->get(route('shared.index', $this->child->share_token)),
        $this->get(route('shared.show', [$this->child->share_token, $this->lesson])),
        $this->postJson(route('shared.answer', [$this->child->share_token, $this->lesson]), ['module' => 'quiz', 'item_id' => 'q1', 'answer' => 1]),
        $this->postJson(route('shared.answer', [$this->child->share_token, $this->lesson]), ['module' => 'cloze', 'item_id' => 'g1', 'answer' => 'x']),
    ] as $response) {
        $response->assertOk()
            // The key in the page data, escaped (attribute) or not (script tag); «origin» alone also appears in the HTML
            ->assertDontSee('"origin"')
            ->assertDontSee('"origin"', false)
            ->assertDontSee('Geheimer Auftrag')
            ->assertDontSee('Zellatmung als Gegenstück');
    }
});
