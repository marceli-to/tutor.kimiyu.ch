<?php

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Lessons\LessonGenerator;
use App\Models\Lesson;

class RegenerateGraphic extends LessonStep
{
    public function __construct(Lesson $lesson, public int $position)
    {
        parent::__construct($lesson);
    }

    protected function step(): string
    {
        return 'neu-grafik';
    }

    protected function run(LessonGenerator $generator): void
    {
        $generator->graphic($this->lesson, $this->position, keepExisting: true);
    }

    // Scheitert es, bleibt die Seite wie vorher, auch freigegeben
    protected function statusAfterFailure(): LessonStatus
    {
        return $this->lesson->published_at ? LessonStatus::Published : LessonStatus::Review;
    }
}
