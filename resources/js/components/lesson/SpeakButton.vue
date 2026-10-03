<script setup lang="ts">
import { Volume2 } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { playClip, speakWithBrowser } from '@/lib/speech';

// Reads a foreign word aloud: the ElevenLabs clip if there is one, otherwise the browser's speech
// synthesis. Hidden without a clip when the browser can't speak or has no voice for the language.
const props = defineProps<{
	text: string;
	// BCP 47, e.g. fr-FR
	lang: string;
	// ElevenLabs clip
	src?: string | null;
}>();

const voice = ref<SpeechSynthesisVoice | null>(null);

// Only the word itself: «parlé (parler)» or «le livre / les livres» reads «parlé» or «le livre»
// (same rule as Texts::spokenText() in PHP, which names the clips)
const spoken = computed(() => props.text.split(/[(/]/)[0].trim() || props.text);

function findVoice() {
	const voices = window.speechSynthesis.getVoices();
	const base = props.lang.split('-')[0].toLowerCase();

	voice.value =
		voices.find((v) => v.lang.toLowerCase() === props.lang.toLowerCase()) ??
		voices.find((v) => v.lang.toLowerCase().split(/[-_]/)[0] === base) ??
		null;
}

onMounted(() => {
	if (!('speechSynthesis' in window)) {
		return;
	}

	findVoice();
	// Many browsers load the voices only after the first call
	window.speechSynthesis.addEventListener('voiceschanged', findVoice);
});

onBeforeUnmount(() => {
	if ('speechSynthesis' in window) {
		window.speechSynthesis.removeEventListener('voiceschanged', findVoice);
	}
});

function speakWithVoice() {
	if (voice.value) {
		speakWithBrowser(spoken.value, voice.value);
	}
}

function speak() {
	if (props.src) {
		playClip(props.src).catch((error: unknown) => {
			// Interrupted by the next word: that one plays
			if (error instanceof DOMException && error.name === 'AbortError') {
				return;
			}

			// File missing or not playable: the browser voice, if there is one
			speakWithVoice();
		});

		return;
	}

	speakWithVoice();
}
</script>

<template>
	<button
		v-if="src || voice"
		type="button"
		class="inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-full text-ls-muted hover:bg-ls-accent-bg hover:text-ls-accent"
		:aria-label="`«${spoken}» vorlesen`"
		@click="speak"
	>
		<Volume2 class="size-4" aria-hidden="true" />
	</button>
</template>
