<?php

namespace App\Http\Requests;

use App\Lessons\HeroPattern;
use App\Models\Lesson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLessonRequest extends FormRequest
{
    /**
     * Übergang bis Teil 3c, Task 4: Das Formular schickt Zweck, Umfang und Module noch nicht.
     * Fehlen die Felder ganz, gelten die Standardwerte. Mit Task 4 wieder entfernen.
     */
    protected function prepareForValidation(): void
    {
        $defaults = ['purpose' => 'neu', 'scope' => 'normal', 'modules' => Lesson::MODULES];

        $this->merge(array_diff_key($defaults, $this->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'child_id' => [
                'required_without:child_name',
                'nullable',
                Rule::exists('children', 'id')->where('user_id', $this->user()->id),
            ],
            'child_name' => ['required_without:child_id', 'nullable', 'string', 'max:60'],
            'subject' => ['required', 'string', 'max:60'],
            'level' => ['required', 'string', 'max:60'],
            'graphics_mode' => ['required', Rule::in(['none', 'auto', 'custom'])],
            // Wünsche der Eltern, nur bei «Selbst beschreiben»
            'graphics' => ['exclude_unless:graphics_mode,custom', 'required', 'array', 'min:1', 'max:3'],
            'graphics.*.beschreibung' => ['exclude_unless:graphics_mode,custom', 'required', 'string', 'max:500'],
            'graphics.*.muster' => ['exclude_unless:graphics_mode,custom', 'nullable', Rule::enum(HeroPattern::class)],
            'purpose' => ['required', Rule::in(Lesson::PURPOSES)],
            'scope' => ['required', Rule::in(Lesson::SCOPES)],
            // Erlaubte Lernmodule: ein Quiz in der Liste kommt immer, die anderen nur, wenn sie zum Stoff passen
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => ['distinct', Rule::in(Lesson::MODULES)],
            // Fotos sind der verbindliche Rahmen, der Auftrag sagt, was daraus werden soll; eines von beiden genügt
            'prompt' => ['nullable', 'string', 'max:1000'],
            'images' => ['required_without:prompt', 'array', 'max:'.config('lessons.images.max_count')],
            'images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('lessons.images.max_upload_kb')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'child_id.required_without' => 'Wähle ein Kind aus.',
            'child_name.required_without' => 'Gib den Namen des Kindes ein.',
            'subject.required' => 'Gib das Fach an.',
            'level.required' => 'Gib die Stufe an.',
            'graphics_mode' => 'Wähle aus, ob und welche Grafiken die Seite bekommt.',
            'purpose' => 'Wähle den Zweck der Lernseite.',
            'scope' => 'Wähle den Umfang der Lernseite.',
            'modules.required' => 'Wähle mindestens ein Lernmodul.',
            'modules.array' => 'Wähle mindestens ein Lernmodul.',
            'modules.min' => 'Wähle mindestens ein Lernmodul.',
            'modules.*.in' => 'Wähle die Lernmodule aus der Liste.',
            'modules.*.distinct' => 'Jedes Lernmodul nur einmal.',
            'prompt.max' => 'Der Auftrag darf höchstens 1000 Zeichen lang sein.',
            'graphics.required' => 'Beschreib mindestens eine Grafik.',
            'graphics.min' => 'Beschreib mindestens eine Grafik.',
            'graphics.max' => 'Höchstens 3 Grafiken.',
            'graphics.*.beschreibung.required' => 'Beschreib, was die Grafik zeigen soll.',
            'graphics.*.beschreibung.max' => 'Die Beschreibung einer Grafik darf höchstens 500 Zeichen lang sein.',
            'graphics.*.muster' => 'Wähle ein Muster aus der Liste.',
            'images.required_without' => 'Lade mindestens ein Foto hoch oder schreib einen Auftrag.',
            'images.max' => 'Höchstens :max Fotos pro Lernseite.',
            'images.*.mimes' => 'Nur Fotos im Format JPEG, PNG oder WebP.',
            'images.*.max' => 'Ein Foto ist zu gross (höchstens 12 MB).',
        ];
    }
}
