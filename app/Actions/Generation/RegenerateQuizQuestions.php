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
     * New quiz for an existing page.
     *
     * @throws GenerationFailed when the new quiz is invalid; the old one stays then
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
