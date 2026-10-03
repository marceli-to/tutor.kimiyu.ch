<script setup lang="ts">
import { computed, inject, ref, watchEffect } from 'vue';
import { katex, loadKatex, mathToHtml, rendersMathKey } from '@/lib/math';

// A text of the lesson; in lessons with formulas, TeX between $…$ is rendered with KaTeX.
// The plain text shows until KaTeX has loaded. Outside a lesson page (overviews) `math` says whether to render.
const props = withDefaults(
	defineProps<{
		text: string;
		math?: boolean;
	}>(),
	{ math: undefined },
);

const rendersMath = inject(rendersMathKey, ref(false));

const hasMath = computed(
	() => (props.math ?? rendersMath.value) && props.text.includes('$'),
);

watchEffect(() => {
	if (hasMath.value) {
		loadKatex();
	}
});

const html = computed(() =>
	hasMath.value && katex.value !== null
		? mathToHtml(props.text, katex.value)
		: null,
);
</script>

<template>
	<!-- mathToHtml escapes the text; only the KaTeX output is HTML -->
	<span v-if="html !== null" v-html="html" />
	<template v-else>{{ text }}</template>
</template>
