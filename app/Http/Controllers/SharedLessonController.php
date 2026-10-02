<?php

namespace App\Http\Controllers;

use App\Actions\Progress\RecordAnswer;
use App\Enums\LessonStatus;
use App\Http\PageData\SharedLessonIndex;
use App\Lessons\LessonView;
use App\Models\Child;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the child sees through its link: only published lessons, read only, without login.
 */
class SharedLessonController extends Controller
{
	public function index(string $token): Response
	{
		$child = $this->child($token);

		return Inertia::render('shared/Index', (new SharedLessonIndex($child))->props());
	}

	public function show(string $token, Lesson $lesson): Response
	{
		$child = $this->child($token);

		abort_unless($lesson->child_id === $child->id && $lesson->status === LessonStatus::Published, 404);

		return Inertia::render('shared/Show', [
			'token' => $token,
			'lesson' => LessonView::page($lesson),
		]);
	}

	/**
	 * One answer of the child. The server checks it itself against the content.
	 */
	public function answer(Request $request, string $token, Lesson $lesson, RecordAnswer $recordAnswer): JsonResponse
	{
		$child = $this->child($token);

		abort_unless($lesson->child_id === $child->id && $lesson->status === LessonStatus::Published, 404);

		$data = $request->validate([
			'module' => ['required', Rule::in(['quiz', 'sorting', 'cloze'])],
			'item_id' => ['required', 'string', 'max:20'],
			'answer' => ['present', 'nullable'],
		]);

		$correct = $recordAnswer->handle($child, $lesson, $data['module'], $data['item_id'], $data['answer']);

		abort_if($correct === null, 422, 'Diese Aufgabe gibt es nicht.');

		return response()->json(['correct' => $correct]);
	}

	private function child(string $token): Child
	{
		// Wrong link: 404 like for a missing page, so nothing is revealed about valid links
		return Child::query()->where('share_token', $token)->firstOrFail();
	}
}
