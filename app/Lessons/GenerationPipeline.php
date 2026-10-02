<?php

namespace App\Lessons;

use App\Enums\LessonStatus;
use App\Jobs\AnalyzeLesson;
use App\Jobs\CheckLesson;
use App\Jobs\FinishLesson;
use App\Jobs\GenerateLessonGraphic;
use App\Jobs\RegenerateGraphic;
use App\Jobs\RegenerateQuiz;
use App\Models\Lesson;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;

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

        // Ein Job pro möglicher Grafik; welche einen Plan haben, steht erst nach der Analyse fest
        $positions = match ($lesson->graphics_mode) {
            'none' => [],
            'custom' => [1, 2, 3],
            default => [1],
        };
        $finished = $lesson->graphics()->whereNotNull('graphic')->pluck('position')->all();

        foreach (array_diff($positions, $finished) as $position) {
            $jobs[] = new GenerateLessonGraphic($lesson, $position);
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
     * Einen Teil einer fertigen Seite neu erstellen lassen: «quiz» oder die Grafik an Position $position.
     */
    public static function regenerate(Lesson $lesson, string $part, int $position = 1): void
    {
        $job = match ($part) {
            'quiz' => new RegenerateQuiz($lesson),
            'grafik' => new RegenerateGraphic($lesson, $position),
            default => throw new InvalidArgumentException("Unbekannter Teil: {$part}"),
        };

        $lesson->update([
            'status' => LessonStatus::Generating,
            'step' => "neu-{$part}",
            'error' => null,
        ]);

        Bus::chain([$job, new FinishLesson($lesson)])->dispatch();
    }

    /**
     * Eine Grafik lässt sich nur neu erstellen, wenn es für sie einen Plan gibt.
     */
    public static function canRegenerate(Lesson $lesson, string $part, int $position = 1): bool
    {
        return in_array($lesson->status, [LessonStatus::Review, LessonStatus::Published], true)
            && $lesson->content !== null
            && ($part !== 'grafik' || $lesson->graphic($position)?->plan !== null);
    }

    /**
     * Ein neuer Versuch geht nur, wenn es eine Quelle gibt (Fotos, Auftrag ohne Fotos oder Thema) oder der Inhalt schon steht.
     */
    public static function canRetry(Lesson $lesson): bool
    {
        return $lesson->status === LessonStatus::Failed
            && ($lesson->content !== null || $lesson->isFromTopic() || $lesson->images()->exists());
    }
}
