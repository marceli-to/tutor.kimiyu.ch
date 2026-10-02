<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Request instead of topic and notes; number of photos and the AI's additions.
     * Old photo lessons have no topic, so even with photo_count 0 they don't count as «from the topic».
     */
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->text('prompt')->nullable()->after('notes');
            $table->unsignedTinyInteger('photo_count')->default(0)->after('prompt');
            $table->json('additions')->nullable()->after('source_summary');
        });
    }

    public function down(): void
    {
        // Lost on rollback: the parents' request, the number of photos and the AI's additions.
        // Lessons from a request only then have neither topic nor request and can't be regenerated.
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['prompt', 'photo_count', 'additions']);
        });
    }
};
