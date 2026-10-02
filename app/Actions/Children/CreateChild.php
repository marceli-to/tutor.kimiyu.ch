<?php

namespace App\Actions\Children;

use App\Models\Child;
use App\Models\User;

class CreateChild
{
	public function handle(User $user, string $name, ?string $level): Child
	{
		return $user->children()->create(['name' => $name, 'level' => $level]);
	}
}
