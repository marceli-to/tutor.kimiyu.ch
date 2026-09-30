<?php

namespace App\Http\Requests;

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
            'subject' => ['required', 'string', 'max:60'],
            'level' => ['required', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
            'images' => ['required', 'array', 'min:1', 'max:'.config('lessons.images.max_count')],
            'images.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('lessons.images.max_upload_kb')],
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
            'notes.max' => 'Die Hinweise dürfen höchstens 500 Zeichen lang sein.',
            'images.required' => 'Lade mindestens ein Foto hoch.',
            'images.min' => 'Lade mindestens ein Foto hoch.',
            'images.max' => 'Höchstens :max Fotos pro Lernseite.',
            'images.*.mimes' => 'Nur Fotos im Format JPEG, PNG oder WebP.',
            'images.*.max' => 'Ein Foto ist zu gross (höchstens 12 MB).',
        ];
    }
}
