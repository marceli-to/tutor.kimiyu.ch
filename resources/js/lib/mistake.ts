import type { Mistake } from '@/types';

// Mirrors App\Lessons\MistakeAnswer for the immediate feedback; the server decides what is stored.

// Words of a sentence: split on spaces, punctuation stays attached («hoffe,»)
export function words(sentence: string): string[] {
	return sentence
		.trim()
		.split(/\s+/)
		.filter((word) => word !== '');
}

// Punctuation before and after the word itself («hoffe,» → ['', ','])
function surroundings(word: string): [string, string] {
	const match = /^(\p{P}*).*?(\p{P}*)$/su.exec(word);

	return [match?.[1] ?? '', match?.[2] ?? ''];
}

// Right word and correction exactly (case counts); the punctuation around the word may be left out
export function checkMistake(
	mistake: Mistake,
	word: number,
	correction: string,
): boolean {
	const value = correction.trim();

	if (word !== mistake.mistake_word || value === '') {
		return false;
	}

	const [before, after] = surroundings(words(mistake.sentence)[word] ?? '');

	return [
		value,
		before + value,
		value + after,
		before + value + after,
	].includes(mistake.correction.trim());
}

// What the input starts with after tapping a word: the word without the punctuation around it
export function bareWord(word: string): string {
	const [before, after] = surroundings(word);

	return word.slice(before.length, word.length - after.length);
}
