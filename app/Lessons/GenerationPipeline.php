<?php

namespace App\Lessons;

use App\Enums\LessonStatus;
use App\Jobs\AnalyzeLesson;
use App\Jobs\CheckLesson;
use App\Jobs\FinishLesson;
use App\Jobs\GenerateLessonHero;
use App\Models\Lesson;
use Illuminate\Support\Facades\Bus;

/**
 * Startet die Job-Kette. Bei einem neuen Versuch werden erledigte Schritte übersprungen.
 */
class GenerationPipeline
{
    public static function start(Lesson $lesson): void
    {
        $jobs = [];

        if ($lesson->content === null) {
            $jobs[] = new AnalyzeLesson($lesson);

            if (config('lessons.check_enabled')) {
                $jobs[] = new CheckLesson($lesson);
            }
        }

        if ($lesson->hero === null) {
            $jobs[] = new GenerateLessonHero($lesson);
        }

        $jobs[] = new FinishLesson($lesson);

        $lesson->update([
            'status' => LessonStatus::Generating,
            'step' => 'warteschlange',
            'error' => null,
        ]);

        Bus::chain($jobs)->dispatch();
    }

    /**
     * Ein neuer Versuch geht nur, wenn noch Fotos da sind oder der Inhalt schon steht.
     */
    public static function canRetry(Lesson $lesson): bool
    {
        return $lesson->status === LessonStatus::Failed
            && ($lesson->content !== null || $lesson->images()->exists());
    }
}
