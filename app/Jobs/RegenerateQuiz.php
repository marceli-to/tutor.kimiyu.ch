<?php

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Lessons\LessonGenerator;

/**
 * Writes a new quiz for a finished page. A published page stays online meanwhile.
 */
class RegenerateQuiz extends LessonStep
{
    protected function step(): string
    {
        return 'neu-quiz';
    }

    protected function run(LessonGenerator $generator): void
    {
        $generator->regenerateQuiz($this->lesson);

        $this->lesson->update(RegenerateGraphic::needsReview());
    }

    // Scheitert es, bleibt die Seite wie vorher, auch freigegeben
    protected function statusAfterFailure(): ?LessonStatus
    {
        return null;
    }
}
