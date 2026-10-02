<?php

namespace App\Lessons;

use RuntimeException;

/**
 * The lesson was deleted while a step was waiting for the AI. The step stops without writing anything.
 */
class LessonGone extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('The lesson was deleted during generation.');
	}
}
