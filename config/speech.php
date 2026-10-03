<?php

return [

	/*
	| ElevenLabs reads the foreign words of language lessons aloud. Without a key, or without a voice
	| for the language, the page uses the browser voice.
	*/
	'key' => env('ELEVENLABS_API_KEY'),

	'model' => env('ELEVENLABS_MODEL', 'eleven_v4'),

	'output_format' => 'mp3_44100_64',

	/*
	| Voice per language (first part of the BCP 47 code). The free plan can only use the premade voices
	| via the API; library voices need a paid plan. Default «Alice» (premade, speaks French).
	*/
	'voices' => [
		'fr' => env('ELEVENLABS_VOICE_FR', 'Xb7hH8MSUJpSbSDYk0k2'),
		'en' => env('ELEVENLABS_VOICE_EN'),
		'it' => env('ELEVENLABS_VOICE_IT'),
	],

	// Models that accept «language_code»; eleven_multilingual_v2 rejects it
	'language_code_models' => ['eleven_v4', 'eleven_v4_turbo', 'eleven_v3', 'eleven_flash_v2_5', 'eleven_turbo_v2_5'],

	// Credits per character, measured 2026-10-03 (the API reports no cost)
	'credits_per_character' => [
		'eleven_v4' => 1.0,
		'eleven_multilingual_v2' => 1.0,
		'eleven_flash_v2_5' => 0.5,
	],

	// Guard against runaway content: no more characters per lesson and run
	'max_characters_per_lesson' => 1500,

	// For the cost log; 0 on the free plan
	'price_per_1000_characters' => (float) env('ELEVENLABS_PRICE_PER_1000_CHARACTERS', 0),

];
