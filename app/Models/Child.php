<?php

namespace App\Models;

use Database\Factories\ChildFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Child profile. The name stays in the app and is never sent to the API.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $level
 * @property string $share_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $lessons_count
 * @property-read int|null $published_count
 */
#[Fillable(['name', 'level'])]
#[Hidden(['share_token'])]
class Child extends Model
{
	/** @use HasFactory<ChildFactory> */
	use HasFactory;

	protected static function booted(): void
	{
		static::creating(function (Child $child) {
			$child->share_token ??= self::newShareToken();
		});
	}

	public static function newShareToken(): string
	{
		return Str::random(40);
	}

	/**
	 * @return BelongsTo<User, $this>
	 */
	public function user(): BelongsTo
	{
		return $this->belongsTo(User::class);
	}

	/**
	 * @return HasMany<Lesson, $this>
	 */
	public function lessons(): HasMany
	{
		return $this->hasMany(Lesson::class);
	}

	/**
	 * @return HasMany<Attempt, $this>
	 */
	public function attempts(): HasMany
	{
		return $this->hasMany(Attempt::class);
	}
}
