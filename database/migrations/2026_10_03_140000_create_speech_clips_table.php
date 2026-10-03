<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Spoken foreign words from ElevenLabs. Shared by all lessons and accounts: a word is generated
	 * once per language, voice and model. The MP3 lives on the «speech» disk as {hash}.mp3.
	 */
	public function up(): void
	{
		Schema::create('speech_clips', function (Blueprint $table) {
			$table->id();
			// sha256 of language, voice, model and spoken text
			$table->string('hash', 64)->unique();
			$table->string('lang');
			$table->string('voice_id');
			$table->string('model');
			// The spoken text, e.g. «parlé» for «parlé (parler)»
			$table->string('text');
			$table->unsignedInteger('credits');
			$table->timestamps();
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('speech_clips');
	}
};
