export function shuffle<T>(items: readonly T[]): T[] {
	const result = items.slice();

	for (let i = result.length - 1; i > 0; i--) {
		const j = Math.floor(Math.random() * (i + 1));
		[result[i], result[j]] = [result[j], result[i]];
	}

	return result;
}

// Same thresholds as in the template
export function resultMessage(score: number, total: number): string {
	if (score === total) {
		return 'Alles richtig. Bereit für die Prüfung!';
	}

	if (score >= Math.ceil(total * 0.6)) {
		return 'Gut gemacht. Schau dir die Fehler nochmals an.';
	}

	return 'Lies den Teil oben nochmals durch und versuch es erneut.';
}

// Must match App\Lessons\ClozeParser::normalize
function normalizeAnswer(value: string, caseSensitive = false): string {
	const trimmed = value.trim();

	return (caseSensitive ? trimmed : trimmed.toLowerCase()).replace(
		/\s+/g,
		' ',
	);
}

// Must match App\Lessons\ClozeParser::check: correct as normalized, almost if only the accents differ
export function checkAnswer(
	value: string,
	solutions: string[],
	caseSensitive = false,
): { result: 'right' | 'almost' | 'wrong'; solution: string } {
	const answer = normalizeAnswer(value, caseSensitive);
	const exact = solutions.find(
		(s) => normalizeAnswer(s, caseSensitive) === answer,
	);

	if (exact !== undefined) {
		return { result: 'right', solution: exact };
	}

	const bare = withoutAccents(answer);
	const almost =
		bare === ''
			? undefined
			: solutions.find(
					(s) =>
						withoutAccents(normalizeAnswer(s, caseSensitive)) ===
						bare,
				);

	return almost !== undefined
		? { result: 'almost', solution: almost }
		: { result: 'wrong', solution: solutions[0] ?? '' };
}

// NFD, then drop the combining marks (not NFKD, so «CO₂» stays apart from «CO2»)
function withoutAccents(value: string): string {
	return value.normalize('NFD').replace(/\p{Mn}/gu, '');
}

// Static class names per category, so Tailwind finds them
export const categoryClasses = {
	cat1: {
		text: 'text-ls-cat1',
		bg: 'bg-ls-cat1-bg',
		border: 'border-ls-cat1',
		hover: 'hover:bg-ls-cat1-bg',
	},
	cat2: {
		text: 'text-ls-cat2',
		bg: 'bg-ls-cat2-bg',
		border: 'border-ls-cat2',
		hover: 'hover:bg-ls-cat2-bg',
	},
	cat3: {
		text: 'text-ls-cat3',
		bg: 'bg-ls-cat3-bg',
		border: 'border-ls-cat3',
		hover: 'hover:bg-ls-cat3-bg',
	},
} as const;
