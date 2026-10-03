<?php

namespace App\Lessons\Speech;

use RuntimeException;

/**
 * ElevenLabs couldn't create a clip. With an exhausted quota no further call makes sense this month.
 */
class SpeechFailed extends RuntimeException
{
	public function __construct(string $message, public readonly bool $quotaExceeded = false)
	{
		parent::__construct($message);
	}
}
