<?php

namespace Tests\Support;

/**
 * Minimaler Prüfer für die Teilmenge von JSON Schema, die die strukturierte Ausgabe verwendet
 * (type, properties, required, additionalProperties, items, anyOf, enum, const).
 * So lässt sich ohne zusätzliches Paket testen, dass Fixtures und Schema zusammenpassen.
 */
class JsonSchema
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public static function errors(mixed $value, array $schema, string $path = '$'): array
    {
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $option) {
                if (self::errors($value, $option, $path) === []) {
                    return [];
                }
            }

            return ["$path passt zu keiner anyOf-Variante"];
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            return ["$path ist nicht ".json_encode($schema['const'])];
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return ["$path ist nicht in enum"];
        }

        $type = $schema['type'] ?? null;

        $typeOk = match ($type) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            null => true,
        };

        if (! $typeOk) {
            return ["$path hat nicht den Typ $type"];
        }

        $errors = [];

        if ($type === 'object') {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $errors[] = "$path.$key fehlt";
                }
            }

            foreach ($value as $key => $item) {
                if (! isset($schema['properties'][$key])) {
                    $errors[] = "$path.$key ist nicht erlaubt";

                    continue;
                }

                $errors = [...$errors, ...self::errors($item, $schema['properties'][$key], "$path.$key")];
            }
        }

        if ($type === 'array' && isset($schema['items'])) {
            foreach ($value as $i => $item) {
                $errors = [...$errors, ...self::errors($item, $schema['items'], "{$path}[$i]")];
            }
        }

        return $errors;
    }

    /**
     * Schlüssel, die die strukturierte Ausgabe nicht unterstützt.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public static function unsupportedKeywords(array $schema, string $path = '$'): array
    {
        $found = [];
        $unsupported = ['minItems', 'maxItems', 'minLength', 'maxLength', 'minimum', 'maximum', 'multipleOf', 'pattern', 'uniqueItems'];

        foreach ($schema as $key => $value) {
            // Property names are not keywords: a field may be called «pattern» or «type»
            if ($key === 'properties' && is_array($value)) {
                foreach ($value as $name => $property) {
                    $found = [...$found, ...self::unsupportedKeywords($property, "$path.properties.$name")];
                }

                continue;
            }

            if (in_array($key, $unsupported, true)) {
                $found[] = "$path.$key";
            }

            if ($key === 'type' && $value === 'object') {
                if (($schema['additionalProperties'] ?? null) !== false) {
                    $found[] = "$path ohne additionalProperties: false";
                }

                if (array_keys($schema['properties'] ?? []) !== ($schema['required'] ?? [])) {
                    $found[] = "$path: nicht alle Felder sind required";
                }
            }

            if (is_array($value) && $key !== 'enum' && $key !== 'required') {
                $found = [...$found, ...self::unsupportedKeywords($value, "$path.$key")];
            }
        }

        return $found;
    }
}
