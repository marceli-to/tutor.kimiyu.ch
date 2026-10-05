<?php

namespace App\Policies;

use App\Models\Child;
use App\Models\User;

class ChildPolicy
{
	public function update(User $user, Child $child): bool
	{
		return $child->user_id === $user->account_id;
	}

	public function delete(User $user, Child $child): bool
	{
		return $this->update($user, $child);
	}
}
