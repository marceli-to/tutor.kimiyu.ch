<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * A linked account works on the children, lessons and costs of its owner.
	 */
	public function up(): void
	{
		Schema::table('users', function (Blueprint $table) {
			$table->foreignId('owner_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
		});
	}

	public function down(): void
	{
		Schema::table('users', function (Blueprint $table) {
			$table->dropConstrainedForeignId('owner_id');
		});
	}
};
