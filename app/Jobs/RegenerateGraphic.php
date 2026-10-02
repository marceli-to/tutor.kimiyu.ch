<?php

namespace App\Jobs;

use App\Actions\Generation\GenerateGraphic;
use App\Enums\LessonStatus;
use App\Models\Lesson;

/**
 * Draws one graphic of a finished page anew. A published page stays online meanwhile.
 */
class RegenerateGraphic extends LessonStep
{
    public function __construct(Lesson $lesson, public int $position)
    {
        parent::__construct($lesson);
    }

    protected function step(): string
    {
        return 'regenerate-graphic';
    }

    protected function run(): void
    {
        $drawn = app(GenerateGraphic::class)->handle($this->lesson, $this->position, keepExisting: true);

        // Failed: the page stays as it was, also published; the error is stored on the graphic
        $this->lesson->update($drawn ? self::needsReview() : ['step' => null]);
    }

    /**
     * New content from the AI: the parents review and publish again.
     *
     * @return array<string, mixed>
     */
    public static function needsReview(): array
    {
        return [
            'status' => LessonStatus::Review,
            'step' => null,
            'error' => null,
            'published_at' => null,
        ];
    }

    // If it fails, the page stays as before, published too
    protected function statusAfterFailure(): ?LessonStatus
    {
        return null;
    }
}
