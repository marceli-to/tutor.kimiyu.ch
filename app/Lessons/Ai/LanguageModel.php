<?php

namespace App\Lessons\Ai;

/**
 * Interface to the language model. In tests and locally without an API key it is replaced by FakeLanguageModel.
 */
interface LanguageModel
{
    /**
     * @throws ModelException when the model doesn't return a usable answer
     */
    public function generate(ModelRequest $request): ModelResponse;
}
