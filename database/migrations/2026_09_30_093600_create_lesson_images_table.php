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
		Schema::create('lesson_images', function (Blueprint $table) {
			$table->id();
			$table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
			$table->string('path');
			$table->string('mime_type');
			$table->unsignedInteger('size');
			$table->unsignedTinyInteger('position')->default(0);
			$table->timestamps();
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('lesson_images');
	}
};
