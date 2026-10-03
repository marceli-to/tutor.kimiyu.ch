<?php

namespace App\Actions\Children;

use App\Models\Child;

/**
 * New link, e.g. when the old one went to the wrong person. The old link stops working.
 */
class RenewShareLink
{
	public function handle(Child $child): void
	{
		$child->forceFill(['share_token' => Child::newShareToken()])->save();
	}
}
