<?php

namespace App\Actions\Children;

use App\Lessons\LessonGenerator;
use App\Models\Child;

/**
 * Löscht das Kind mit allen Lernseiten und dem Lernstand.
 */
class DeleteChild
{
    public function __construct(private LessonGenerator $generator) {}

    public function handle(Child $child): void
    {
        foreach ($child->lessons as $lesson) {
            $this->generator->deleteImages($lesson);
        }

        $child->delete();
    }
}
