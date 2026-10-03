<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Purpose, scope and allowed learning modules. Old lessons have no list (null): all modules allowed.
	 */
	public function up(): void
	{
		Schema::table('lessons', function (Blueprint $table) {
			$table->string('purpose', 20)->default('neu')->after('graphics_mode');
			$table->string('scope', 20)->default('normal')->after('purpose');
			$table->json('modules')->nullable()->after('scope');
		});
	}

	public function down(): void
	{
		// Lost on rollback: purpose, scope and allowed learning modules.
		Schema::table('lessons', function (Blueprint $table) {
			$table->dropColumn(['purpose', 'scope', 'modules']);
		});
	}
};
