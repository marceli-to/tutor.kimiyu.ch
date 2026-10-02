<?php

namespace App\Http\Controllers;

use App\Actions\Children\CreateChild;
use App\Actions\Children\DeleteChild;
use App\Actions\Children\RenewShareLink;
use App\Actions\Children\UpdateChild;
use App\Http\PageData\ChildProgress;
use App\Http\PageData\ChildrenIndex;
use App\Http\Requests\ChildRequest;
use App\Models\Child;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ChildController extends Controller
{
	public function index(Request $request): Response
	{
		return Inertia::render('children/Index', (new ChildrenIndex($request->user()))->props());
	}

	public function store(ChildRequest $request, CreateChild $createChild): RedirectResponse
	{
		$createChild->handle($request->user(), $request->validated('name'), $request->validated('level'));

		$this->toast('Kind hinzugefügt.');

		return back();
	}

	public function update(ChildRequest $request, Child $child, UpdateChild $updateChild): RedirectResponse
	{
		$updateChild->handle($child, $request->validated('name'), $request->validated('level'));

		$this->toast('Gespeichert.');

		return back();
	}

	/**
	 * Deletes the child with all lessons and progress.
	 */
	public function destroy(Child $child, DeleteChild $deleteChild): RedirectResponse
	{
		Gate::authorize('delete', $child);

		$deleteChild->handle($child);

		$this->toast('Kind und Lernseiten gelöscht.');

		return back();
	}

	/**
	 * Progress: per lesson, what is mastered and what still needs practice.
	 */
	public function progress(Child $child): Response
	{
		Gate::authorize('update', $child);

		return Inertia::render('children/Progress', (new ChildProgress($child))->props());
	}

	/**
	 * New link, e.g. when the old one went to the wrong person. The old link stops working.
	 */
	public function renewLink(Child $child, RenewShareLink $renewShareLink): RedirectResponse
	{
		Gate::authorize('update', $child);

		$renewShareLink->handle($child);

		$this->toast('Neuer Link erstellt. Der alte Link funktioniert nicht mehr.');

		return back();
	}
}
