<script setup lang="ts">
defineProps<{
    checkNotes: { bereich: string; aenderung: string }[];
    heroError: string | null;
    fromTopic: boolean;
}>();
</script>

<template>
    <div
        class="mb-8 rounded-2xl border border-ls-line bg-ls-card px-5 py-4 text-base"
    >
        <p class="m-0 font-bold">Bitte prüfen, bevor dein Kind damit lernt</p>
        <p class="mt-1 mb-0 text-ls-muted">
            Die Inhalte hat eine KI erstellt. Schau Texte, Quiz-Lösungen und
            Sortierung kurz durch.
        </p>
        <p v-if="fromTopic" class="mt-2 mb-0">
            Ohne Buchseite erstellt – mit dem Schulstoff vergleichen. An der
            Prüfung zählt die Definition aus dem Buch.
        </p>
        <details v-if="checkNotes.length" class="mt-3">
            <summary class="cursor-pointer">
                Die Nachprüfung hat {{ checkNotes.length }}
                {{ checkNotes.length === 1 ? 'Stelle' : 'Stellen' }} korrigiert
            </summary>
            <ul class="mt-2 mb-0 list-disc pl-5">
                <li v-for="(note, k) in checkNotes" :key="k">
                    <strong>{{ note.bereich }}:</strong> {{ note.aenderung }}
                </li>
            </ul>
        </details>
        <p v-if="heroError" class="mt-3 mb-0 text-ls-bad">
            Die interaktive Grafik konnte nicht erstellt werden. {{ heroError }}
        </p>
    </div>
</template>
