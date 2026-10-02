<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auftrag statt Thema und Hinweise; Anzahl Fotos und Ergänzungen der KI.
     * Alte Foto-Lernseiten haben kein Thema und gelten deshalb auch mit photo_count 0 nicht als «aus dem Thema».
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
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['prompt', 'photo_count', 'additions']);
        });
    }
};
