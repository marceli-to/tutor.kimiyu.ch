<?php

namespace App\Lessons\Ai;

/**
 * Error after a successful API answer: the tokens were used and belong in the cost log.
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
