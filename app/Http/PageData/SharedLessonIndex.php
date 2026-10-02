<?php

namespace App\Http\PageData;

use App\Enums\LessonStatus;
use App\Lessons\Progress;
use App\Models\Child;
use App\Models\Lesson;

/**
 * Props for shared/Index: the child's published lessons by subject, with its progress.
 */
class SharedLessonIndex
{
    public function __construct(private Child $child) {}

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        $lessons = $this->child->lessons()
            ->where('status', LessonStatus::Published)
            ->latest('published_at')
            ->get();

        $progress = Progress::summaries($this->child, $lessons);

        return [
            'token' => $this->child->share_token,
            'childName' => $this->child->name,
            'subjects' => $lessons
                // Published lessons always have a subject after the analysis; guard anyway
                ->groupBy(fn (Lesson $lesson) => (string) $lesson->subject)
                ->sortKeys(SORT_NATURAL | SORT_FLAG_CASE)
                ->map(fn ($lessons, string $subject) => [
                    'name' => $subject !== '' ? $subject : 'Allgemein',
                    'lessons' => $lessons->map(fn (Lesson $lesson) => [
                        'id' => $lesson->id,
                        'title' => $lesson->title,
                        'emoji' => $lesson->content['meta']['emoji'] ?? null,
                        'key_idea' => $lesson->content['meta']['key_idea'] ?? null,
                        'progress' => [
                            'mastered' => $progress[$lesson->id]['counts']['mastered'],
                            'total' => $progress[$lesson->id]['total'],
                        ],
                    ])->values(),
                ])
                ->values(),
        ];
    }
}
