<?php

namespace App\Lessons\Ai;

use App\Lessons\GraphicPattern;
use App\Lessons\LessonView;
use App\Lessons\Palettes;
use App\Lessons\Profile;
use App\Models\Lesson;
use App\Models\LessonGraphic;
use Database\Factories\LessonFactory;
use Illuminate\Support\Facades\File;

/**
 * Builds the requests for the individual steps.
 *
 * The system prompts (resources/prompts) are the same for every lesson.
 * Everything that changes per lesson is in the user prompt. The child's name is never sent.
 */
class Prompts
{
	/**
	 * First step: check the source, summary, additions and plans for the graphics.
	 * The text part comes in a call of its own (pageRequest); together the schema is too large for the API.
	 *
	 * @param  list<array{mime: string, data: string}>  $images
	 */
	public static function analysis(Lesson $lesson, array $images): ModelRequest
	{
		$system = self::analysisSystem('Fotosynthese', [
			'source' => ['readable' => true, 'problem' => null],
			'subject' => 'Biologie',
			'summary' => '…',
			'additions' => [],
			'graphic_plans' => [[
				'number' => 1,
				'plan' => [
					'pattern' => 'sliders',
					'idea' => 'Ein Blatt im Querschnitt mit Pfeilen für Licht, CO₂ und Wasser (hinein) sowie Sauerstoff und Traubenzucker (hinaus). Drei Regler steuern Licht, CO₂ und Wasser. Die Pfeile hinaus werden so stark wie die knappste Zutat. Eine Anzeige nennt die Leistung und was gerade bremst, ein Satz darunter erklärt es.',
				],
				'note' => null,
			]],
		]);

		return new ModelRequest(
			step: 'analysis',
			system: $system,
			prompt: implode("\n\n", [
				'Schritt 1 von 2: Liefere nur `source`, `subject`, `summary`, `additions` und `graphic_plans`. Den Textteil (`page`) schreibst du im zweiten Schritt.',
				self::sourceLines($lesson, count($images)),
			]),
			schema: Schemas::analysis(),
			maxTokens: config('lessons.max_tokens.analysis'),
			images: $images,
		);
	}

	/**
	 * Second part of the analysis: the text part of the page, with the same photos (so the terms follow the book),
	 * the stored summary, the additions and the plans for the graphics.
	 *
	 * @param  list<array{mime: string, data: string}>  $images
	 */
	public static function pageRequest(Lesson $lesson, array $images): ModelRequest
	{
		$profile = $lesson->resolvedProfile();
		$page = self::page(LessonFactory::fixture($profile->fixture()));

		if (! $profile->allowsExperiments()) {
			unset($page['try_it']);
		}

		$system = self::analysisSystem($page['meta']['topic'], ['page' => $page]);

		$parts = [
			'Schritt 2 von 2: Liefere nur `page`, den Textteil der Lernseite. Quelle, Zusammenfassung, Ergänzungen und die Pläne für die Grafiken stehen fest (unten), halte dich daran.',
			self::sourceLines($lesson, count($images)),
			self::profileSection($profile),
			"Zusammenfassung des Stoffs:\n{$lesson->source_summary}",
		];

		if ($lesson->additions) {
			$parts[] = "Ergänzt (nicht auf den Fotos), diese Bausteine haben `origin: \"added\"`:\n- ".implode("\n- ", $lesson->additions);
		}

		$parts[] = self::pagePlanText($lesson);

		return new ModelRequest(
			step: 'page',
			system: $system,
			prompt: implode("\n\n", $parts),
			schema: Schemas::part('page', $profile),
			maxTokens: config('lessons.max_tokens.page'),
			images: $images,
		);
	}

	/**
	 * Rules shared by both calls of the analysis, with the example for the fields of the respective call.
	 *
	 * @param  array<string, mixed>  $example
	 */
	private static function analysisSystem(string $topic, array $example): string
	{
		return strtr(self::load('analysis'), [
			'{{PALETTEN}}' => self::paletteList(),
			'{{THEMA}}' => $topic,
			'{{BEISPIEL}}' => self::json($example),
		]);
	}

	/**
	 * Source and request, the same for both calls of the analysis.
	 */
	private static function sourceLines(Lesson $lesson, int $count): string
	{
		$photos = $count === 1 ? 'diesem Foto' : "diesen {$count} Fotos";

		$source = match (true) {
			$count === 0 && $lesson->prompt !== null => 'Erstelle eine Lernseite nach dem Auftrag der Eltern. Es gibt keine Fotos, arbeite aus deinem Fachwissen (siehe «Nur ein Auftrag, keine Fotos»).',
			// Old lesson from a topic (before the request), e.g. on a retry
			$count === 0 => "Erstelle eine Lernseite zum Thema «{$lesson->topic}». Es gibt keine Fotos, arbeite aus deinem Fachwissen (siehe «Nur ein Auftrag, keine Fotos»).",
			$lesson->prompt !== null => "Erstelle eine Lernseite aus {$photos}. Die Fotos sind der Rahmen, der Auftrag der Eltern setzt den Fokus (siehe «Fotos und Auftrag»).",
			default => "Erstelle eine Lernseite aus {$photos}.",
		};

		return implode("\n", array_filter([
			$source,
			$count > 1 ? 'Die Fotos sind in der Reihenfolge der Seiten: Foto 1 ist die erste Seite.' : null,
			'',
			// Without a subject the analysis detects it; in the second call it is already set
			'Fach: '.($lesson->subject ?? 'unbekannt, erkenne es aus den Fotos oder dem Auftrag'),
			"Stufe: {$lesson->level}",
			self::purposeLine($lesson),
			self::scopeLine($lesson),
			self::graphicsWish($lesson),
			self::parentInstruction($lesson),
		], fn ($line) => $line !== null));
	}

	/**
	 * Second step: the learning modules from the summary and the text part.
	 *
	 * @param  array<string, mixed>  $page
	 */
	public static function modules(Lesson $lesson, array $page): ModelRequest
	{
		$profile = $lesson->resolvedProfile();
		$system = self::modulesSystem($profile);

		return new ModelRequest(
			step: 'modules',
			system: $system,
			prompt: implode("\n\n", [
				self::context($lesson),
				self::graphicPlansText($lesson, $page),
				"Textteil der Lernseite:\n".self::json($page),
			]),
			schema: Schemas::modulesResult($profile),
			maxTokens: config('lessons.max_tokens.modules'),
		);
	}

	/**
	 * New quiz with other questions; the rest of the page stays.
	 */
	public static function quiz(Lesson $lesson): ModelRequest
	{
		$system = self::modulesSystem($lesson->resolvedProfile());

		$count = self::scopeCounts($lesson)['quiz'];

		return new ModelRequest(
			step: 'regenerate-quiz',
			system: $system,
			prompt: implode("\n\n", [
				self::context($lesson),
				"Erstelle nur ein neues Quiz mit genau {$count} Fragen (IDs q1–q{$count}). Frag andere Aspekte ab oder stell die Fragen anders als im bisherigen Quiz. Die Regeln für das Quiz gelten unverändert.",
				"Bisheriges Quiz:\n".self::json($lesson->content['modules']['quiz']),
				"Textteil der Lernseite:\n".self::json(self::page($lesson->content)),
			]),
			schema: Schemas::quizResult(),
			maxTokens: config('lessons.max_tokens.modules'),
		);
	}

	/**
	 * Rules for the modules with the example of the profile, the offered modules only.
	 */
	private static function modulesSystem(Profile $profile): string
	{
		$fixture = LessonFactory::fixture($profile->fixture());

		return strtr(self::load('modules'), [
			'{{THEMA}}' => $fixture['meta']['topic'],
			'{{BEISPIEL}}' => self::json(['modules' => array_intersect_key($fixture['modules'], array_flip($profile->modules()))]),
		]);
	}

	/**
	 * The profile with its addendum; it takes precedence over the general rules (analysis.md, modules.md).
	 */
	private static function profileSection(Profile $profile): string
	{
		return "Fachprofil: {$profile->label()}\n\n".trim(File::get($profile->promptFile()));
	}

	/**
	 * @param  array<string, mixed>  $content
	 * @param  'page'|'modules'  $part
	 * @param  list<string>  $errors
	 */
	public static function repair(Lesson $lesson, array $content, string $part, array $errors): ModelRequest
	{
		return new ModelRequest(
			step: "repair-{$part}",
			system: self::load('repair'),
			prompt: implode("\n\n", array_filter([
				self::context($lesson),
				// The text part places the graphic blocks, so it needs the plans
				$part === 'page' ? self::graphicPlansText($lesson, $content) : null,
				"Gib diesen Teil korrigiert zurück: {$part}",
				"Diese Fehler müssen behoben werden:\n- ".implode("\n- ", $errors),
				"Ganze Lernseite:\n".self::json($content),
			])),
			schema: Schemas::part($part, $lesson->resolvedProfile()),
			maxTokens: config('lessons.max_tokens.repair'),
		);
	}

	/**
	 * Check of the whole page in one call. The answer contains only corrections.
	 *
	 * @param  array<string, mixed>  $content
	 */
	public static function check(Lesson $lesson, array $content): ModelRequest
	{
		return new ModelRequest(
			step: 'check',
			system: self::load('check'),
			prompt: implode("\n\n", [
				self::context($lesson),
				"Lernseite:\n".self::json($content),
			]),
			schema: Schemas::checkResult(),
			maxTokens: config('lessons.max_tokens.check'),
		);
	}

	/**
	 * Text part of a page: everything except the modules.
	 *
	 * @param  array<string, mixed>  $content
	 * @return array<string, mixed>
	 */
	public static function page(array $content): array
	{
		unset($content['modules']);

		return $content;
	}

	/**
	 * One graphic from its plan. Graphic 1 is at the top, 2 and 3 in the section with their block.
	 */
	public static function graphic(Lesson $lesson, LessonGraphic $graphic): ModelRequest
	{
		// One example is enough as a benchmark; each further one only costs input.
		// The profile's own graphic where its fixture has one, else photosynthesis.
		$fixture = $lesson->resolvedProfile()->fixture();

		if (! File::exists(database_path("fixtures/lessons/{$fixture}.graphic.json"))) {
			$fixture = 'fotosynthese';
		}

		$example = '### '.LessonFactory::fixture($fixture)['meta']['title']."\n\n```json\n".self::json(LessonFactory::fixture("{$fixture}.graphic"))."\n```";

		$place = null;

		if ($graphic->position > 1) {
			$title = self::graphicSection($lesson->content ?? [], $graphic->position);
			$place = 'Diese Grafik steht '.($title !== null ? "im Abschnitt «{$title}»" : 'weiter unten auf der Seite').' neben dem Text, nicht oben auf der Seite.';
		}

		return new ModelRequest(
			step: 'graphic',
			system: strtr(self::load('graphic'), ['{{BEISPIELE}}' => $example]),
			prompt: implode("\n\n", array_filter([
				self::planTextForGraphic($graphic),
				$place,
				// The child sees the graphic: no origin and no list of additions
				self::context($lesson, forChild: true),
				"Inhalt der Lernseite:\n".self::json(LessonView::withoutOrigin($lesson->content ?? [])),
			])),
			schema: Schemas::graphic(),
			maxTokens: config('lessons.max_tokens.graphic'),
		);
	}

	/**
	 * @param  array<string, mixed>  $result
	 * @param  list<string>  $errors
	 */
	public static function graphicRepair(LessonGraphic $graphic, array $result, array $errors): ModelRequest
	{
		return new ModelRequest(
			step: 'graphic-repair',
			system: self::load('graphic-repair'),
			prompt: implode("\n\n", [
				self::planTextForGraphic($graphic),
				"Diese Probleme müssen behoben werden:\n- ".implode("\n- ", $errors),
				"Bisheriges Ergebnis:\n".self::json($result),
			]),
			schema: Schemas::graphic(),
			maxTokens: config('lessons.max_tokens.graphic'),
		);
	}

	private static function planTextForGraphic(LessonGraphic $graphic): string
	{
		return "Plan für die Grafik:\nMuster: {$graphic->plan['pattern']}\n{$graphic->plan['idea']}";
	}

	/**
	 * What the parents chose for the graphics, for the analysis.
	 */
	private static function graphicsWish(Lesson $lesson): string
	{
		return match ($lesson->graphics_mode) {
			'none' => 'Grafiken: keine (von den Eltern abgewählt)',
			'custom' => implode("\n", [
				'Grafiken nach Wunsch der Eltern:',
				...$lesson->graphics()->get()->map(function (LessonGraphic $graphic) {
					$pattern = GraphicPattern::tryFrom((string) $graphic->pattern);

					return "Grafik {$graphic->position}: {$graphic->request}"
						.($pattern ? " (Muster: {$pattern->value} – {$pattern->label()})" : '');
				}),
			]),
			default => 'Grafiken: höchstens eine, wenn ein Muster den Stoff sichtbar macht',
		};
	}

	/**
	 * The plans from the first step, for the text part: where «graphic» blocks belong
	 * and whether there is a graphic 1 for «meta.instructions» and «try_it».
	 */
	private static function pagePlanText(Lesson $lesson): string
	{
		$graphics = $lesson->graphics()->whereNotNull('plan')->orderBy('position')->get();

		$plans = $graphics->map(fn (LessonGraphic $graphic) => 'Grafik '.$graphic->position
			.($graphic->position === 1 ? ' (oben)' : ' (Baustein `graphic` im passenden Abschnitt)')
			.": Muster {$graphic->plan['pattern']}\n{$graphic->plan['idea']}");

		$lines = $plans->isNotEmpty() ? ["Geplante Grafiken:\n\n".$plans->implode("\n\n")] : [];

		if (! $graphics->contains('position', 1)) {
			$lines[] = $lesson->resolvedProfile()->allowsExperiments()
				? 'Diese Seite hat keine Grafik 1: `try_it` ist null, `meta.instructions` sagt in einem Satz, worum es geht.'
				: 'Diese Seite hat keine Grafik 1: `meta.instructions` sagt in einem Satz, worum es geht.';
		}

		$lines[] = $graphics->contains(fn (LessonGraphic $graphic) => $graphic->position > 1)
			? 'Setze für jede geplante Grafik ab Nummer 2 genau einen Baustein `graphic` mit ihrer `number`, für andere Nummern keinen.'
			: 'Kein Baustein `graphic`.';

		return implode("\n\n", $lines);
	}

	/**
	 * The planned graphics with their place on the page, for the modules (and the repair of the text part).
	 *
	 * @param  array<string, mixed>  $page
	 */
	private static function graphicPlansText(Lesson $lesson, array $page): string
	{
		$plans = $lesson->graphics()->whereNotNull('plan')->get()->map(function (LessonGraphic $graphic) use ($page) {
			$place = $graphic->position === 1
				? 'oben'
				: (($title = self::graphicSection($page, $graphic->position)) !== null ? "im Abschnitt «{$title}»" : 'weiter unten auf der Seite');

			return "Grafik {$graphic->position} ({$place}): Muster {$graphic->plan['pattern']}\n{$graphic->plan['idea']}";
		});

		return $plans->isNotEmpty()
			? "Geplante Grafiken:\n\n".$plans->implode("\n\n")
			: 'Diese Seite hat keine interaktive Grafik. Keine Quizfrage darf sich auf eine Grafik beziehen.';
	}

	/**
	 * Title of the section that holds the block for graphic $number.
	 *
	 * @param  array<string, mixed>  $content
	 */
	public static function graphicSection(array $content, int $number): ?string
	{
		foreach ($content['sections'] ?? [] as $section) {
			foreach ($section['blocks'] ?? [] as $block) {
				if (($block['type'] ?? null) === 'graphic' && ($block['number'] ?? null) === $number) {
					return $section['title'] ?? null;
				}
			}
		}

		return null;
	}

	/**
	 * Part shared by all steps after the analysis: subject, level, purpose, scope, modules, request, summary and additions.
	 * For parts the child sees ($forChild, e.g. the graphic) without additions and without the «(ergänzt)» mark;
	 * purpose, scope and modules don't matter there and are left out, and of the profile only the label.
	 */
	private static function context(Lesson $lesson, bool $forChild = false): string
	{
		$profile = $lesson->resolvedProfile();

		$header = implode("\n", array_filter([
			"Fach: {$lesson->subject}",
			$forChild ? "Fachprofil: {$profile->label()}" : null,
			"Stufe: {$lesson->level}",
			$forChild ? null : self::purposeLine($lesson),
			$forChild ? null : self::scopeLine($lesson),
			$forChild ? null : self::modulesLine($lesson),
			// So that «keine Fotos → immer added» in modules.md applies
			$lesson->isFromTopic() ? 'Quelle: keine Fotos (Auftrag oder Thema)' : null,
			self::parentInstruction($lesson),
		], fn ($line) => $line !== null));

		$summary = $forChild
			? (string) preg_replace('/\s*\(ergänzt\)/u', '', (string) $lesson->source_summary)
			: $lesson->source_summary;

		$parts = array_filter([$header, $forChild ? null : self::profileSection($profile), "Zusammenfassung des Stoffs:\n{$summary}"]);

		if ($lesson->additions && ! $forChild) {
			$parts[] = "Ergänzt (nicht auf den Fotos):\n- ".implode("\n- ", $lesson->additions);
		}

		return implode("\n\n", $parts);
	}

	private static function purposeLine(Lesson $lesson): string
	{
		return 'Zweck: '.($lesson->purpose === 'exam' ? 'Prüfungsvorbereitung' : 'Neuer Stoff');
	}

	private static function scopeLine(Lesson $lesson): string
	{
		$label = match ($lesson->scope) {
			'short' => 'kurz',
			'detailed' => 'ausführlich',
			default => $lesson->scope,
		};

		return "Umfang: {$label} (".self::scopeCounts($lesson)['sections'].' Abschnitte)';
	}

	/**
	 * The allowed learning modules with the counts for the scope; old lessons without a list allow all.
	 */
	private static function modulesLine(Lesson $lesson): string
	{
		$counts = self::scopeCounts($lesson);

		$labels = [
			'quiz' => "Quiz (genau {$counts['quiz']} Fragen)",
			'sorting' => "Sortierspiel ({$counts['terms']} Begriffe)",
			'flashcards' => "Karteikarten ({$counts['flashcards']} Karten)",
			'cloze' => "Lückentext ({$counts['gaps']} Lücken)",
			'exercises' => "Übungen (genau {$counts['exercises']} Aufgaben)",
			'find_the_mistake' => "Fehler finden ({$counts['mistakes']} Sätze)",
		];

		return 'Erlaubte Lernmodule: '.implode(', ', array_intersect_key($labels, array_flip($lesson->generatedModules())));
	}

	/**
	 * @return array{sections: string, quiz: int, flashcards: string, terms: string, gaps: string, exercises: string, mistakes: string}
	 */
	private static function scopeCounts(Lesson $lesson): array
	{
		return config("lessons.scope.{$lesson->scope}") ?? config('lessons.scope.normal');
	}

	/**
	 * The parents' request; old lessons have notes instead.
	 */
	private static function parentInstruction(Lesson $lesson): ?string
	{
		return match (true) {
			$lesson->prompt !== null => "Auftrag der Eltern: {$lesson->prompt}",
			(bool) $lesson->notes => "Hinweise der Eltern: {$lesson->notes}",
			default => null,
		};
	}

	private static function paletteList(): string
	{
		return collect(Palettes::all())
			->map(fn (array $palette, string $key) => "- `{$key}`: {$palette['label']}")
			->implode("\n");
	}

	private static function load(string $name): string
	{
		return File::get(resource_path("prompts/{$name}.md"));
	}

	private static function json(mixed $value): string
	{
		return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}
}
