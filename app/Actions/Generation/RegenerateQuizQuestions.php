<?php

namespace App\Actions\Generation;

use App\Lessons\Ai\Prompts;
use App\Lessons\ContentValidator;
use App\Lessons\GenerationFailed;
use App\Models\Lesson;

class RegenerateQuizQuestions
{
    public function __construct(private CallModel $callModel) {}

    /**
     * Neues Quiz für eine bestehende Seite.
     *
     * @throws GenerationFailed wenn das neue Quiz ungültig ist; das alte bleibt dann
     */
    public function handle(Lesson $lesson): void
    {
        $quiz = $this->callModel->handle($lesson, Prompts::quiz($lesson))->data['quiz'] ?? null;

        $content = $lesson->content;
        $content['modules']['quiz'] = $quiz;

        if (! is_array($quiz) || ($errors = ContentValidator::errors($content)) !== []) {
            throw new GenerationFailed('Das neue Quiz war fehlerhaft. Das bisherige Quiz bleibt.', implode(' | ', $errors ?? []));
        }

        $lesson->update(['content' => $content]);
    }
}
