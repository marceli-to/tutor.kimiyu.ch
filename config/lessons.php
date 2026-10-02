<?php

return [

	/*
	| Fake model instead of the Claude API: returns the fixtures from database/fixtures/lessons.
	| Handy for local development without an API key.
	*/
	'fake_ai' => (bool) env('LESSON_FAKE_AI', false),

	// Second pass that checks and corrects quiz solutions, sorting and facts
	'check_enabled' => (bool) env('LESSON_CHECK_ENABLED', true),

	// Delete photos after a successful analysis (revDSG)
	'delete_images' => (bool) env('LESSON_DELETE_IMAGES', true),

	'images' => [
		'max_count' => 4,
		'max_upload_kb' => 12 * 1024,
		// Longer side in pixels; larger images don't help the analysis any more
		'max_edge' => 1600,
		'jpeg_quality' => 85,
	],

	/*
	| Counts per scope, for the prompts. Keep within the limits of the ContentValidator:
	| at most 4 sections, 3–8 quiz questions, 20 cards, 16 sorting terms.
	*/
	'scope' => [
		'short' => ['sections' => '1–2', 'quiz' => 3, 'flashcards' => '4–6', 'terms' => '6–8', 'gaps' => '3–5'],
		'normal' => ['sections' => '1–3', 'quiz' => 5, 'flashcards' => '5–10', 'terms' => '8–12', 'gaps' => '4–8'],
		'detailed' => ['sections' => '2–4', 'quiz' => 8, 'flashcards' => '8–15', 'terms' => '10–16', 'gaps' => '6–10'],
	],

	/*
	| Subject profile per subject (lower case, as typed by the parents or detected by the AI).
	| Every other subject gets «general». See App\Lessons\Profile.
	*/
	'profiles' => [
		'natur und technik' => 'science',
		'biologie' => 'science',
		'chemie' => 'science',
		'physik' => 'science',
		'mathematik' => 'math',
		'geometrie' => 'geometry',
		'deutsch' => 'german',
		'französisch' => 'languages',
		'englisch' => 'languages',
		'italienisch' => 'languages',
	],

	// Language for the read-aloud button (browser speech synthesis) in a languages lesson, by subject
	'speech_langs' => [
		'französisch' => 'fr-FR',
		'englisch' => 'en-GB',
		'italienisch' => 'it-IT',
	],

	'max_tokens' => [
		'analysis' => 32000,
		'page' => 32000,
		'modules' => 32000,
		'repair' => 32000,
		'check' => 16000,
		'graphic' => 48000,
	],

	/*
	| Model and effort per step. Empty: global default from services.anthropic.
	| Reading photos and building interactive graphics needs Opus; Sonnet manages structured
	| writing and checking from existing material at half the price.
	| Steps without an entry of their own use the part before the hyphen (graphic-repair → graphic).
	*/
	'models' => [
		'analysis' => ['model' => env('LESSON_MODEL_ANALYSIS'), 'effort' => env('LESSON_EFFORT_ANALYSIS')],
		// Text part, the second part of the analysis: reads the same photos, so the same settings
		'page' => ['model' => env('LESSON_MODEL_ANALYSIS'), 'effort' => env('LESSON_EFFORT_ANALYSIS')],
		'modules' => ['model' => env('LESSON_MODEL_MODULES', 'claude-sonnet-5-5'), 'effort' => env('LESSON_EFFORT_MODULES', 'medium')],
		'regenerate-quiz' => ['model' => env('LESSON_MODEL_MODULES', 'claude-sonnet-5-5'), 'effort' => env('LESSON_EFFORT_MODULES', 'medium')],
		'repair' => ['model' => env('LESSON_MODEL_MODULES', 'claude-sonnet-5-5'), 'effort' => env('LESSON_EFFORT_MODULES', 'medium')],
		'check' => ['model' => env('LESSON_MODEL_CHECK', 'claude-sonnet-5-5'), 'effort' => env('LESSON_EFFORT_CHECK', 'medium')],
		'graphic' => ['model' => env('LESSON_MODEL_GRAPHIC'), 'effort' => env('LESSON_EFFORT_GRAPHIC', 'medium')],
	],

	/*
	| Prices in USD per million tokens, for the cost log.
	| Source: Anthropic price list, as of September 2026. Cache writes cost 1.25× input.
	*/
	'pricing' => [
		'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20, 'cache_write' => 5.00],
		'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.20, 'cache_write' => 2.50],
		'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00, 'cache_read' => 0.10, 'cache_write' => 1.25],
		'claude-fable-5-1' => ['input' => 10.00, 'output' => 50.00, 'cache_read' => 0.25, 'cache_write' => 12.50],
	],

];
