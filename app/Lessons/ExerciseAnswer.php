<?php

namespace App\Lessons;

/**
 * Checks the answer to a math exercise (module «exercises»). The frontend mirrors it in
 * resources/js/lib/exercise.ts for the immediate feedback; this check is the one that counts.
 */
class ExerciseAnswer
{
	public const KINDS = ['number', 'fraction', 'text'];

	// Rounding noise of floats, e.g. 0.1 + 0.2
	private const EPSILON = 1e-9;

	/**
	 * @param  array<string, mixed>  $entry  one task of the module
	 */
	public static function check(array $entry, string $answer): AnswerResult
	{
		$solution = (string) ($entry['answer'] ?? '');

		$correct = match ($entry['kind'] ?? null) {
			'number' => self::checkNumber($answer, $solution, (float) ($entry['tolerance'] ?? 0), $entry['unit'] ?? null),
			'fraction' => self::same(self::fraction($answer), self::fraction($solution), 0.0),
			'text' => trim($answer) !== '' && ClozeParser::normalize($answer) === ClozeParser::normalize($solution),
			default => false,
		};

		return $correct ? AnswerResult::Correct : AnswerResult::Wrong;
	}

	/**
	 * Whether a stored solution can be compared at all (the validator rejects the others).
	 */
	public static function isCheckable(string $kind, string $solution): bool
	{
		return match ($kind) {
			'number' => self::number($solution) !== null,
			'fraction' => self::fraction($solution) !== null,
			'text' => trim($solution) !== '',
			default => false,
		};
	}

	/**
	 * A number as children write it: «1'250,5», «1250.5», «1 250», «1.250.000», «-3,5». One separator
	 * that occurs once is the decimal separator; one that repeats separates thousands. With both, the last one is decimal.
	 */
	public static function number(string $value): ?float
	{
		$value = (string) preg_replace("/[\\s\x{00A0}\x{202F}'’]/u", '', str_replace('−', '-', $value));
		// «12.» or «12.–» as on price tags
		$value = (string) preg_replace('/[.,][-–]?$/u', '', $value);

		if (! preg_match('/^([+-]?)([\d.,]*\d)$/', $value, $match)) {
			return null;
		}

		[, $sign, $digits] = $match;
		$commas = substr_count($digits, ',');
		$dots = substr_count($digits, '.');

		[$decimal, $thousands] = match (true) {
			$commas > 0 && $dots > 0 => strrpos($digits, ',') > strrpos($digits, '.') ? [',', '.'] : ['.', ','],
			$commas > 1 => [null, ','],
			$dots > 1 => [null, '.'],
			$commas === 1 => [',', null],
			$dots === 1 => ['.', null],
			default => [null, null],
		};

		[$integer, $fraction] = $decimal !== null ? explode($decimal, $digits, 2) : [$digits, ''];

		if (str_contains($fraction, ',') || str_contains($fraction, '.')) {
			return null;
		}

		if ($thousands !== null) {
			$groups = explode($thousands, $integer);

			foreach ($groups as $i => $group) {
				if (! preg_match($i === 0 ? '/^\d{1,3}$/' : '/^\d{3}$/', $group)) {
					return null;
				}
			}

			$integer = implode('', $groups);
		}

		return (float) ($sign.($integer === '' ? '0' : $integer).($fraction !== '' ? '.'.$fraction : ''));
	}

	/**
	 * A fraction («3/4», «6 / 8»), a mixed number («1 1/2») or a number («0,75») as its value.
	 */
	public static function fraction(string $value): ?float
	{
		if (preg_match('#^\s*([+\-−]?)(?:(\d+)\s+)?(\d+)\s*/\s*(\d+)\s*$#u', $value, $match)) {
			$denominator = (int) $match[4];

			if ($denominator === 0) {
				return null;
			}

			$value = (int) $match[2] + (int) $match[3] / $denominator;

			return in_array($match[1], ['-', '−'], true) ? -$value : $value;
		}

		return self::number($value);
	}

	/**
	 * The unit may stand before («Fr. 10.50») or after the number («10.50 Fr.», «375km», «24 cm2») or be left out.
	 */
	private static function checkNumber(string $answer, string $solution, float $tolerance, ?string $unit): bool
	{
		if (! preg_match("/^\\s*(\\D*?)\\s*([+\\-−]?(?:\\d|[.,](?=\\d))[\\d.,'’\\s\x{00A0}\x{202F}]*)(\\D*(?:(?<=\\p{L})[23])?)$/u", $answer, $match)) {
			return false;
		}

		$before = trim($match[1]);
		$after = trim($match[3]);

		if ($before !== '' && $after !== '') {
			return false;
		}

		$given = $before.$after;

		if ($given !== '' && self::unit($given) !== self::unit((string) $unit)) {
			return false;
		}

		return self::same(self::number($match[2]), self::number($solution), $tolerance);
	}

	/**
	 * Case and a final dot don't matter: «Fr.», «fr» and «FR» are the same unit; «cm2» stands for «cm²».
	 */
	private static function unit(string $unit): string
	{
		return str_replace(['²', '³'], ['2', '3'], rtrim(ClozeParser::normalize($unit), '.'));
	}

	private static function same(?float $value, ?float $expected, float $tolerance): bool
	{
		return $value !== null && $expected !== null && abs($value - $expected) <= $tolerance + self::EPSILON;
	}
}
