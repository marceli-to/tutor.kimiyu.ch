<?php

namespace App\Lessons;

use App\Enums\LessonStatus;
use App\Jobs\CheckLesson;
use App\Jobs\FinishLesson;
use App\Jobs\GenerateLessonGraphic;
use App\Jobs\LessonStep;
use App\Jobs\PlanLesson;
use App\Jobs\RegenerateGraphic;
use App\Jobs\RegenerateQuiz;
use App\Jobs\WriteLesson;
use App\Models\Lesson;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;

/**
 * Starts the job chains: planning (stops for the parents' review) and writing. On a retry finished steps are skipped.
 */
class GenerationPipeline
{
	/**
	 * Create and retry: graphics only if the content exists, writing if the plan is confirmed, else planning.
	 */
	public static function start(Lesson $lesson): void
	{
		match (true) {
			$lesson->content !== null => self::dispatch($lesson, 'queued', self::graphicJobs($lesson)),
			$lesson->plan_confirmed_at !== null => self::write($lesson),
			default => self::plan($lesson),
		};
	}

	/**
	 * The planning call. The job stops for the review or starts the writing itself.
	 */
	public static function plan(Lesson $lesson): void
	{
		self::dispatch($lesson, 'queued', [new PlanLesson($lesson)]);
	}

	/**
	 * Text part, modules, check and the planned graphics.
	 */
	public static function write(Lesson $lesson): void
	{
		$jobs = [new WriteLesson($lesson)];

		if (config('lessons.check_enabled')) {
			$jobs[] = new CheckLesson($lesson);
		}

		// «page» rather than «queued»: the progress shows the planning as done
		self::dispatch($lesson, 'page', [...$jobs, ...self::graphicJobs($lesson)]);
	}

	/**
	 * One job per planned graphic that isn't built yet, then the finish.
	 *
	 * @return list<LessonStep>
	 */
	private static function graphicJobs(Lesson $lesson): array
	{
		$positions = $lesson->graphics()->whereNotNull('plan')->whereNull('graphic')->pluck('position');

		return [
			...$positions->map(fn (int $position) => new GenerateLessonGraphic($lesson, $position))->values()->all(),
			new FinishLesson($lesson),
		];
	}

	/**
	 * @param  list<LessonStep>  $jobs
	 */
	private static function dispatch(Lesson $lesson, string $step, array $jobs): void
	{
		$lesson->update([
			'status' => LessonStatus::Generating,
			'step' => $step,
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
