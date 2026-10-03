<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

abstract class Controller
{
	/**
	 * Short feedback as a toast after the next page view.
	 */
	protected function toast(string $message, string $type = 'success'): void
	{
		Inertia::flash('toast', ['type' => $type, 'message' => $message]);
	}
}
