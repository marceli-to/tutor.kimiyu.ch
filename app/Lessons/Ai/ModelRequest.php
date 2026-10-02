<?php

namespace App\Lessons\Ai;

/**
 * Ein Aufruf mit strukturierter Ausgabe: System-Prompt, Benutzerinhalt (Text und Bilder), JSON-Schema.
 */
final readonly class ModelRequest
{
    /**
     * @param  string  $step  analyse, module, neu-quiz, reparatur-*, pruefung, grafik oder grafik-reparatur
     * @param  list<array{mime: string, data: string}>  $images  Bilder als Binärdaten
     * @param  array<string, mixed>  $schema  JSON-Schema der Antwort
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
     * Einstellung für diesen Schritt aus config/lessons.php: zuerst der genaue Schritt
     * («grafik-reparatur»), dann der Teil vor dem Bindestrich («grafik»).
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
