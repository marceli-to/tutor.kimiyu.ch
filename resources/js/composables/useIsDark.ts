import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Folgt der Klasse «dark» auf <html>, die useAppearance setzt.
 * So stimmt der Wert auch, wenn das System-Theme wechselt.
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
