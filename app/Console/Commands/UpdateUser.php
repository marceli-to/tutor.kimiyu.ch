<?php

namespace App\Console\Commands;

use App\Actions\Users\UpdateUser as UpdateUserAction;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('users:update')]
#[Description('Changes name, email, password and the linked owner account of an account (asks for everything)')]
class UpdateUser extends Command
{
	public function handle(UpdateUserAction $updateUser): int
	{
		$user = User::query()->with('owner')->firstWhere('email', (string) $this->ask('E-Mail des Kontos'));

		if ($user === null) {
			$this->error('Kein Konto mit dieser E-Mail.');

			return self::FAILURE;
		}

		$name = (string) $this->ask('Name', $user->name);
		$email = (string) $this->ask('E-Mail', $user->email);
		$password = (string) $this->secret('Neues Passwort (leer lassen, um es zu behalten)');
		$confirmation = $password === '' ? null : (string) $this->secret('Passwort wiederholen');
		$owner = (string) $this->ask('Sieht die Daten von (E-Mail, «-» für eigene Daten)', $user->owner->email ?? '-');

		try {
			$user = $updateUser->handle($user, [
				'name' => $name,
				'email' => $email,
				'password' => $password === '' ? null : $password,
				'password_confirmation' => $confirmation,
				'owner_email' => $owner === '-' ? null : $owner,
			]);
		} catch (ValidationException $exception) {
			foreach ($exception->validator->errors()->all() as $error) {
				$this->error($error);
			}

			return self::FAILURE;
		}

		// The owner loaded for the question may have changed
		$user->load('owner');

		$this->info("Konto {$user->email} gespeichert.");
		$this->line($user->owner === null ? 'Sieht die eigenen Daten.' : "Sieht die Daten von {$user->owner->email}.");

		return self::SUCCESS;
	}
}
