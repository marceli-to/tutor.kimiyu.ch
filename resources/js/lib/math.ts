import type Katex from 'katex';
import { shallowRef } from 'vue';
import type { InjectionKey, Ref } from 'vue';

// Provided by LessonPage: true for math, geometry and science lessons. Elsewhere a «$» stays a dollar sign.
export const rendersMathKey: InjectionKey<Ref<boolean>> = Symbol('rendersMath');

export type MathPart =
	| { kind: 'text'; value: string }
	| { kind: 'math'; value: string; display: boolean };

// $$…$$ for a display formula, $…$ inline, \$ is a literal dollar sign.
// Same delimiters as App\Lessons\ContentValidator::checkTex; an unclosed «$» stays text.
const delimiters = /\\\$|\$\$([\s\S]+?)\$\$|\$([^$]+?)\$/g;

export function splitMath(text: string): MathPart[] {
	const parts: MathPart[] = [];
	let buffer = '';
	let last = 0;

	for (const match of text.matchAll(delimiters)) {
		buffer += text.slice(last, match.index);
		last = match.index + match[0].length;

		if (match[0] === '\\$') {
			buffer += '$';

			continue;
		}

		if (buffer !== '') {
			parts.push({ kind: 'text', value: buffer });
			buffer = '';
		}

		parts.push({
			kind: 'math',
			value: match[1] ?? match[2],
			display: match[1] !== undefined,
		});
	}

	buffer += text.slice(last);

	if (buffer !== '') {
		parts.push({ kind: 'text', value: buffer });
	}

	return parts;
}

function escapeHtml(text: string): string {
	return text
		.replaceAll('&', '&amp;')
		.replaceAll('<', '&lt;')
		.replaceAll('>', '&gt;')
		.replaceAll('"', '&quot;')
		.replaceAll("'", '&#39;');
}

// KaTeX (with its CSS) is large: it is only loaded once a text with a formula is shown.
// Until then MathText shows the plain text.
export const katex = shallowRef<typeof Katex | null>(null);

let loading: Promise<void> | null = null;

export function loadKatex(): Promise<void> {
	loading ??= Promise.all([
		import('katex'),
		import('katex/dist/katex.min.css'),
	]).then(([module]) => {
		katex.value = module.default;
	});

	return loading;
}

// HTML for a text with formulas: text parts escaped, only the KaTeX output is markup.
// Broken TeX shows as red source instead of throwing.
export function mathToHtml(text: string, renderer: typeof Katex): string {
	return splitMath(text)
		.map((part) =>
			part.kind === 'text'
				? escapeHtml(part.value)
				: renderer.renderToString(part.value, {
						displayMode: part.display,
						throwOnError: false,
						strict: 'ignore',
						output: 'html',
					}),
		)
		.join('');
}
