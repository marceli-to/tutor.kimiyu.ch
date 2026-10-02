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

    /*
    | Anzahlen pro Umfang, für die Prompts. Grenzen des ContentValidator einhalten:
    | höchstens 4 Abschnitte, 3–8 Quizfragen, 20 Karten, 16 Sortier-Begriffe.
    */
    'scope' => [
        'kurz' => ['abschnitte' => '1–2', 'quiz' => 3, 'karten' => '4–6', 'begriffe' => '6–8', 'luecken' => '3–5'],
        'normal' => ['abschnitte' => '1–3', 'quiz' => 5, 'karten' => '5–10', 'begriffe' => '8–12', 'luecken' => '4–8'],
        'ausfuehrlich' => ['abschnitte' => '2–4', 'quiz' => 8, 'karten' => '8–15', 'begriffe' => '10–16', 'luecken' => '6–10'],
    ],

    'max_tokens' => [
        'analyse' => 32000,
        'seite' => 32000,
        'module' => 32000,
        'reparatur' => 32000,
        'pruefung' => 16000,
        'grafik' => 48000,
    ],

    /*
    | Modell und Effort pro Schritt. Leer: globaler Standard aus services.anthropic.
    | Fotos lesen und interaktive Grafiken bauen braucht Opus; strukturiertes Schreiben
    | und Prüfen aus vorhandenem Stoff schafft Sonnet zum halben Preis.
    | Schritte ohne eigenen Eintrag nehmen den Teil vor dem Bindestrich (grafik-reparatur → grafik).
    */
    'models' => [
        'analyse' => ['model' => env('LESSON_MODEL_ANALYSE'), 'effort' => env('LESSON_EFFORT_ANALYSE')],
        // Textteil, der zweite Teil der Analyse: liest dieselben Fotos, deshalb dieselben Einstellungen
        'seite' => ['model' => env('LESSON_MODEL_ANALYSE'), 'effort' => env('LESSON_EFFORT_ANALYSE')],
        'module' => ['model' => env('LESSON_MODEL_MODULE', 'claude-sonnet-5-5'), 'effort' => env('LESSON_EFFORT_MODULE', 'medium')],
        'neu-quiz' => ['model' => env('LESSON_MODEL_MODULE', 'claude-sonnet-5-5'), 'effort' => env('LESSON_EFFORT_MODULE', 'medium')],
        'reparatur' => ['model' => env('LESSON_MODEL_MODULE', 'claude-sonnet-5-5'), 'effort' => env('LESSON_EFFORT_MODULE', 'medium')],
        'pruefung' => ['model' => env('LESSON_MODEL_PRUEFUNG', 'claude-sonnet-5-5'), 'effort' => env('LESSON_EFFORT_PRUEFUNG', 'medium')],
        'grafik' => ['model' => env('LESSON_MODEL_GRAFIK'), 'effort' => env('LESSON_EFFORT_GRAFIK', 'medium')],
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
