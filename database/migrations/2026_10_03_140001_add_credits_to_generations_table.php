<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * ElevenLabs bills credits (about one per character), not tokens. Null for Claude calls.
	 */
	public function up(): void
	{
		Schema::table('generations', function (Blueprint $table) {
			$table->unsignedInteger('credits')->nullable()->after('cache_write_tokens');
		});
	}

	public function down(): void
	{
		Schema::table('generations', function (Blueprint $table) {
			$table->dropColumn('credits');
		});
	}
};
