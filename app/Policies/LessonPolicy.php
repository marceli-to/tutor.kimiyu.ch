<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\User;

class LessonPolicy
{
    public function view(User $user, Lesson $lesson): bool
    {
        return $lesson->child->user_id === $user->id;
    }

    public function update(User $user, Lesson $lesson): bool
    {
        return $this->view($user, $lesson);
    }
}
