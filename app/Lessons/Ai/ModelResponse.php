<?php

namespace App\Lessons\Ai;

final readonly class ModelResponse
{
    /**
     * @param  array<string, mixed>  $data  die geparste JSON-Antwort
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
     * Kosten in USD nach config/lessons.php. Unbekannte Modelle kosten 0, damit das Log nicht bricht.
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
