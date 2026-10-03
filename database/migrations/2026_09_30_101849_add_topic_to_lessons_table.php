<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Run the migrations.
	 */
	public function up(): void
	{
		Schema::table('lessons', function (Blueprint $table) {
			// Topic as the source when there are no photos
			$table->string('topic')->nullable()->after('level');
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::table('lessons', function (Blueprint $table) {
			$table->dropColumn('topic');
		});
	}
};
