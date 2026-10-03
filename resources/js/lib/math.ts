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
// Never loaded during SSR: the SSR server keeps it between requests, so later pages would be
// rendered with formulas while the browser hydrates with plain text (hydration mismatch).
export const katex = shallowRef<typeof Katex | null>(null);

let loading: Promise<void> | null = null;

export function loadKatex(): Promise<void> {
	if (import.meta.env.SSR) {
		return Promise.resolve();
	}

	loading ??= Promise.all([
		import('katex'),
		import('katex/dist/katex.min.css'),
	]).then(([module]) => {
		katex.value = module.default;
	});

	return loading;
}

// «3x+1» needs brackets as part of a fraction written on one line
function grouped(term: string): string {
	return /[+\-−\s]/.test(term.trim()) ? `(${term.trim()})` : term.trim();
}

// A text with formulas as plain text, for places without HTML such as the browser tab:
// «$\frac{2x}{4} \cdot 3$» becomes «2x/4 · 3». Rough on purpose; the page itself renders KaTeX.
export function mathToPlain(text: string): string {
	return splitMath(text)
		.map((part) =>
			part.kind === 'text'
				? part.value
				: part.value
						.replace(
							/\\[dt]?frac\{([^{}]*)\}\{([^{}]*)\}/g,
							(_, top: string, bottom: string) =>
								`${grouped(top)}/${grouped(bottom)}`,
						)
						.replace(/\\[,;:! ]/g, ' ')
						.replace(/\\cdot/g, '·')
						.replace(/\\times/g, '×')
						.replace(/\\neq/g, '≠')
						.replace(/\\le(q)?\b/g, '≤')
						.replace(/\\ge(q)?\b/g, '≥')
						.replace(/\\[a-zA-Z]+/g, '')
						.replace(/[{}]/g, '')
						.replace(/\s+/g, ' ')
						.trim(),
		)
		.join('');
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
