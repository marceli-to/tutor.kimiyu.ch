<script setup lang="ts">
import { computed, inject, ref } from 'vue';
import { mathToHtml, rendersMathKey } from '@/lib/math';

// A text of the lesson; in lessons with formulas, TeX between $…$ is rendered with KaTeX
const props = defineProps<{
	text: string;
}>();

const rendersMath = inject(rendersMathKey, ref(false));

const html = computed(() =>
	rendersMath.value && props.text.includes('$')
		? mathToHtml(props.text)
		: null,
);
</script>

<template>
	<!-- mathToHtml escapes the text; only the KaTeX output is HTML -->
	<span v-if="html !== null" v-html="html" />
	<template v-else>{{ text }}</template>
</template>
