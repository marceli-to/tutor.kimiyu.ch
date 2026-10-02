<?php

use App\Models\Lesson;

// child_id gesetzt, damit make() kein Kind in der Datenbank anlegt
function makeLesson(array $attributes = []): Lesson
{
    return Lesson::factory()->make(['child_id' => 1, ...$attributes]);
}

describe('isFromTopic', function () {
    it('is true for a lesson with a topic only', function () {
        expect(makeLesson(['topic' => 'Kommaregeln'])->isFromTopic())->toBeTrue();
    });

    it('is true for a prompt without photos', function () {
        expect(makeLesson(['prompt' => 'Erkläre die Kommaregeln.', 'photo_count' => 0])->isFromTopic())->toBeTrue();
    });

    it('is false for a prompt with photos', function () {
        expect(makeLesson(['prompt' => 'Prüfung am Freitag', 'photo_count' => 2])->isFromTopic())->toBeFalse();
    });

    it('is false without topic and prompt', function () {
        expect(makeLesson()->isFromTopic())->toBeFalse();
    });
});

describe('displayTitle', function () {
    it('prefers the title', function () {
        expect(makeLesson(['title' => 'Fotosynthese', 'topic' => 'Pflanzen', 'prompt' => 'Auftrag'])->displayTitle())
            ->toBe('Fotosynthese');
    });

    it('falls back to the topic', function () {
        expect(makeLesson(['topic' => 'Pflanzen', 'prompt' => 'Auftrag'])->displayTitle())->toBe('Pflanzen');
    });

    it('falls back to the shortened prompt', function () {
        $title = makeLesson(['prompt' => str_repeat('a', 100)])->displayTitle();

        expect($title)->toEndWith('...')
            ->and(mb_strlen($title))->toBeLessThanOrEqual(63);
    });

    it('falls back to a placeholder', function () {
        expect(makeLesson()->displayTitle())->toBe('Neue Lernseite');
    });
});
