<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kosten gehören dem Konto, nicht der Lernseite: Sie bleiben, wenn eine Lernseite oder ein Kind
     * gelöscht wird, und verschwinden erst mit dem Konto.
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
