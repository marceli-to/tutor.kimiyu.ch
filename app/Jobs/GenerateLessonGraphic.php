<?php

namespace App\Jobs;

use App\Actions\Generation\GenerateGraphic;
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

    protected function run(): void
    {
        app(GenerateGraphic::class)->handle($this->lesson, $this->position);
    }
}
