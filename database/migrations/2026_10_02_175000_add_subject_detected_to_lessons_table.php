<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the AI detected the subject (true) or the parents gave it (false).
     * A new analysis detects a detected subject again.
     */
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->boolean('subject_detected')->default(false)->after('subject');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('subject_detected');
        });
    }
};
