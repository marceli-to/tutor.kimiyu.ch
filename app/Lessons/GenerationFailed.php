<?php

namespace App\Lessons;

use RuntimeException;

/**
 * The generation can't continue. The message is shown to the parents.
 */
class GenerationFailed extends RuntimeException
{
	public function __construct(string $message, public readonly ?string $detail = null)
	{
		parent::__construct($message);
	}
}
