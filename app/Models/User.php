<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property int|null $owner_id
 * @property-read int $account_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'owner_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
	/** @use HasFactory<UserFactory> */
	use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

	/**
	 * Get the attributes that should be cast.
	 *
	 * @return array<string, string>
	 */
	protected function casts(): array
	{
		return [
			'email_verified_at' => 'datetime',
			'password' => 'hashed',
			'two_factor_confirmed_at' => 'datetime',
		];
	}

	/**
	 * The account whose data a linked account works on.
	 *
	 * @return BelongsTo<User, $this>
	 */
	public function owner(): BelongsTo
	{
		return $this->belongsTo(User::class, 'owner_id');
	}

	/**
	 * Id that owns the data: the owner's for a linked account, else the own.
	 *
	 * @return Attribute<int, never>
	 */
	protected function accountId(): Attribute
	{
		return Attribute::get(fn (): int => $this->owner_id ?? $this->id);
	}

	/**
	 * Children of the account, so a linked account sees and creates the owner's.
	 *
	 * @return HasMany<Child, $this>
	 */
	public function children(): HasMany
	{
		return $this->hasMany(Child::class, 'user_id', 'account_id');
	}
}
