import type { Exercise } from '@/types';

// Mirrors App\Lessons\ExerciseAnswer for the immediate feedback; the server decides what is stored.

const EPSILON = 1e-9;

// Same normalisation as App\Lessons\ClozeParser::normalize
function normalize(value: string): string {
	return value.trim().toLowerCase().replace(/\s+/g, ' ');
}

// «1'250,5», «1250.5», «1 250», «1.250.000», «-3,5»: one separator that occurs once is decimal,
// one that repeats separates thousands; with both, the last one is decimal
export function parseNumber(input: string): number | null {
	const value = input
		.replace(/−/g, '-')
		.replace(/[\s  '’]/g, '')
		.replace(/[.,][-–]?$/, '');
	const match = /^([+-]?)([\d.,]*\d)$/.exec(value);

	if (!match) {
		return null;
	}

	const [, sign, digits] = match;
	const commas = digits.split(',').length - 1;
	const dots = digits.split('.').length - 1;
	let decimal: string | null = null;
	let thousands: string | null = null;

	if (commas > 0 && dots > 0) {
		decimal = digits.lastIndexOf(',') > digits.lastIndexOf('.') ? ',' : '.';
		thousands = decimal === ',' ? '.' : ',';
	} else if (commas > 1 || dots > 1) {
		thousands = commas > 1 ? ',' : '.';
	} else if (commas === 1 || dots === 1) {
		decimal = commas === 1 ? ',' : '.';
	}

	const cut = decimal === null ? -1 : digits.indexOf(decimal);
	let integer = cut === -1 ? digits : digits.slice(0, cut);
	const fraction = cut === -1 ? '' : digits.slice(cut + 1);

	if (/[.,]/.test(fraction)) {
		return null;
	}

	if (thousands !== null) {
		const groups = integer.split(thousands);

		if (
			!groups.every((group, i) =>
				(i === 0 ? /^\d{1,3}$/ : /^\d{3}$/).test(group),
			)
		) {
			return null;
		}

		integer = groups.join('');
	}

	return Number(
		`${sign}${integer === '' ? '0' : integer}${fraction !== '' ? `.${fraction}` : ''}`,
	);
}

// «3/4», «6 / 8» or a number such as «0,75»
export function parseFraction(input: string): number | null {
	const match = /^\s*([+\-−]?\d+)\s*\/\s*(\d+)\s*$/.exec(input);

	if (match) {
		const denominator = Number(match[2]);

		return denominator === 0
			? null
			: Number(match[1].replace('−', '-')) / denominator;
	}

	return parseNumber(input);
}

// Case and a final dot don't matter: «Fr.», «fr» and «FR» are the same unit
function unit(value: string): string {
	return normalize(value).replace(/\.+$/, '');
}

function same(
	value: number | null,
	expected: number | null,
	tolerance: number,
): boolean {
	return (
		value !== null &&
		expected !== null &&
		Math.abs(value - expected) <= tolerance + EPSILON
	);
}

function checkNumber(answer: string, exercise: Exercise): boolean {
	// The unit may stand before («Fr. 10.50») or after the number («10.50 Fr.») or be left out
	const match =
		/^\s*(\D*?)\s*([+\-−]?(?:\d|[.,](?=\d))[\d.,'’\s  ]*)(\D*)$/.exec(
			answer,
		);

	if (!match) {
		return false;
	}

	const before = match[1].trim();
	const after = match[3].trim();

	if (before !== '' && after !== '') {
		return false;
	}

	const given = before + after;

	if (given !== '' && unit(given) !== unit(exercise.unit ?? '')) {
		return false;
	}

	return same(
		parseNumber(match[2]),
		parseNumber(exercise.answer),
		exercise.tolerance ?? 0,
	);
}

export function checkExercise(answer: string, exercise: Exercise): boolean {
	switch (exercise.kind) {
		case 'number':
			return checkNumber(answer, exercise);
		case 'fraction':
			return same(
				parseFraction(answer),
				parseFraction(exercise.answer),
				0,
			);
		case 'text':
			return (
				answer.trim() !== '' &&
				normalize(answer) === normalize(exercise.answer)
			);
	}
}
