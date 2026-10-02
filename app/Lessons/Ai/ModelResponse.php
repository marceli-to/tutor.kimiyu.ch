<?php

namespace App\Lessons\Ai;

final readonly class ModelResponse
{
	/**
	 * @param  array<string, mixed>  $data  the parsed JSON answer
	 */
	public function __construct(
		public array $data,
		public string $model,
		public int $inputTokens = 0,
		public int $outputTokens = 0,
		public int $cacheReadTokens = 0,
		public int $cacheWriteTokens = 0,
	) {}

	/**
	 * Cost in USD according to config/lessons.php. Unknown models cost 0, so the log doesn't break.
	 */
	public function costUsd(): float
	{
		$price = config("lessons.pricing.{$this->model}");

		if (! is_array($price)) {
			return 0.0;
		}

		return ($this->inputTokens * $price['input']
			+ $this->outputTokens * $price['output']
			+ $this->cacheReadTokens * $price['cache_read']
			+ $this->cacheWriteTokens * $price['cache_write']) / 1_000_000;
	}
}
