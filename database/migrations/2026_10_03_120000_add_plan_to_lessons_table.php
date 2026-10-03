<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * The plan the parents confirm before the expensive steps; existing lessons don't stop for it.
	 */
	public function up(): void
	{
		Schema::table('lessons', function (Blueprint $table) {
			$table->json('plan')->nullable()->after('additions');
			$table->timestamp('plan_confirmed_at')->nullable()->after('plan');
			$table->boolean('review_plan')->default(false)->after('plan_confirmed_at');
		});
	}

	public function down(): void
	{
		Schema::table('lessons', function (Blueprint $table) {
			$table->dropColumn(['plan', 'plan_confirmed_at', 'review_plan']);
		});
	}
};
