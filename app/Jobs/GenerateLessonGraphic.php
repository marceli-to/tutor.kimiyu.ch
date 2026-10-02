<?php

namespace App\Jobs;

use App\Lessons\LessonGenerator;
use App\Models\Lesson;

/**
 * Eine Grafik der Lernseite. Ohne Plan für diese Position tut der Job nichts.
 */
class GenerateLessonGraphic extends LessonStep
{
    public function __construct(Lesson $lesson, public int $position)
    {
        parent::__construct($lesson);
    }

    // For the progress display; the API steps are still called «graphic» and «graphic-repair»
    protected function step(): string
    {
        return "graphic-{$this->position}";
    }

    protected function run(LessonGenerator $generator): void
    {
        $generator->graphic($this->lesson, $this->position);
    }
}
