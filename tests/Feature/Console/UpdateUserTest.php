<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
	$this->owner = User::factory()->create(['email' => 'm@example.ch']);
	$this->user = User::factory()->create(['name' => 'Peter', 'email' => 'peter@example.ch', 'password' => 'alt']);
});

function updateUser(array $answers)
{
	$command = test()->artisan('users:update')
		->expectsQuestion('E-Mail des Kontos', 'peter@example.ch')
		->expectsQuestion('Name', $answers['name'] ?? 'Peter')
		->expectsQuestion('E-Mail', $answers['email'] ?? 'peter@example.ch')
		->expectsQuestion('Neues Passwort (leer lassen, um es zu behalten)', $answers['password'] ?? '');

	if (($answers['password'] ?? '') !== '') {
		$command->expectsQuestion('Passwort wiederholen', $answers['confirmation'] ?? $answers['password']);
	}

	return $command->expectsQuestion('Sieht die Daten von (E-Mail, «-» für eigene Daten)', $answers['owner'] ?? '-');
}

it('links an account to the owner and keeps the rest', function () {
	updateUser(['owner' => 'm@example.ch'])
		->expectsOutputToContain('Sieht die Daten von m@example.ch.')
		->assertSuccessful();

	$user = $this->user->fresh();

	expect($user->owner_id)->toBe($this->owner->id)
		->and($user->name)->toBe('Peter')
		->and(Hash::check('alt', $user->password))->toBeTrue();
});

it('changes name, email and password and removes the link', function () {
	$this->user->update(['owner_id' => $this->owner->id]);

	updateUser(['name' => 'Peter Muster', 'email' => 'pm@example.ch', 'password' => 'Neu-Passwort-42!'])
		->expectsOutputToContain('Sieht die eigenen Daten.')
		->assertSuccessful();

	$user = $this->user->fresh();

	expect($user->name)->toBe('Peter Muster')
		->and($user->email)->toBe('pm@example.ch')
		->and($user->owner_id)->toBeNull()
		->and(Hash::check('Neu-Passwort-42!', $user->password))->toBeTrue();
});

it('fails for an unknown account', function () {
	$this->artisan('users:update')
		->expectsQuestion('E-Mail des Kontos', 'niemand@example.ch')
		->assertFailed();
});

it('refuses invalid changes', function (array $answers) {
	updateUser($answers)->assertFailed();

	expect($this->user->fresh()->owner_id)->toBeNull()
		->and($this->user->fresh()->email)->toBe('peter@example.ch');
})->with([
	'taken email' => [['email' => 'm@example.ch']],
	'passwords differ' => [['password' => 'Neu-Passwort-42!', 'confirmation' => 'anders']],
	'unknown owner' => [['owner' => 'niemand@example.ch']],
	'own account as owner' => [['owner' => 'peter@example.ch']],
]);

it('refuses chains of linked accounts', function () {
	$linked = User::factory()->create(['email' => 'linked@example.ch', 'owner_id' => $this->owner->id]);

	// The owner of others can't be linked, and nobody can be linked to a linked account
	User::factory()->create(['owner_id' => $this->user->id]);
	updateUser(['owner' => 'm@example.ch'])->assertFailed();

	User::query()->where('owner_id', $this->user->id)->delete();
	updateUser(['owner' => $linked->email])->assertFailed();

	expect($this->user->fresh()->owner_id)->toBeNull();
});
