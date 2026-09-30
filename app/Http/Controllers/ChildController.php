<?php

namespace App\Http\Controllers;

use App\Lessons\LessonGenerator;
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

    public function store(Request $request): RedirectResponse
    {
        $request->user()->children()->create($this->validated($request));

        $this->toast('Kind hinzugefügt.');

        return back();
    }

    public function update(Request $request, Child $child): RedirectResponse
    {
        Gate::authorize('update', $child);

        $child->update($this->validated($request));

        $this->toast('Gespeichert.');

        return back();
    }

    /**
     * Löscht das Kind mit allen Lernseiten und dem Lernstand.
     */
    public function destroy(Child $child, LessonGenerator $generator): RedirectResponse
    {
        Gate::authorize('delete', $child);

        foreach ($child->lessons as $lesson) {
            $generator->deleteImages($lesson);
        }

        $child->delete();

        $this->toast('Kind und Lernseiten gelöscht.');

        return back();
    }

    /**
     * Neuer Link, z. B. wenn der alte an die falsche Person ging. Der alte Link funktioniert danach nicht mehr.
     */
    public function renewLink(Child $child): RedirectResponse
    {
        Gate::authorize('update', $child);

        $child->forceFill(['share_token' => Child::newShareToken()])->save();

        $this->toast('Neuer Link erstellt. Der alte Link funktioniert nicht mehr.');

        return back();
    }

    /**
     * @return array{name: string, level: string|null}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'level' => ['nullable', 'string', 'max:60'],
        ], [
            'name.required' => 'Gib einen Namen ein.',
        ]);

        return ['name' => trim($data['name']), 'level' => isset($data['level']) ? trim($data['level']) : null];
    }
}
