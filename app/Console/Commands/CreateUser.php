<?php

namespace App\Console\Commands;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Signature('users:create')]
#[Description('Creates a verified account (registration is off); asks for name, email and password (or generates one)')]
class CreateUser extends Command
{
	public function handle(CreateNewUser $createNewUser): int
	{
		$name = (string) $this->ask('Name');
		$email = (string) $this->ask('E-Mail');
		$password = (string) $this->secret('Passwort (leer lassen, um eines zu erzeugen)');
		$generated = $password === '';

		if ($generated) {
			// Production rules want mixed case, which Str::password() doesn't guarantee
			do {
				$password = Str::password(16);
			} while (! preg_match('/[a-z]/', $password) || ! preg_match('/[A-Z]/', $password));
			$confirmation = $password;
		} else {
			$confirmation = (string) $this->secret('Passwort wiederholen');
		}

		try {
			$user = $createNewUser->create([
				'name' => $name,
				'email' => $email,
				'password' => $password,
				'password_confirmation' => $confirmation,
			]);
		} catch (ValidationException $exception) {
			foreach ($exception->validator->errors()->all() as $error) {
				$this->error($error);
			}

			return self::FAILURE;
		}

		$user->markEmailAsVerified();

		$this->info("Konto {$user->email} angelegt.");

		if ($generated) {
			$this->line("Passwort: {$password}");
		}

		return self::SUCCESS;
	}
}
