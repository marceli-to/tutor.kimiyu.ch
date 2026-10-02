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
 * Starts the job chain. On a retry finished steps are skipped.
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

        // One job per possible graphic; which ones have a plan is only known after the analysis
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
            'step' => 'queued',
            'error' => null,
        ]);

        Bus::chain($jobs)->dispatch();
    }

    /**
     * Regenerates a part of a finished page: «quiz» or the graphic at position $position.
     */
    public static function regenerate(Lesson $lesson, string $part, int $position = 1): void
    {
        $job = match ($part) {
            'quiz' => new RegenerateQuiz($lesson),
            'graphic' => new RegenerateGraphic($lesson, $position),
            default => throw new InvalidArgumentException("Unbekannter Teil: {$part}"),
        };

        // A published page stays online for the child; the step shows the parent the progress
        $lesson->update([
            'step' => "regenerate-{$part}",
            'error' => null,
        ]);

        dispatch($job);
    }

    /**
     * A graphic can only be regenerated if it has a plan, the quiz only if there is one.
     */
    public static function canRegenerate(Lesson $lesson, string $part, int $position = 1): bool
    {
        return in_array($lesson->status, [LessonStatus::Review, LessonStatus::Published], true)
            && $lesson->content !== null
            && ! $lesson->isRegenerating()
            && ($part !== 'graphic' || $lesson->graphic($position)?->plan !== null)
            && ($part !== 'quiz' || ! empty($lesson->content['modules']['quiz']));
    }

    /**
     * A retry is only possible if there is a source (photos, request without photos or topic) or the content already exists.
     */
    public static function canRetry(Lesson $lesson): bool
    {
        return $lesson->status === LessonStatus::Failed
            && ($lesson->content !== null || $lesson->isFromTopic() || $lesson->images()->exists());
    }
}
