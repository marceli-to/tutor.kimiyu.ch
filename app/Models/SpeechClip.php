<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A spoken foreign word from ElevenLabs, shared by all lessons. The MP3 is on the «speech» disk.
 *
 * @property int $id
 * @property string $hash sha256 of language, voice, model and spoken text
 * @property string $lang BCP 47, e.g. fr-FR
 * @property string $voice_id
 * @property string $model
 * @property string $text The spoken text
 * @property int $credits
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['hash', 'lang', 'voice_id', 'model', 'text', 'credits'])]
class SpeechClip extends Model
{
	public function path(): string
	{
		return "{$this->hash}.mp3";
	}

	public function url(): string
	{
		return route('speech.clip', $this->hash);
	}
}
