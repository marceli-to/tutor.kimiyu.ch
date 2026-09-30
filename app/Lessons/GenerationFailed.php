<?php

namespace App\Lessons;

use RuntimeException;

/**
 * Die Generierung kann nicht weitergehen. Die Nachricht wird den Eltern angezeigt.
 */
class GenerationFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $detail = null)
    {
        parent::__construct($message);
    }
}
