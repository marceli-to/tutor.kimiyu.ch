<?php

namespace App\Lessons\Ai;

use RuntimeException;
use Throwable;

/**
 * The model didn't deliver: API error, refusal, truncated or invalid answer.
 * The message is meant for parents; details are in $detail.
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
