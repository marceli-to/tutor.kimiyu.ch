<?php

use App\Actions\Generation\CallModel;
use App\Lessons\Ai\FakeLanguageModel;
use App\Lessons\Ai\ModelException;
use App\Lessons\Ai\Prompts;
use App\Lessons\GenerationFailed;
use App\Lessons\LessonGone;
use App\Models\Child;
use App\Models\Generation;
use App\Models\Lesson;

beforeEach(function () {
    $this->fake = FakeLanguageModel::install();
    $this->lesson = Lesson::factory()->for(Child::factory())->fromFixture()->create();
});

it('returns the response and logs the costs', function () {
    $response = app(CallModel::class)->handle($this->lesson, Prompts::quiz($this->lesson));

    expect($response->data)->toBe(FakeLanguageModel::defaultResponse('regenerate-quiz'))
        ->and(Generation::where('lesson_id', $this->lesson->id)->pluck('status')->all())->toBe(['ok']);
});

it('stops with LessonGone when the lesson was deleted during the call, but keeps the cost entry', function () {
    $this->fake->push('regenerate-quiz', function () {
        $this->lesson->delete();

        return FakeLanguageModel::defaultResponse('regenerate-quiz');
    });

    expect(fn () => app(CallModel::class)->handle($this->lesson, Prompts::quiz($this->lesson)))->toThrow(LessonGone::class)
        ->and(Generation::where('lesson_id', $this->lesson->id)->count())->toBe(1);
});

it('logs failed calls', function () {
    $this->fake->push('regenerate-quiz', new ModelException('Die KI war nicht erreichbar.'));

    expect(fn () => app(CallModel::class)->handle($this->lesson, Prompts::quiz($this->lesson)))->toThrow(ModelException::class)
        ->and(Generation::where('lesson_id', $this->lesson->id)->pluck('status')->all())->toBe(['error']);
});

it('does not call the api for a lesson without child', function () {
    $this->lesson->setRelation('child', null);

    expect(fn () => app(CallModel::class)->handle($this->lesson, Prompts::quiz($this->lesson)))->toThrow(GenerationFailed::class)
        ->and($this->fake->requests)->toBe([]);
});
