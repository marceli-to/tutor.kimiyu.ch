<?php

namespace App\Lessons\Ai;

/**
 * Schnittstelle zum Sprachmodell. In Tests und lokal ohne API-Key wird sie durch FakeLanguageModel ersetzt.
 */
interface LanguageModel
{
    /**
     * @throws ModelException wenn das Modell keine brauchbare Antwort liefert
     */
    public function generate(ModelRequest $request): ModelResponse;
}
