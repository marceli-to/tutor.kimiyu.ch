<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lernseiten werden weich gelöscht, damit ihre Kosten erhalten bleiben.
     */
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        // Verloren beim Rollback: die gelöschten Lernseiten (ohnehin leer, nur Titel, Fach, Stufe, Kind)
        // und mit ihnen ihre Kosten, weil generations.lesson_id nach dem Rollback von user_id wieder
        // cascadeOnDelete ist.
        DB::table('lessons')->whereNotNull('deleted_at')->delete();

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
