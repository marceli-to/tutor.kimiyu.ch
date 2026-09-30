<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

abstract class Controller
{
    /**
     * Kurze Rückmeldung als Toast nach der nächsten Seitenanzeige.
     */
    protected function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }
}
