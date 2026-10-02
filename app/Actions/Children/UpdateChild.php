<?php

namespace App\Actions\Children;

use App\Models\Child;

class UpdateChild
{
    public function handle(Child $child, string $name, ?string $level): void
    {
        $child->update(['name' => $name, 'level' => $level]);
    }
}
