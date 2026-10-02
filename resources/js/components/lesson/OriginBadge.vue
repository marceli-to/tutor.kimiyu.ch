<script setup lang="ts">
// Markiert, was nicht auf den Fotos stand. Das Kind bekommt keine Herkunft,
// darum kann die Marke überall ohne weitere Bedingung eingebunden werden.
withDefaults(
    defineProps<{
        origin?: 'foto' | 'ergaenzt';
        // «app» für die Bearbeiten-Seite, die ausserhalb der Lernseiten-Farben liegt
        variant?: 'lesson' | 'app';
    }>(),
    { origin: undefined, variant: 'lesson' },
);
</script>

<template>
    <span
        v-if="origin === 'ergaenzt'"
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
