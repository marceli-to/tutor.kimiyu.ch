<?php

namespace App\Lessons;

use Illuminate\Support\Facades\File;

/**
 * Getestete Themen-Paletten aus resources/lesson/palettes.json.
 * Dieselbe Datei wird im Frontend importiert.
 */
class Palettes
{
    public const DEFAULT = 'petrol';

    /** @var array<string, array{label: string, light: array<string, string>, dark: array<string, string>}>|null */
    private static ?array $palettes = null;

    /**
     * @return array<string, array{label: string, light: array<string, string>, dark: array<string, string>}>
     */
    public static function all(): array
    {
        return self::$palettes ??= json_decode(
            File::get(resource_path('lesson/palettes.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return array{label: string, light: array<string, string>, dark: array<string, string>}
     */
    public static function get(?string $key): array
    {
        return self::all()[$key] ?? self::all()[self::DEFAULT];
    }
}
