import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Follows the «dark» class on <html> that useAppearance sets.
 * So the value is also right when the system theme changes.
 */
export function useIsDark() {
	const isDark = ref(false);
	let observer: MutationObserver | null = null;

	onMounted(() => {
		const root = document.documentElement;
		isDark.value = root.classList.contains('dark');

		observer = new MutationObserver(() => {
			isDark.value = root.classList.contains('dark');
		});
		observer.observe(root, {
			attributes: true,
			attributeFilter: ['class'],
		});
	});

	onBeforeUnmount(() => observer?.disconnect());

	return isDark;
}
