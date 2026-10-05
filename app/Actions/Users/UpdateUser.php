<?php

namespace App\Actions\Users;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUser
{
	use ProfileValidationRules;

	/**
	 * Changes name, email, password (if given) and the owner (email, null for own data).
	 *
	 * @param  array{name: string, email: string, password: ?string, password_confirmation: ?string, owner_email: ?string}  $input
	 */
	public function handle(User $user, array $input): User
	{
		Validator::make($input, [
			...$this->profileRules($user->id),
			'password' => ['nullable', 'string', Password::default(), 'confirmed'],
			'owner_email' => [
				'nullable',
				'email',
				Rule::notIn([$user->email, $input['email']]),
				// Only one level: the owner can't be linked itself
				Rule::exists('users', 'email')->whereNull('owner_id'),
				function (string $attribute, mixed $value, Closure $fail) use ($user) {
					if ($value !== null && User::query()->where('owner_id', $user->id)->exists()) {
						$fail('Andere Konten sind mit diesem Konto verbunden, es kann nicht selbst verbunden werden.');
					}
				},
			],
		], [
			'owner_email.not_in' => 'Ein Konto kann nicht mit sich selbst verbunden werden.',
			'owner_email.exists' => 'Kein Konto mit dieser E-Mail, oder es ist selbst verbunden.',
		])->validate();

		$user->fill([
			'name' => $input['name'],
			'email' => $input['email'],
			'owner_id' => $input['owner_email'] === null ? null : User::query()->where('email', $input['owner_email'])->value('id'),
		]);

		if ($input['password'] !== null) {
			$user->password = $input['password'];
		}

		$user->save();

		return $user;
	}
}
