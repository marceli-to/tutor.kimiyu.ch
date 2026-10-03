<?php

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Lessons\Ai\ModelException;
use App\Lessons\GenerationFailed;
use App\Lessons\LessonGone;
use App\Models\Lesson;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One step of the generation. The steps run as a chain (Bus::chain):
 * if one fails for good, the chain stops and the lesson is marked as failed.
 */
abstract class LessonStep implements ShouldQueue
{
	use Queueable;

	// An API call can take several minutes
	public int $timeout = 900;

	// A second attempt on temporary errors (overload, connection)
	public int $tries = 2;

	public int $backoff = 60;

	public function __construct(public Lesson $lesson) {}

	abstract protected function step(): ?string;

	abstract protected function run(): void;

	/**
	 * Status after an error. When single parts are regenerated, the page stays usable.
	 * null keeps the status (and the publication) as it is.
	 */
	protected function statusAfterFailure(): ?LessonStatus
	{
		return LessonStatus::Failed;
	}

	public function handle(): void
	{
		// The queue also loads deleted lessons: then do nothing more, cause no costs
		if ($this->lesson->trashed()) {
			return;
		}

		if ($this->step() !== null) {
			$this->lesson->update(['step' => $this->step()]);
		}

		try {
			$this->run();
		} catch (LessonGone) {
			// Deleted during a call: nothing left to do, the next steps stop on their own
			return;
		} catch (GenerationFailed $e) {
			$this->markFailed($e->getMessage(), $e->detail);
			$this->fail($e);
		} catch (ModelException $e) {
			if ($e->retryable && $this->attempts() < $this->tries) {
				throw $e;
			}

			$this->markFailed($e->getMessage(), $e->detail);
			$this->fail($e);
		}
	}

	/**
	 * Unexpected errors (timeouts too) end up here.
	 */
	public function failed(?Throwable $e): void
	{
		$fresh = $this->lesson->fresh();

		// A regeneration leaves the status alone; it only shows itself in the step
		$running = $this->statusAfterFailure() === null
			? $fresh?->step !== null && $fresh->step === $this->step()
			: $fresh?->status === LessonStatus::Generating;

		if ($running) {
			$this->markFailed('Bei der Erstellung ist ein unerwarteter Fehler aufgetreten.', $e?->getMessage());
		}
	}

	private function markFailed(string $message, ?string $detail): void
	{
		// A deleted lesson keeps its state from the deletion
		if (! $this->lesson->stillExists()) {
			return;
		}

		Log::warning('Lernseite fehlgeschlagen', ['lesson' => $this->lesson->id, 'step' => $this->step(), 'detail' => $detail]);

		$status = $this->statusAfterFailure();

		$this->lesson->update([
			...($status !== null ? ['status' => $status] : []),
			'step' => null,
			'error' => $message,
		]);
	}
}
