<?php

use App\Models\Child;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->child = Child::factory()->for($this->user)->create();
    $this->lesson = Lesson::factory()->for($this->child)->fromFixture('oekosystem')->create();
});

it('shows a lesson to the parent', function () {
    $this->actingAs($this->user)
        ->get(route('lessons.show', $this->lesson))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('lessons/Show')
            ->where('lesson.content.meta.titel', 'Biotop + Biozönose = Ökosystem')
            ->where('lesson.palette.light.accent', '#134E5E')
            ->where('lesson.subject', 'Biologie')
            ->has('lesson.hero.url')
        );
});

it('hides a lesson from other parents', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('lessons.show', $this->lesson))
        ->assertForbidden();
});

it('redirects guests to the login', function () {
    $this->get(route('lessons.show', $this->lesson))
        ->assertRedirect(route('login'));
});

it('does not expose the child share token', function () {
    expect($this->child->toArray())->not->toHaveKey('share_token')
        ->and($this->child->share_token)->toHaveLength(40);
});

describe('graphic document', function () {
    it('needs a signed url', function () {
        $this->get(route('lessons.graphic', [$this->lesson, 1]))->assertForbidden();
    });

    it('is served with a csp that blocks all network access', function () {
        $response = $this->get(URL::signedRoute('lessons.graphic', [$this->lesson, 1]));

        $response->assertOk()
            ->assertHeader('Content-Security-Policy')
            ->assertSee('<div class="formula"', escape: false)
            ->assertSee('--cat1:#8A5A24;', escape: false)
            ->assertSee("parent.postMessage(msg,'*')", escape: false);

        $csp = $response->headers->get('Content-Security-Policy');

        expect($csp)->toContain("default-src 'none'")
            ->toContain('font-src data:')
            ->not->toContain('connect-src')
            ->not->toContain('http');
    });

    it('returns 404 when the lesson has no hero', function () {
        $this->lesson->graphic(1)->update(['graphic' => null]);

        $this->get(URL::signedRoute('lessons.graphic', [$this->lesson, 1]))->assertNotFound();
    });
});
