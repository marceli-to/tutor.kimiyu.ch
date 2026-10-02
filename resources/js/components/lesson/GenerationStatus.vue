<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { Check, CircleAlert, LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { retry } from '@/routes/lessons';

const props = defineProps<{
    lessonId: number;
    status: string;
    step: string | null;
    error: string | null;
    canRetry: boolean;
    // Positionen der Grafiken, die gebaut werden
    plannedGraphics: number[];
}>();

const fullSteps = [
    { key: 'warteschlange', label: 'Wartet auf den Start' },
    { key: 'analyse', label: 'Stoff lesen und Erklärungen schreiben' },
    { key: 'module', label: 'Quiz und Übungen erstellen' },
    { key: 'pruefung', label: 'Inhalt nachprüfen' },
];

// Beim Neu-Erstellen einzelner Teile gibt es nur einen Schritt
const partSteps: Record<string, { key: string; label: string }[]> = {
    'neu-quiz': [{ key: 'neu-quiz', label: 'Neues Quiz schreiben' }],
    'neu-grafik': [{ key: 'neu-grafik', label: 'Grafik neu zeichnen' }],
};

const steps = computed(() => {
    const part = Object.keys(partSteps).find((key) => key === props.step);

    if (part) {
        return partSteps[part];
    }

    // Ein Schritt pro Grafik; die Jobs heissen «grafik-{Position}»
    const total = props.plannedGraphics.length;
    const graphicSteps = props.plannedGraphics.map((position, index) => ({
        key: `grafik-${position}`,
        label:
            total > 1
                ? `Grafik ${index + 1} von ${total} wird gebaut`
                : 'Interaktive Grafik zeichnen',
    }));

    return [...fullSteps, ...graphicSteps];
});

const current = computed(() => {
    const index = steps.value.findIndex((s) => s.key === props.step);
    const position = Number(props.step?.match(/^grafik-(\d)$/)?.[1]);

    if (index !== -1 || !position) {
        return index;
    }

    // Ein Job für eine Grafik ohne Plan läuft nur kurz durch: als nächste geplante Grafik anzeigen
    const next = steps.value.findIndex(
        (s) => s.key.startsWith('grafik-') && Number(s.key.slice(7)) > position,
    );

    return next === -1 ? steps.value.length : next;
});
const failed = computed(() => props.status === 'failed');
</script>

<template>
    <div
        class="mx-auto flex min-h-screen max-w-md flex-col justify-center gap-6 p-6"
    >
        <template v-if="failed">
            <div class="flex items-start gap-3">
                <CircleAlert
                    class="mt-0.5 size-6 shrink-0 text-destructive"
                    aria-hidden="true"
                />
                <div>
                    <h1 class="text-xl font-semibold">
                        Das hat nicht geklappt
                    </h1>
                    <p class="mt-2 text-muted-foreground" role="alert">
                        {{
                            error ??
                            'Die Lernseite konnte nicht erstellt werden.'
                        }}
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-3">
                <Form
                    v-if="canRetry"
                    v-bind="retry.form(lessonId)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" :disabled="processing"
                        >Nochmals versuchen</Button
                    >
                </Form>
                <Button variant="outline" as-child>
                    <Link :href="dashboard()">Zur Übersicht</Link>
                </Button>
            </div>
        </template>

        <template v-else>
            <div>
                <h1 class="text-xl font-semibold">Die Lernseite entsteht</h1>
                <p class="mt-2 text-muted-foreground">
                    Das dauert ein paar Minuten. Du kannst die Seite offen
                    lassen oder später zurückkommen.
                </p>
            </div>
            <ol class="space-y-3" aria-live="polite">
                <li
                    v-for="(s, index) in steps"
                    :key="s.key"
                    class="flex items-center gap-3"
                    :class="index > current ? 'text-muted-foreground' : ''"
                >
                    <Check
                        v-if="index < current"
                        class="size-5 text-green-600"
                        aria-hidden="true"
                    />
                    <LoaderCircle
                        v-else-if="index === current"
                        class="size-5 animate-spin motion-reduce:animate-none"
                        aria-hidden="true"
                    />
                    <span v-else class="size-5" aria-hidden="true" />
                    <span>
                        {{ s.label }}
                        <span v-if="index < current" class="sr-only"
                            >(erledigt)</span
                        >
                        <span v-else-if="index === current" class="sr-only"
                            >(läuft)</span
                        >
                    </span>
                </li>
            </ol>
        </template>
    </div>
</template>
