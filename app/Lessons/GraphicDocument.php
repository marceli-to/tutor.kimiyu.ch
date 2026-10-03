<?php

namespace App\Lessons;

use App\Models\Lesson;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the standalone HTML document for an interactive graphic.
 *
 * It is shown in an iframe with sandbox="allow-scripts" (without allow-same-origin)
 * and gets a CSP without any network access.
 */
class GraphicDocument
{
	/**
	 * @param  array{pattern: string, description: string, css: string, markup: string, script: string}  $graphic
	 */
	public static function response(Lesson $lesson, array $graphic): Response
	{
		$palette = Palettes::get($lesson->content['meta']['palette'] ?? null);

		$html = view('lesson-graphic', [
			'title' => $lesson->title ?? 'Grafik',
			'graphic' => $graphic,
			'fontFaces' => self::fontFaces(),
			'paletteLight' => self::cssVariables($palette['light']),
			'paletteDark' => self::cssVariables($palette['dark']),
			'baseCss' => File::get(resource_path('lesson/graphic-base.css')),
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
	 * @font-face rules from the font manifest of the Vite plugin, with the WOFF2 files as data: URIs.
	 * So the iframe needs no network access and no CORS headers on the server.
	 */
	private static function fontFaces(): string
	{
		$manifest = public_path('build/fonts-manifest.json');

		if (! File::exists($manifest)) {
			return '';
		}

		return Cache::rememberForever('graphic-font-faces:'.md5_file($manifest), function () use ($manifest) {
			$styles = json_decode(File::get($manifest), true)['style']['familyStyles'] ?? [];

			// The graphic doesn't need italic styles
			$faces = preg_split('/(?=@font-face)/', implode("\n", $styles)) ?: [];
			$faces = array_filter($faces, fn (string $face) => str_starts_with($face, '@font-face') && ! str_contains($face, 'font-style: italic'));

			return implode("\n", array_map(function (string $face) {
				// Keep and embed only the WOFF2 source
				if (! preg_match('#url\("/(build/[^"]+\.woff2)"\)#', $face, $match)) {
					return '';
				}

				$data = base64_encode(File::get(public_path($match[1])));

				return preg_replace('#src: [^;]+;#', 'src: url("data:font/woff2;base64,'.$data.'") format("woff2");', $face);
			}, $faces));
		});
	}
}
