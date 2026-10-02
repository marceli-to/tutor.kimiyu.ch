<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lessons are soft-deleted, so their costs are kept.
     */
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        // Lost on rollback: the deleted lessons (empty anyway, only title, subject, level, child)
        // and with them their costs, because generations.lesson_id is cascadeOnDelete again after
        // the rollback of user_id.
        DB::table('lessons')->whereNotNull('deleted_at')->delete();

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
