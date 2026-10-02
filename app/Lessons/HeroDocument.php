<?php

namespace App\Lessons;

use App\Models\Lesson;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baut das eigenständige HTML-Dokument für eine interaktive Grafik.
 *
 * Es wird in einem iframe mit sandbox="allow-scripts" (ohne allow-same-origin) angezeigt
 * und bekommt eine CSP ohne jeden Netzwerkzugriff.
 */
class HeroDocument
{
    /**
     * @param  array{pattern: string, description: string, css: string, markup: string, script: string}  $graphic
     */
    public static function response(Lesson $lesson, array $graphic): Response
    {
        $palette = Palettes::get($lesson->content['meta']['palette'] ?? null);

        $html = view('lesson-hero', [
            'title' => $lesson->title ?? 'Grafik',
            'hero' => $graphic,
            'fontFaces' => self::fontFaces(),
            'paletteLight' => self::cssVariables($palette['light']),
            'paletteDark' => self::cssVariables($palette['dark']),
            'baseCss' => File::get(resource_path('lesson/hero-base.css')),
        ])->render();

        return response($html)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Content-Security-Policy', self::contentSecurityPolicy())
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public static function contentSecurityPolicy(): string
    {
        return implode('; ', [
            "default-src 'none'",
            "script-src 'unsafe-inline'",
            "style-src 'unsafe-inline'",
            'font-src data:',
            'img-src data:',
            "base-uri 'none'",
            "form-action 'none'",
            "frame-ancestors 'self'",
        ]);
    }

    /**
     * @param  array<string, string>  $colors
     */
    private static function cssVariables(array $colors): string
    {
        return collect($colors)
            ->map(fn (string $value, string $name) => "--{$name}:{$value};")
            ->implode('');
    }

    /**
     * @font-face-Regeln aus dem Font-Manifest des Vite-Plugins, mit den WOFF2-Dateien als data:-URIs.
     * So braucht das iframe keinen Netzwerkzugriff und keine CORS-Header auf dem Server.
     */
    private static function fontFaces(): string
    {
        $manifest = public_path('build/fonts-manifest.json');

        if (! File::exists($manifest)) {
            return '';
        }

        return Cache::rememberForever('hero-font-faces:'.md5_file($manifest), function () use ($manifest) {
            $styles = json_decode(File::get($manifest), true)['style']['familyStyles'] ?? [];

            // Kursive Schnitte braucht die Grafik nicht
            $faces = preg_split('/(?=@font-face)/', implode("\n", $styles)) ?: [];
            $faces = array_filter($faces, fn (string $face) => str_starts_with($face, '@font-face') && ! str_contains($face, 'font-style: italic'));

            return implode("\n", array_map(function (string $face) {
                // Nur die WOFF2-Quelle behalten und einbetten
                if (! preg_match('#url\("/(build/[^"]+\.woff2)"\)#', $face, $match)) {
                    return '';
                }

                $data = base64_encode(File::get(public_path($match[1])));

                return preg_replace('#src: [^;]+;#', 'src: url("data:font/woff2;base64,'.$data.'") format("woff2");', $face);
            }, $faces));
        });
    }
}
