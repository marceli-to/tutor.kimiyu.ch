<?php

namespace App\Http\PageData;

use App\Models\Child;
use App\Models\Lesson;
use App\Models\User;

/**
 * Bibliothek: pro Kind die Lernseiten, nach Fach gruppiert und neueste zuerst.
 */
class Dashboard
{
    public function __construct(private User $user) {}

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        $children = $this->user->children()
            ->with(['lessons' => fn ($q) => $q->latest()])
            ->orderBy('name')
            ->get();

        return [
            'children' => $children->map(fn (Child $child) => [
                'id' => $child->id,
                'name' => $child->name,
                'level' => $child->level,
                'shareUrl' => route('shared.index', $child->share_token),
                'subjects' => $child->lessons
                    ->groupBy(fn (Lesson $lesson) => $lesson->subjectLabel())
                    // Without a subject (failed before the detection, or not detected yet) at the top
                    ->sortKeysUsing(fn (string $a, string $b) => self::placeholderOrder($a) <=> self::placeholderOrder($b) ?: strnatcasecmp($a, $b))
                    ->map(fn ($lessons, string $subject) => [
                        'name' => $subject,
                        'lessons' => $lessons->map(fn (Lesson $lesson) => [
                            'id' => $lesson->id,
                            'title' => $lesson->displayTitle(),
                            'emoji' => $lesson->content['meta']['emoji'] ?? null,
                            'status' => $lesson->status->value,
                            'statusLabel' => $lesson->status->label(),
                            'date' => $lesson->created_at?->translatedFormat('j. F Y'),
                        ])->values(),
                    ])
                    ->values(),
            ]),
        ];
    }

    /**
     * Placeholders sort before the real subjects: «Fach unbekannt» first, then «Fach wird erkannt …».
     */
    private static function placeholderOrder(string $subject): int
    {
        return match ($subject) {
            Lesson::SUBJECT_UNKNOWN => 0,
            Lesson::SUBJECT_PENDING => 1,
            default => 2,
        };
    }
}
