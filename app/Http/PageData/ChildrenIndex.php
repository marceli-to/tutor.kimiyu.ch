<?php

namespace App\Http\PageData;

use App\Models\Child;
use App\Models\User;

/**
 * Props for children/Index: the parent's children with lesson counts and share links.
 */
class ChildrenIndex
{
    public function __construct(private User $user) {}

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        return [
            'children' => $this->user->children()
                ->withCount(['lessons', 'lessons as published_count' => fn ($q) => $q->whereNotNull('published_at')])
                ->orderBy('name')
                ->get()
                ->map(fn (Child $child) => [
                    'id' => $child->id,
                    'name' => $child->name,
                    'level' => $child->level,
                    'lessons' => $child->lessons_count,
                    'published' => $child->published_count,
                    'shareUrl' => route('shared.index', $child->share_token),
                ]),
        ];
    }
}
