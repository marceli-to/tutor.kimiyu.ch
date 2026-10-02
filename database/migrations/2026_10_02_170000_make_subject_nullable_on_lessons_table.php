<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Das Fach darf leer bleiben: Die KI erkennt es in der Analyse und speichert es dann.
     */
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('subject')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Lernseiten, deren Fach noch nicht erkannt wurde, bekommen «Allgemein»
        DB::table('lessons')->whereNull('subject')->update(['subject' => 'Allgemein']);

        Schema::table('lessons', function (Blueprint $table) {
            $table->string('subject')->nullable(false)->change();
        });
    }
};
