<?php

namespace App\Lessons\Ai;

/**
 * Fehler nach einer erfolgreichen API-Antwort: Die Tokens wurden verbraucht und sollen ins Kosten-Log.
 */
class UsageAwareModelException extends ModelException
{
    public function __construct(
        string $message,
        ?string $detail,
        public readonly ModelResponse $response,
        bool $retryable = false,
    ) {
        parent::__construct($message, $detail, $retryable);
    }
}
