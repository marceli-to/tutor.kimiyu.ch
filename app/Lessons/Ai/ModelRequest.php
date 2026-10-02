<?php

namespace App\Lessons\Ai;

/**
 * One call with structured output: system prompt, user content (text and images), JSON schema.
 */
final readonly class ModelRequest
{
    /**
     * @param  string  $step  analysis, page, modules, regenerate-quiz, repair-*, check, graphic or graphic-repair
     * @param  list<array{mime: string, data: string}>  $images  images as binary data
     * @param  array<string, mixed>  $schema  JSON schema of the answer
     */
    public function __construct(
        public string $step,
        public string $system,
        public string $prompt,
        public array $schema,
        public int $maxTokens,
        public array $images = [],
    ) {}

    public function model(): string
    {
        return $this->setting('model') ?? config('services.anthropic.model');
    }

    public function effort(): string
    {
        return $this->setting('effort') ?? config('services.anthropic.effort');
    }

    /**
     * Setting for this step from config/lessons.php: first the exact step
     * («graphic-repair»), then the part before the hyphen («graphic»).
     */
    private function setting(string $key): ?string
    {
        foreach ([$this->step, strtok($this->step, '-')] as $name) {
            $value = config("lessons.models.{$name}.{$key}");

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
