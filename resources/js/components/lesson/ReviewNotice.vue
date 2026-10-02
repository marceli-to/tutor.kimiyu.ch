<script setup lang="ts">
defineProps<{
    status: string;
    error: string | null;
    checkNotes: { bereich: string; aenderung: string }[];
    heroError: string | null;
    fromTopic: boolean;
    additions: string[];
}>();
</script>

<template>
    <div
        class="mb-8 rounded-2xl border border-ls-line bg-ls-card px-5 py-4 text-base"
    >
        <p v-if="error" class="m-0 text-ls-bad" role="status">{{ error }}</p>
        <template v-if="status === 'review'">
            <p class="m-0 font-bold" :class="{ 'mt-3': error }">
                Bitte prüfen, bevor dein Kind damit lernt
            </p>
            <p class="mt-1 mb-0 text-ls-muted">
                Die Inhalte hat eine KI erstellt. Schau Texte, Quiz-Lösungen und
                Sortierung kurz durch und gib die Seite dann frei.
            </p>
            <p v-if="fromTopic" class="mt-2 mb-0">
                Ohne Buchseite erstellt – mit dem Schulstoff vergleichen. An der
                Prüfung zählt die Definition aus dem Buch.
            </p>
            <template v-if="additions.length">
                <p class="mt-3 mb-0">
                    <strong>
                        {{ additions.length }}
                        {{
                            additions.length === 1
                                ? 'Teil stammt'
                                : 'Teile stammen'
                        }}
                        nicht aus den Fotos.
                    </strong>
                    Die KI hat sie aus Fachwissen ergänzt, sie sind mit
                    «ergänzt» markiert. Bitte besonders genau prüfen.
                </p>
                <details class="mt-3">
                    <summary class="cursor-pointer">Was ergänzt wurde</summary>
                    <ul class="mt-2 mb-0 list-disc pl-5">
                        <li v-for="(addition, k) in additions" :key="k">
                            {{ addition }}
                        </li>
                    </ul>
                </details>
            </template>
            <details v-if="checkNotes.length" class="mt-3">
                <summary class="cursor-pointer">
                    Die Nachprüfung hat {{ checkNotes.length }}
                    {{ checkNotes.length === 1 ? 'Stelle' : 'Stellen' }}
                    korrigiert
                </summary>
                <ul class="mt-2 mb-0 list-disc pl-5">
                    <li v-for="(note, k) in checkNotes" :key="k">
                        <strong>{{ note.bereich }}:</strong>
                        {{ note.aenderung }}
                    </li>
                </ul>
            </details>
            <p v-if="heroError" class="mt-3 mb-0 text-ls-bad">
                Die interaktive Grafik konnte nicht erstellt werden.
                {{ heroError }}
            </p>
        </template>
    </div>
</template>
