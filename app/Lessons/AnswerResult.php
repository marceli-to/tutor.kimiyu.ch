<?php

namespace App\Lessons;

/**
 * Result of checking one answer. «Almost»: a gap filled in right except for its accents;
 * the progress counts it as not correct.
 */
enum AnswerResult: string
{
	case Correct = 'correct';
	case Almost = 'almost';
	case Wrong = 'wrong';

	public function isCorrect(): bool
	{
		return $this === self::Correct;
	}
}
