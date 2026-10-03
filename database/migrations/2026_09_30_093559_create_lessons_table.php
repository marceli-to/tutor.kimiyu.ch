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
		Schema::create('lessons', function (Blueprint $table) {
			$table->id();
			$table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
			$table->string('status')->default('draft');
			$table->string('step')->nullable();
			$table->string('title')->nullable();
			$table->string('subject');
			$table->string('level');
			$table->text('notes')->nullable();
			$table->unsignedSmallInteger('schema_version')->nullable();
			$table->json('content')->nullable();
			$table->json('hero_plan')->nullable();
			$table->json('hero')->nullable();
			$table->text('hero_error')->nullable();
			$table->json('check_notes')->nullable();
			$table->text('source_summary')->nullable();
			$table->text('error')->nullable();
			$table->timestamp('published_at')->nullable();
			$table->timestamps();

			$table->index(['child_id', 'subject']);
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('lessons');
	}
};
