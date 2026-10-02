<?php

namespace App\Http\Requests;

use App\Lessons\HeroPattern;
use App\Models\Lesson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLessonRequest extends FormRequest
{
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
            // Leer: Die KI erkennt das Fach in der Analyse
            'subject' => ['nullable', 'string', 'max:60'],
            // Leer: Die Stufe kommt vom Kind; nur nötig, wenn das Kind keine hat oder neu ist
            'level' => [Rule::requiredIf(fn () => $this->childLevel() === null), 'nullable', 'string', 'max:60'],
            'graphics_mode' => ['required', Rule::in(['none', 'auto', 'custom'])],
            // Wünsche der Eltern, nur bei «Selbst beschreiben»
            'graphics' => ['exclude_unless:graphics_mode,custom', 'required', 'array', 'min:1', 'max:3'],
            'graphics.*.description' => ['exclude_unless:graphics_mode,custom', 'required', 'string', 'max:500'],
            'graphics.*.pattern' => ['exclude_unless:graphics_mode,custom', 'nullable', Rule::enum(HeroPattern::class)],
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
     * Stufe des gewählten Kindes, null für ein neues Kind oder ein Kind ohne Stufe.
     */
    public function childLevel(): ?string
    {
        if (! $this->filled('child_id')) {
            return null;
        }

        $level = $this->user()->children()->whereKey($this->integer('child_id'))->value('level');

        return is_string($level) && trim($level) !== '' ? trim($level) : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'child_id.required_without' => 'Wähle ein Kind aus.',
            'child_name.required_without' => 'Gib den Namen des Kindes ein.',
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
            'graphics.*.description.required' => 'Beschreib, was die Grafik zeigen soll.',
            'graphics.*.description.max' => 'Die Beschreibung einer Grafik darf höchstens 500 Zeichen lang sein.',
            'graphics.*.pattern' => 'Wähle ein Muster aus der Liste.',
            'images.required_without' => 'Lade mindestens ein Foto hoch oder schreib einen Auftrag.',
            'images.max' => 'Höchstens :max Fotos pro Lernseite.',
            'images.*.mimes' => 'Nur Fotos im Format JPEG, PNG oder WebP.',
            'images.*.max' => 'Ein Foto ist zu gross (höchstens 12 MB).',
        ];
    }
}
