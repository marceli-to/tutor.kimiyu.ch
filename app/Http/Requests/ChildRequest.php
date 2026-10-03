<?php

namespace App\Http\Requests;

use App\Models\Child;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Name and level of a child, for adding and editing.
 */
class ChildRequest extends FormRequest
{
	/**
	 * Editing: only the parent's own children; before the validation, as before.
	 */
	public function authorize(): bool
	{
		$child = $this->route('child');

		return ! $child instanceof Child || $this->user()->can('update', $child);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function rules(): array
	{
		return [
			'name' => ['required', 'string', 'max:60'],
			'level' => ['nullable', 'string', 'max:60'],
		];
	}

	/**
	 * @return array<string, string>
	 */
	public function messages(): array
	{
		return [
			'name.required' => 'Gib einen Namen ein.',
		];
	}
}
