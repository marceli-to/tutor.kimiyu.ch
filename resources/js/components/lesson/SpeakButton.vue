<script setup lang="ts">
import { Volume2 } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// Reads a foreign word aloud with the browser's speech synthesis (no network).
// Hidden when the browser can't speak or has no voice for the language.
const props = defineProps<{
	text: string;
	// BCP 47, e.g. fr-FR
	lang: string;
}>();

const voice = ref<SpeechSynthesisVoice | null>(null);

// Only the word itself: «parlé (parler)» or «le livre / les livres» reads «parlé» or «le livre»
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

function speak() {
	if (!voice.value) {
		return;
	}

	const utterance = new SpeechSynthesisUtterance(spoken.value);
	utterance.lang = voice.value.lang;
	utterance.voice = voice.value;
	utterance.rate = 0.9;

	window.speechSynthesis.cancel();
	window.speechSynthesis.speak(utterance);
}
</script>

<template>
	<button
		v-if="voice"
		type="button"
		class="inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-full text-ls-muted hover:bg-ls-accent-bg hover:text-ls-accent"
		:aria-label="`«${spoken}» vorlesen`"
		@click="speak"
	>
		<Volume2 class="size-4" aria-hidden="true" />
	</button>
</template>
