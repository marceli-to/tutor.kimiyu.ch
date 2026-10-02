<?php

namespace App\Actions\Children;

use App\Models\Child;

/**
 * Neuer Link, z. B. wenn der alte an die falsche Person ging. Der alte Link funktioniert danach nicht mehr.
 */
class RenewShareLink
{
    public function handle(Child $child): void
    {
        $child->forceFill(['share_token' => Child::newShareToken()])->save();
    }
}
