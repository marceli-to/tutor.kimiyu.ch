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

    // Für die Anzeige des Fortschritts; die API-Schritte heissen weiterhin «grafik» und «grafik-reparatur»
    protected function step(): string
    {
        return "grafik-{$this->position}";
    }

    protected function run(LessonGenerator $generator): void
    {
        $generator->graphic($this->lesson, $this->position);
    }
}
