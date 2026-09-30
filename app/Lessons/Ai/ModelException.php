<?php

namespace App\Lessons\Ai;

use RuntimeException;
use Throwable;

/**
 * Das Modell hat nicht geliefert: API-Fehler, Ablehnung, abgeschnittene oder ungültige Antwort.
 * Die Nachricht ist für Eltern verständlich, Details stehen in $detail.
 */
class ModelException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $detail = null,
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
