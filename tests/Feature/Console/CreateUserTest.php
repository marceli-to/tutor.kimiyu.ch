<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a verified user with the given password', function () {
	$this->artisan('users:create')
		->expectsQuestion('Name', 'Peter Muster')
		->expectsQuestion('E-Mail', 'peter@example.ch')
		->expectsQuestion('Passwort (leer lassen, um eines zu erzeugen)', 'Geheim-Passwort-42!')
		->expectsQuestion('Passwort wiederholen', 'Geheim-Passwort-42!')
		->expectsOutputToContain('peter@example.ch')
		->assertSuccessful();

	$user = User::query()->where('email', 'peter@example.ch')->sole();

	expect($user->name)->toBe('Peter Muster')
		->and($user->email_verified_at)->not->toBeNull()
		->and(Hash::check('Geheim-Passwort-42!', $user->password))->toBeTrue();
});

it('generates a password when none is given and prints it', function () {
	$this->artisan('users:create')
		->expectsQuestion('Name', 'Peter Muster')
		->expectsQuestion('E-Mail', 'peter@example.ch')
		->expectsQuestion('Passwort (leer lassen, um eines zu erzeugen)', '')
		->expectsOutputToContain('Passwort:')
		->assertSuccessful();

	expect(User::query()->where('email', 'peter@example.ch')->sole()->email_verified_at)->not->toBeNull();
});

it('refuses an email that is already taken', function () {
	User::factory()->create(['email' => 'peter@example.ch']);

	$this->artisan('users:create')
		->expectsQuestion('Name', 'Peter Muster')
		->expectsQuestion('E-Mail', 'peter@example.ch')
		->expectsQuestion('Passwort (leer lassen, um eines zu erzeugen)', '')
		->assertFailed();

	expect(User::query()->count())->toBe(1);
});

it('refuses passwords that do not match', function () {
	$this->artisan('users:create')
		->expectsQuestion('Name', 'Peter Muster')
		->expectsQuestion('E-Mail', 'peter@example.ch')
		->expectsQuestion('Passwort (leer lassen, um eines zu erzeugen)', 'Geheim-Passwort-42!')
		->expectsQuestion('Passwort wiederholen', 'anders')
		->assertFailed();

	expect(User::query()->count())->toBe(0);
});
