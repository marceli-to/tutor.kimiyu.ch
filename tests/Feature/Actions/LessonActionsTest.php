<?php

use App\Actions\Lessons\DeleteLesson;
use App\Actions\Lessons\UpdateLessonContent;
use App\Enums\LessonStatus;
use App\Lessons\ClozeParser;
use App\Models\Child;
use App\Models\Lesson;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
	Storage::fake('lesson-images');
	$this->lesson = Lesson::factory()->for(Child::factory())->fromFixture()->create();
});

it('deletes photos, progress, content and graphics but keeps the row', function () {
	Storage::disk('lesson-images')->put("{$this->lesson->id}/a.jpg", 'jpeg');
	$this->lesson->images()->create(['path' => "{$this->lesson->id}/a.jpg", 'mime_type' => 'image/jpeg', 'size' => 4, 'position' => 0]);
	$this->lesson->child->attempts()->create(['lesson_id' => $this->lesson->id, 'module' => 'quiz', 'item_id' => 'q1', 'correct' => true]);

	app(DeleteLesson::class)->handle($this->lesson);

	$deleted = Lesson::withTrashed()->find($this->lesson->id);
	expect($deleted->trashed())->toBeTrue()
		->and($deleted->status)->toBe(LessonStatus::Failed)
		->and($deleted->content)->toBeNull()
		->and($deleted->published_at)->toBeNull()
		->and($deleted->title)->not->toBeNull()
		->and($deleted->graphics()->count())->toBe(0)
		->and($deleted->attempts()->count())->toBe(0)
		->and($deleted->images()->count())->toBe(0)
		->and(Storage::disk('lesson-images')->allFiles())->toBe([]);
});

it('stores corrected content with the title from the meta data', function () {
	$content = $this->lesson->content;
	$content['meta']['title'] = 'Neuer Titel';

	app(UpdateLessonContent::class)->handle($this->lesson, $content, ClozeParser::toMarkup($content['modules']['cloze']['segments']));

	expect($this->lesson->fresh()->title)->toBe('Neuer Titel')
		->and($this->lesson->fresh()->content['meta']['title'])->toBe('Neuer Titel');
});

it('rejects broken cloze markup and invalid content', function (Closure $change, string $key) {
	$content = $this->lesson->content;
	$markup = ClozeParser::toMarkup($content['modules']['cloze']['segments']);
	[$content, $markup] = $change($content, $markup);

	try {
		app(UpdateLessonContent::class)->handle($this->lesson, $content, $markup);
		$this->fail('No ValidationException');
	} catch (ValidationException $e) {
		expect(array_keys($e->errors()))->toContain($key);
	}

	expect($this->lesson->fresh()->content)->toBe($this->lesson->content);
})->with([
	'cloze' => [fn (array $content) => [$content, 'Text mit [offener Klammer'], 'clozeMarkup'],
	'content' => [fn (array $content, string $markup) => [array_replace_recursive($content, ['meta' => ['title' => '']]), $markup], 'content.meta.title'],
]);
