<?php

namespace App\Http\PageData;

use App\Enums\LessonStatus;
use App\Lessons\GenerationPipeline;
use App\Lessons\LessonView;
use App\Models\Lesson;
use App\Models\LessonGraphic;

/**
 * Props for lessons/Show: the page as the child sees it, plus status, progress and (for the parents) their controls.
 */
class LessonPage
{
    public function __construct(private Lesson $lesson, private bool $parent) {}

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        return [
            'parent' => $this->parent ? [
                'childName' => $this->lesson->child->name,
                'shareUrl' => $this->lesson->status === LessonStatus::Published
                    ? route('shared.show', [$this->lesson->child->share_token, $this->lesson])
                    : null,
                'canPublish' => $this->lesson->canBePublished(),
                'canRegenerate' => [
                    'quiz' => GenerationPipeline::canRegenerate($this->lesson, 'quiz'),
                ],
                // How many questions a new quiz gets, for the confirm dialog
                'quizCount' => config('lessons.scope')[$this->lesson->scope]['quiz'],
                // Only the parents see the graphics' errors
                'graphics' => $this->lesson->graphics->map(fn (LessonGraphic $graphic) => [
                    'number' => $graphic->position,
                    'error' => $graphic->error,
                    'canRegenerate' => GenerationPipeline::canRegenerate($this->lesson, 'graphic', $graphic->position),
                    'hidden' => $graphic->hidden,
                ])->values()->all(),
                'additions' => $this->lesson->isFromTopic() ? [] : ($this->lesson->additions ?? []),
            ] : null,
            'lesson' => [
                ...LessonView::page($this->lesson, showOrigin: $this->parent),
                'status' => $this->lesson->status->value,
                'step' => $this->lesson->step,
                'error' => $this->lesson->error,
                'canRetry' => GenerationPipeline::canRetry($this->lesson),
                'fromTopic' => $this->lesson->isFromTopic(),
                'plannedGraphics' => self::plannedGraphics($this->lesson),
                'checkNotes' => $this->lesson->check_notes ?? [],
            ],
        ];
    }

    /**
     * Which graphics are being built, for the progress display. After the analysis those with a plan,
     * before it graphic 1 («auto») or the parents' wishes.
     *
     * @return list<int>
     */
    private static function plannedGraphics(Lesson $lesson): array
    {
        if ($lesson->graphics_mode === 'none') {
            return [];
        }

        if ($lesson->content !== null) {
            return array_values($lesson->graphics
                ->filter(fn (LessonGraphic $graphic) => $graphic->plan !== null)
                ->map(fn (LessonGraphic $graphic) => $graphic->position)
                ->all());
        }

        return $lesson->graphics_mode === 'custom'
            ? array_values($lesson->graphics->map(fn (LessonGraphic $graphic) => $graphic->position)->all())
            : [1];
    }
}
