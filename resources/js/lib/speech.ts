// One player for the whole page: a new word stops the previous one
let player: HTMLAudioElement | null = null;

function stopAll(): void {
	player?.pause();

	if ('speechSynthesis' in window) {
		window.speechSynthesis.cancel();
	}
}

/**
 * Plays an ElevenLabs clip. Rejects when the file can't be loaded or played.
 */
export function playClip(src: string): Promise<void> {
	stopAll();
	player = new Audio(src);

	return player.play();
}

/**
 * Reads the text with the browser's speech synthesis (no network).
 */
export function speakWithBrowser(
	text: string,
	voice: SpeechSynthesisVoice,
): void {
	stopAll();

	const utterance = new SpeechSynthesisUtterance(text);
	utterance.lang = voice.lang;
	utterance.voice = voice;
	utterance.rate = 0.9;

	window.speechSynthesis.speak(utterance);
}
