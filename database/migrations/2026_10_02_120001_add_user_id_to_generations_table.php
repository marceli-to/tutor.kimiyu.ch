<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * Costs belong to the account, not the lesson: they stay when a lesson or a child
	 * is deleted, and only disappear with the account.
	 */
	public function up(): void
	{
		Schema::table('generations', function (Blueprint $table) {
			$table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
		});

		DB::table('generations')->update([
			'user_id' => DB::table('lessons')
				->join('children', 'children.id', '=', 'lessons.child_id')
				->whereColumn('lessons.id', 'generations.lesson_id')
				->select('children.user_id'),
		]);

		Schema::table('generations', function (Blueprint $table) {
			$table->foreignId('user_id')->nullable(false)->change();
			$table->dropForeign(['lesson_id']);
		});

		Schema::table('generations', function (Blueprint $table) {
			$table->foreignId('lesson_id')->nullable()->change();
			$table->foreign('lesson_id')->references('id')->on('lessons')->nullOnDelete();
		});
	}

	public function down(): void
	{
		// Lost on rollback: costs without a lesson (lesson deleted with the child) and the link
		// of the costs to the account (user_id). Afterwards costs disappear with the lesson again.
		DB::table('generations')->whereNull('lesson_id')->delete();

		Schema::table('generations', function (Blueprint $table) {
			$table->dropForeign(['lesson_id']);
		});

		Schema::table('generations', function (Blueprint $table) {
			$table->foreignId('lesson_id')->nullable(false)->change();
			$table->foreign('lesson_id')->references('id')->on('lessons')->cascadeOnDelete();
			$table->dropConstrainedForeignId('user_id');
		});
	}
};
