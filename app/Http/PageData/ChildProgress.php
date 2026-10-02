<?php

namespace App\Http\PageData;

use App\Enums\LessonStatus;
use App\Lessons\Progress;
use App\Models\Child;
use App\Models\Lesson;
use Illuminate\Support\Carbon;

/**
 * Progress: per lesson, what is mastered and what still needs practice.
 */
class ChildProgress
{
    public function __construct(private Child $child) {}

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        $lessons = $this->child->lessons()
            ->whereNotNull('content')
            ->whereIn('status', [LessonStatus::Review, LessonStatus::Published])
            ->latest()
            ->get();

        $summaries = Progress::summaries($this->child, $lessons);
        $lastActivity = $this->child->attempts()->latest('created_at')->value('created_at');

        return [
            'child' => [
                'id' => $this->child->id,
                'name' => $this->child->name,
                'lastActivity' => $lastActivity ? Carbon::parse($lastActivity)->diffForHumans() : null,
            ],
            'lessons' => $lessons->map(fn (Lesson $lesson) => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'subject' => $lesson->subjectLabel(),
                'emoji' => $lesson->content['meta']['emoji'] ?? null,
                'published' => $lesson->status === LessonStatus::Published,
                ...$summaries[$lesson->id],
            ])->values(),
        ];
    }
}
