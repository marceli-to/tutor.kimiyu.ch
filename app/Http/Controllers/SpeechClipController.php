<?php

namespace App\Http\Controllers;

use App\Models\SpeechClip;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Plays a spoken foreign word. No login: children use their shared link, and a clip holds only a single word.
 */
class SpeechClipController extends Controller
{
	public function __invoke(string $hash): StreamedResponse
	{
		$clip = SpeechClip::where('hash', $hash)->firstOrFail();
		$disk = Storage::disk('speech');

		abort_unless($disk->exists($clip->path()), 404);

		// The hash includes text, voice and model: a clip never changes
		return $disk->response($clip->path(), null, [
			'Content-Type' => 'audio/mpeg',
			'Cache-Control' => 'public, max-age=31536000, immutable',
		]);
	}
}
