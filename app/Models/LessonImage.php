<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uploaded photo of a book page. Stored on the private disk and deleted after the analysis.
 *
 * @property int $id
 * @property int $lesson_id
 * @property string $path
 * @property string $mime_type
 * @property int $size
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['path', 'mime_type', 'size', 'position'])]
class LessonImage extends Model
{
	/**
	 * @return BelongsTo<Lesson, $this>
	 */
	public function lesson(): BelongsTo
	{
		return $this->belongsTo(Lesson::class);
	}
}
