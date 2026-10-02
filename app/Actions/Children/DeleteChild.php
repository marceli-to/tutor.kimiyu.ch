<?php

namespace App\Actions\Children;

use App\Actions\Generation\DeleteLessonImages;
use App\Models\Child;

/**
 * Löscht das Kind mit allen Lernseiten und dem Lernstand.
 */
class DeleteChild
{
    public function __construct(private DeleteLessonImages $deleteImages) {}

    public function handle(Child $child): void
    {
        foreach ($child->lessons as $lesson) {
            $this->deleteImages->handle($lesson);
        }

        $child->delete();
    }
}
