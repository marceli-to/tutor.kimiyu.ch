<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The subject may stay empty: the AI detects it in the analysis and stores it then.
     */
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('subject')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Lessons whose subject hasn't been detected yet get «Allgemein»
        DB::table('lessons')->whereNull('subject')->update(['subject' => 'Allgemein']);

        Schema::table('lessons', function (Blueprint $table) {
            $table->string('subject')->nullable(false)->change();
        });
    }
};
