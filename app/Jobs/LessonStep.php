<?php

namespace App\Jobs;

use App\Enums\LessonStatus;
use App\Lessons\Ai\ModelException;
use App\Lessons\GenerationFailed;
use App\Lessons\LessonGenerator;
use App\Lessons\LessonGone;
use App\Models\Lesson;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ein Schritt der Generierung. Die Schritte laufen als Kette (Bus::chain):
 * scheitert einer endgültig, bricht die Kette ab und die Lernseite wird als fehlgeschlagen markiert.
 */
abstract class LessonStep implements ShouldQueue
{
    use Queueable;

    // Ein API-Call kann mehrere Minuten dauern
    public int $timeout = 900;

    // Ein zweiter Versuch bei vorübergehenden Fehlern (Überlast, Verbindung)
    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(public Lesson $lesson) {}

    abstract protected function step(): ?string;

    abstract protected function run(LessonGenerator $generator): void;

    /**
     * Status nach einem Fehler. Beim Neu-Erstellen einzelner Teile bleibt die Seite brauchbar.
     */
    protected function statusAfterFailure(): LessonStatus
    {
        return LessonStatus::Failed;
    }

    public function handle(LessonGenerator $generator): void
    {
        // Die Queue lädt auch gelöschte Lernseiten: dann nichts mehr tun, keine Kosten verursachen
        if ($this->lesson->trashed()) {
            return;
        }

        if ($this->step() !== null) {
            $this->lesson->update(['step' => $this->step()]);
        }

        try {
            $this->run($generator);
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
     * Unerwartete Fehler (auch Timeouts) landen hier.
     */
    public function failed(?Throwable $e): void
    {
        if ($this->lesson->fresh()?->status === LessonStatus::Generating) {
            $this->markFailed('Bei der Erstellung ist ein unerwarteter Fehler aufgetreten.', $e?->getMessage());
        }
    }

    private function markFailed(string $message, ?string $detail): void
    {
        // A deleted lesson keeps its state from the deletion
        if (! Lesson::whereKey($this->lesson->id)->exists()) {
            return;
        }

        Log::warning('Lernseite fehlgeschlagen', ['lesson' => $this->lesson->id, 'step' => $this->step(), 'detail' => $detail]);

        $this->lesson->update([
            'status' => $this->statusAfterFailure(),
            'step' => null,
            'error' => $message,
        ]);
    }
}
