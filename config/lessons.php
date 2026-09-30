<?php

return [

    /*
    | Fake-Modell statt Claude API: liefert die Fixtures aus database/fixtures/lessons.
    | Praktisch für die lokale Entwicklung ohne API-Key.
    */
    'fake_ai' => (bool) env('LESSON_FAKE_AI', false),

    // Zweiter Durchgang, der Quiz-Lösungen, Sortierung und Fakten prüft und korrigiert
    'check_enabled' => (bool) env('LESSON_CHECK_ENABLED', true),

    // Fotos nach erfolgreicher Analyse löschen (revDSG)
    'delete_images' => (bool) env('LESSON_DELETE_IMAGES', true),

    'images' => [
        'max_count' => 4,
        'max_upload_kb' => 12 * 1024,
        // Längere Seite in Pixeln, grössere Bilder bringen der Analyse nichts mehr
        'max_edge' => 1600,
        'jpeg_quality' => 85,
    ],

    'max_tokens' => [
        'analyse' => 48000,
        'reparatur' => 32000,
        'pruefung' => 48000,
        'grafik' => 48000,
    ],

    /*
    | Preise in USD pro Million Tokens, für das Kosten-Log.
    | Quelle: Anthropic-Preisliste, Stand September 2026. Cache-Schreiben kostet 1.25× Input.
    */
    'pricing' => [
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20, 'cache_write' => 5.00],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.20, 'cache_write' => 2.50],
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00, 'cache_read' => 0.10, 'cache_write' => 1.25],
        'claude-fable-5-1' => ['input' => 10.00, 'output' => 50.00, 'cache_read' => 0.25, 'cache_write' => 12.50],
    ],

];
