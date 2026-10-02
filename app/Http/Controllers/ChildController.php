<?php

namespace App\Http\Controllers;

use App\Actions\Children\CreateChild;
use App\Actions\Children\DeleteChild;
use App\Actions\Children\RenewShareLink;
use App\Actions\Children\UpdateChild;
use App\Enums\LessonStatus;
use App\Http\Requests\ChildRequest;
use App\Lessons\Progress;
use App\Models\Child;
use App\Models\Lesson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ChildController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('children/Index', [
            'children' => $request->user()->children()
                ->withCount(['lessons', 'lessons as published_count' => fn ($q) => $q->whereNotNull('published_at')])
                ->orderBy('name')
                ->get()
                ->map(fn (Child $child) => [
                    'id' => $child->id,
                    'name' => $child->name,
                    'level' => $child->level,
                    'lessons' => $child->lessons_count,
                    'published' => $child->published_count,
                    'shareUrl' => route('shared.index', $child->share_token),
                ]),
        ]);
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
     * Löscht das Kind mit allen Lernseiten und dem Lernstand.
     */
    public function destroy(Child $child, DeleteChild $deleteChild): RedirectResponse
    {
        Gate::authorize('delete', $child);

        $deleteChild->handle($child);

        $this->toast('Kind und Lernseiten gelöscht.');

        return back();
    }

    /**
     * Lernstand: pro Lernseite, was sitzt und was noch geübt werden muss.
     */
    public function progress(Child $child): Response
    {
        Gate::authorize('update', $child);

        $lessons = $child->lessons()
            ->whereNotNull('content')
            ->whereIn('status', [LessonStatus::Review, LessonStatus::Published])
            ->latest()
            ->get();

        $summaries = Progress::summaries($child, $lessons);
        $lastActivity = $child->attempts()->latest('created_at')->value('created_at');

        return Inertia::render('children/Progress', [
            'child' => [
                'id' => $child->id,
                'name' => $child->name,
                'lastActivity' => $lastActivity ? Carbon::parse($lastActivity)->diffForHumans() : null,
            ],
            'lessons' => $lessons->map(fn (Lesson $lesson) => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'subject' => $lesson->subjectLabel(),
                'emoji' => $lesson->content['meta']['emoji'] ?? null,
                'published' => $lesson->status === LessonStatus::Published,
                ...$summaries[$lesson->id],
            ])->values(),
        ]);
    }

    /**
     * Neuer Link, z. B. wenn der alte an die falsche Person ging. Der alte Link funktioniert danach nicht mehr.
     */
    public function renewLink(Child $child, RenewShareLink $renewShareLink): RedirectResponse
    {
        Gate::authorize('update', $child);

        $renewShareLink->handle($child);

        $this->toast('Neuer Link erstellt. Der alte Link funktioniert nicht mehr.');

        return back();
    }
}
