<script setup lang="ts">
// Marks what wasn't on the photos. The child gets no origin,
// so the badge can be included anywhere without further conditions.
withDefaults(
    defineProps<{
        origin?: 'photo' | 'added';
        // «app» for the edit page, which is outside the lesson colours
        variant?: 'lesson' | 'app';
    }>(),
    { origin: undefined, variant: 'lesson' },
);
</script>

<template>
    <span
        v-if="origin === 'added'"
        class="inline-block rounded-full border px-2 py-px align-middle text-xs leading-normal font-normal whitespace-nowrap"
        :class="
            variant === 'app'
                ? 'border-border text-muted-foreground'
                : 'border-ls-line font-reading text-ls-muted'
        "
        title="Nicht auf den Fotos, von der KI aus Fachwissen ergänzt"
    >
        ergänzt
    </span>
</template>
