<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zweck, Umfang und erlaubte Lernmodule. Alte Lernseiten haben keine Liste (null): alle Module erlaubt.
     */
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('purpose', 20)->default('neu')->after('graphics_mode');
            $table->string('scope', 20)->default('normal')->after('purpose');
            $table->json('modules')->nullable()->after('scope');
        });
    }

    public function down(): void
    {
        // Verloren beim Rollback: Zweck, Umfang und erlaubte Lernmodule.
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'scope', 'modules']);
        });
    }
};
