<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
	inheritAttrs: false,
});

type Props = {
	className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
	<!-- Open book -->
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		:class="className"
		v-bind="$attrs"
	>
		<path
			d="M2 5.5C2 4.7 2.7 4 3.5 4H9c1.2 0 2.3.5 3 1.3C12.7 4.5 13.8 4 15 4h5.5c.8 0 1.5.7 1.5 1.5v12c0 .8-.7 1.5-1.5 1.5H15c-1 0-1.9.5-2.3 1.3a.8.8 0 0 1-1.4 0C10.9 19.5 10 19 9 19H3.5C2.7 19 2 18.3 2 17.5v-12Zm9 1.9C11 6.6 10.1 6 9 6H4v11h5c.7 0 1.4.2 2 .5V7.4Zm2 10.1c.6-.3 1.3-.5 2-.5h5V6h-5c-1.1 0-2 .6-2 1.4v10.1Z"
		/>
	</svg>
</template>
