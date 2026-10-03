<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\UpdateLessonContent;
use App\Http\PageData\EditLessonPage;
use App\Models\Lesson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Parents correct texts, quiz questions and solutions before they publish the page.
 */
class LessonContentController extends Controller
{
	public function edit(Lesson $lesson): Response
	{
		Gate::authorize('update', $lesson);

		abort_unless($lesson->isEditable(), 404);

		return Inertia::render('lessons/Edit', (new EditLessonPage($lesson))->props());
	}

	public function update(Request $request, Lesson $lesson, UpdateLessonContent $updateContent): RedirectResponse
	{
		Gate::authorize('update', $lesson);

		abort_unless($lesson->isEditable(), 404);

		$request->validate([
			'content' => ['required', 'array'],
			'clozeMarkup' => ['nullable', 'string', 'max:5000'],
		]);

		$updateContent->handle($lesson, $request->input('content'), $request->input('clozeMarkup'));

		$this->toast('Gespeichert.');

		return back();
	}
}
