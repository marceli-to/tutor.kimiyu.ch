<?php

namespace App\Lessons\Ai;

/**
 * Ein Aufruf mit strukturierter Ausgabe: System-Prompt, Benutzerinhalt (Text und Bilder), JSON-Schema.
 */
final readonly class ModelRequest
{
    /**
     * @param  string  $step  analyse, reparatur, pruefung, grafik oder grafik-reparatur
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
}
