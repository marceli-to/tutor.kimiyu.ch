<?php

namespace App\Lessons\Speech;

/**
 * One clip from ElevenLabs: the MP3 bytes and the credits it cost.
 */
final readonly class SpeechResult
{
	public function __construct(
		public string $audio,
		public int $credits,
	) {}
}
