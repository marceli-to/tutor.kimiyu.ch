<?php

namespace App\Lessons;

/**
 * Prüft ein generiertes Hero-Fragment, bevor es gespeichert wird.
 *
 * Die eigentliche Sicherheit kommt vom sandboxed iframe und der CSP. Diese Prüfung fängt
 * Fehler ab, die die Grafik kaputt machen würden, und gibt der Reparatur klare Hinweise.
 */
class HeroValidator
{
    public const MAX_BYTES = [
        'css' => 30_000,
        'markup' => 80_000,
        'script' => 40_000,
    ];

    // Namespaces in SVG und createElementNS sind keine Netzwerkadressen
    private const ALLOWED_URLS = [
        'http://www.w3.org/2000/svg',
        'http://www.w3.org/1999/xlink',
        'http://www.w3.org/1999/xhtml',
    ];

    /**
     * @param  array<string, mixed>  $hero
     * @return list<string>
     */
    public static function errors(array $hero): array
    {
        $errors = [];

        if (HeroPattern::tryFrom((string) ($hero['pattern'] ?? '')) === null) {
            $errors[] = 'Das Feld «pattern» enthält kein bekanntes Hero-Muster.';
        }

        if (trim((string) ($hero['description'] ?? '')) === '') {
            $errors[] = 'Die Beschreibung fehlt.';
        }

        foreach (self::MAX_BYTES as $field => $max) {
            $value = $hero[$field] ?? null;

            if (! is_string($value)) {
                $errors[] = "Das Feld «{$field}» fehlt.";

                continue;
            }

            if (strlen($value) > $max) {
                $errors[] = "Das Feld «{$field}» ist zu gross (höchstens ".number_format($max / 1000).' KB).';
            }

            $withoutNamespaces = str_ireplace(self::ALLOWED_URLS, '', $value);
            if (preg_match('#(https?:)?//[a-z0-9-]+\.[a-z]#i', $withoutNamespaces)) {
                $errors[] = "Im Feld «{$field}» steht eine externe Adresse. Die Grafik darf nichts nachladen.";
            }
        }

        $markup = (string) ($hero['markup'] ?? '');
        $css = (string) ($hero['css'] ?? '');
        $script = (string) ($hero['script'] ?? '');

        if (trim($markup) === '') {
            $errors[] = 'Das Markup ist leer.';
        }

        if (preg_match('#<\s*(script|style|iframe|object|embed|link|meta|base|form)\b#i', $markup, $match)) {
            $errors[] = "Das Markup enthält ein <{$match[1]}>-Element. Skript und CSS gehören in die Felder «script» und «css».";
        }

        if (preg_match('#\son[a-z]+\s*=#i', $markup)) {
            $errors[] = 'Das Markup enthält Inline-Event-Handler (onclick usw.). Ereignisse im Skript mit addEventListener verbinden.';
        }

        if (stripos($css, '</style') !== false) {
            $errors[] = 'Das CSS enthält «</style».';
        }

        if (stripos($script, '</script') !== false) {
            $errors[] = 'Das Skript enthält «</script».';
        }

        if (preg_match('#\b(fetch|XMLHttpRequest|WebSocket|EventSource|importScripts|localStorage|sessionStorage|document\.cookie)\b#', $script, $match)) {
            $errors[] = "Das Skript verwendet «{$match[1]}». Die Grafik darf weder Netzwerk noch Speicher nutzen.";
        }

        if (str_contains($markup.$script.$css, 'ß')) {
            $errors[] = 'Die Grafik enthält ein «ß». In der Schweiz schreibt man «ss».';
        }

        return $errors;
    }
}
