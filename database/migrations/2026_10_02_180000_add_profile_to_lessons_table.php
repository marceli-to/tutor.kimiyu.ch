<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Subject profile the parents chose; null: derived from the subject (also for old lessons).
	 */
	public function up(): void
	{
		Schema::table('lessons', function (Blueprint $table) {
			$table->string('profile', 20)->nullable()->after('subject_detected');
		});
	}

	public function down(): void
	{
		Schema::table('lessons', function (Blueprint $table) {
			$table->dropColumn('profile');
		});
	}
};
