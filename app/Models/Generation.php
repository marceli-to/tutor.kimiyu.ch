<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One API call (analysis, graphic, check, repair) with token usage and cost.
 * Belongs to the account: if the lesson is deleted with the child, the entry stays with lesson_id = null.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $lesson_id
 * @property string $step
 * @property string $model
 * @property string $status
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property string $cost_usd
 * @property int|null $duration_ms
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'step', 'model', 'status', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'cost_usd', 'duration_ms', 'error'])]
class Generation extends Model
{
	/**
	 * Soft-deleted lessons too, so the cost page can still name them.
	 *
	 * @return BelongsTo<Lesson, $this>
	 */
	public function lesson(): BelongsTo
	{
		return $this->belongsTo(Lesson::class)->withTrashed();
	}

	/**
	 * @return BelongsTo<User, $this>
	 */
	public function user(): BelongsTo
	{
		return $this->belongsTo(User::class);
	}
}
