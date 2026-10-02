<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, toRaw } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import EditField from '@/components/lesson/EditField.vue';
import OriginBadge from '@/components/lesson/OriginBadge.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { edit, show, update } from '@/routes/lessons';
import type { CategoryId, LessonContent } from '@/types';

const props = defineProps<{
    lesson: {
        id: number;
        status: string;
        childName: string;
        content: LessonContent;
    };
    clozeMarkup: string | null;
    // Nur bei Lernseiten mit Fotos: was die KI ergänzt hat, ist markiert
    showOrigin: boolean;
    palettes: { value: string; label: string; accent: string }[];
    // Kurzer Text pro Grafik (Beschreibung, Idee oder Wunsch), nach Nummer
    graphicLabels: Partial<Record<number, string>>;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Übersicht', href: dashboard() },
        { title: 'Bearbeiten', href: edit(props.lesson.id) },
    ],
});

const form = useForm({
    // Props sind reaktive Proxys, structuredClone kann sie nicht kopieren
    content: JSON.parse(JSON.stringify(props.lesson.content)) as LessonContent,
    clozeMarkup: props.clozeMarkup ?? '',
});

const c = computed(() => form.content);
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function err(path: string): string | undefined {
    return errors.value[`content.${path}`];
}

// Absätze werden als ein Textfeld bearbeitet, getrennt durch eine Leerzeile
function joinParagraphs(paragraphs: string[]): string {
    return paragraphs.join('\n\n');
}

function splitParagraphs(value: string | null | undefined): string[] {
    return (value ?? '')
        .split(/\n\s*\n/)
        .map((p) => p.trim())
        .filter(Boolean);
}

// Stabile Schlüssel für Bausteine, auch wenn einer entfernt wird (splice). Nur im Browser,
// damit der Inhalt für den Server unverändert bleibt.
const blockKeys = new WeakMap<object, number>();
let nextBlockKey = 0;

function blockKey(block: object): number {
    const raw = toRaw(block);
    let key = blockKeys.get(raw);

    if (key === undefined) {
        key = nextBlockKey++;
        blockKeys.set(raw, key);
    }

    return key;
}

function newId(prefix: string): string {
    return prefix + Date.now().toString(36).slice(-6);
}

const categories: { value: CategoryId; label: string }[] = [
    { value: 'cat1', label: 'Farbe 1' },
    { value: 'cat2', label: 'Farbe 2' },
    { value: 'cat3', label: 'Farbe 3' },
];

function addQuestion() {
    c.value.module.quiz.push({
        id: newId('q'),
        frage: '',
        optionen: ['', '', '', ''],
        loesung: 0,
        tipp: '',
        erklaerung: '',
    });
}

function addTerm() {
    const sort = c.value.module.sortieren;

    sort?.begriffe.push({
        id: newId('s'),
        text: '',
        kategorie: sort.kategorien[0]?.id ?? 'cat1',
        erklaerung: '',
    });
}

function addCard() {
    c.value.module.karten?.eintraege.push({
        id: newId('k'),
        vorne: '',
        hinten: '',
    });
}

const errorCount = computed(() => Object.keys(form.errors).length);

function save() {
    form.put(update(props.lesson.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Lernseite bearbeiten" />

    <form
        class="mx-auto w-full max-w-3xl space-y-10 p-4 pb-0 md:p-6 md:pb-0"
        @submit.prevent="save"
    >
        <Heading
            :title="lesson.content.meta.titel"
            description="Korrigiere Texte, Quizfragen und Lösungen. Die Änderungen gelten sofort, auch wenn die Seite schon freigegeben ist."
        />

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">Kopf</h2>
            <EditField
                v-model="c.meta.titel"
                label="Titel"
                :error="err('meta.titel')"
            />
            <EditField
                v-model="c.meta.anleitung"
                label="Anleitung zur Grafik"
                :error="err('meta.anleitung')"
            />
            <div class="grid gap-4 sm:grid-cols-[8rem_1fr]">
                <EditField
                    v-model="c.meta.emoji"
                    label="Emoji"
                    :error="err('meta.emoji')"
                />
                <div class="grid gap-1.5">
                    <Label for="palette">Farben</Label>
                    <select
                        id="palette"
                        v-model="c.meta.palette"
                        class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                    >
                        <option
                            v-for="p in palettes"
                            :key="p.value"
                            :value="p.value"
                        >
                            {{ p.label }}
                        </option>
                    </select>
                </div>
            </div>
        </section>

        <section class="space-y-6">
            <h2 class="text-lg font-semibold">Erklärungen</h2>
            <div
                v-for="(section, s) in c.abschnitte"
                :key="s"
                class="space-y-4 rounded-xl border p-4"
            >
                <EditField
                    v-model="section.titel"
                    label="Überschrift"
                    :error="err(`abschnitte.${s}.titel`)"
                />

                <div
                    v-for="(block, b) in section.bloecke"
                    :key="blockKey(block)"
                    class="space-y-3 border-l-2 pl-4"
                >
                    <div
                        v-if="showOrigin && block.herkunft === 'ergaenzt'"
                        class="flex justify-end"
                    >
                        <OriginBadge :origin="block.herkunft" variant="app" />
                    </div>
                    <InputError
                        :message="err(`abschnitte.${s}.bloecke.${b}`)"
                    />

                    <EditField
                        v-if="block.typ === 'absatz'"
                        v-model="block.text"
                        label="Absatz"
                        multiline
                    />

                    <template v-else-if="block.typ === 'formel'">
                        <EditField
                            v-model="block.text"
                            label="Formel oder Merksatz"
                        />
                        <EditField
                            v-model="block.zusatz"
                            label="Zusatz (klein darunter)"
                        />
                    </template>

                    <template v-else-if="block.typ === 'fakten'">
                        <div
                            v-for="(fact, f) in block.eintraege"
                            :key="f"
                            class="space-y-2"
                        >
                            <EditField
                                v-model="fact.titel"
                                :label="`Fakt ${f + 1}: Titel`"
                            />
                            <EditField
                                v-model="fact.text"
                                :label="`Fakt ${f + 1}: Text`"
                                multiline
                            />
                        </div>
                    </template>

                    <template v-else-if="block.typ === 'spalten'">
                        <div
                            v-for="(column, k) in block.eintraege"
                            :key="k"
                            class="space-y-2"
                        >
                            <EditField
                                v-model="column.titel"
                                :label="`Spalte ${k + 1}: Titel`"
                            />
                            <EditField
                                :model-value="joinParagraphs(column.absaetze)"
                                :label="`Spalte ${k + 1}: Text`"
                                hint="Neuer Absatz: eine Leerzeile."
                                multiline
                                :rows="4"
                                @update:model-value="
                                    column.absaetze = splitParagraphs($event)
                                "
                            />
                        </div>
                    </template>

                    <template v-else-if="block.typ === 'box'">
                        <EditField
                            v-model="block.titel"
                            label="Kasten: Titel"
                        />
                        <EditField
                            :model-value="joinParagraphs(block.absaetze)"
                            label="Kasten: Text"
                            hint="Neuer Absatz: eine Leerzeile."
                            multiline
                            :rows="4"
                            @update:model-value="
                                block.absaetze = splitParagraphs($event)
                            "
                        />
                    </template>

                    <div v-else-if="block.typ === 'grafik'" class="space-y-1">
                        <p class="text-sm font-medium">
                            Grafik {{ block.nr
                            }}<template v-if="graphicLabels[block.nr]"
                                >: {{ graphicLabels[block.nr] }}</template
                            >
                        </p>
                        <p class="text-sm text-muted-foreground">
                            Entfernen blendet die Grafik aus. Mit «Grafik neu
                            erstellen» kommt sie zurück.
                        </p>
                    </div>

                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="text-destructive"
                        :disabled="section.bloecke.length <= 1"
                        @click="section.bloecke.splice(b, 1)"
                    >
                        <Trash2 class="size-4" aria-hidden="true" />
                        Baustein entfernen
                    </Button>
                </div>
                <InputError :message="err(`abschnitte.${s}.bloecke`)" />
            </div>
        </section>

        <section v-if="c.probieren" class="space-y-4">
            <h2 class="text-lg font-semibold">Probier es aus</h2>
            <EditField
                v-for="(experiment, e) in c.probieren.experimente"
                :key="e"
                v-model="c.probieren.experimente[e]"
                :label="`Experiment ${e + 1}`"
                :error="err(`probieren.experimente.${e}`)"
                multiline
            />
            <EditField
                v-model="c.probieren.alltagsvergleich"
                label="Alltagsvergleich"
                multiline
            />
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">Quiz</h2>
            <InputError :message="err('module.quiz')" />
            <fieldset
                v-for="(question, q) in c.module.quiz"
                :key="question.id"
                class="space-y-3 rounded-xl border p-4"
            >
                <legend class="px-1 text-sm font-medium">
                    Frage {{ q + 1 }}
                    <OriginBadge
                        v-if="showOrigin"
                        :origin="question.herkunft"
                        variant="app"
                        class="ml-1"
                    />
                </legend>
                <EditField
                    v-model="question.frage"
                    label="Frage"
                    :error="err(`module.quiz.${q}.frage`)"
                    multiline
                    :rows="2"
                />

                <div class="space-y-2">
                    <p class="text-sm font-medium">
                        Antworten
                        <span class="font-normal text-muted-foreground"
                            >(richtige Antwort anwählen)</span
                        >
                    </p>
                    <div
                        v-for="(_, o) in question.optionen"
                        :key="o"
                        class="flex items-center gap-2"
                    >
                        <input
                            v-model="question.loesung"
                            type="radio"
                            :name="`loesung-${question.id}`"
                            :value="o"
                            :aria-label="`Antwort ${o + 1} ist richtig`"
                            class="size-4 accent-primary"
                        />
                        <input
                            v-model="question.optionen[o]"
                            type="text"
                            :aria-label="`Antwort ${o + 1}`"
                            :class="[
                                'h-9 flex-1 rounded-md border bg-transparent px-3 text-sm shadow-xs',
                                question.loesung === o
                                    ? 'border-green-600 dark:border-green-500'
                                    : 'border-input',
                            ]"
                        />
                    </div>
                    <InputError
                        :message="
                            err(`module.quiz.${q}.optionen`) ??
                            err(`module.quiz.${q}.loesung`)
                        "
                    />
                </div>

                <EditField v-model="question.tipp" label="Tipp" />
                <EditField
                    v-model="question.erklaerung"
                    label="Erklärung nach der Antwort"
                    :error="err(`module.quiz.${q}.erklaerung`)"
                    multiline
                    :rows="2"
                />

                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="text-destructive"
                    :disabled="c.module.quiz.length <= 1"
                    @click="c.module.quiz.splice(q, 1)"
                >
                    <Trash2 class="size-4" aria-hidden="true" />
                    Frage entfernen
                </Button>
            </fieldset>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="c.module.quiz.length >= 10"
                @click="addQuestion"
            >
                <Plus class="size-4" aria-hidden="true" />
                Frage hinzufügen
            </Button>
        </section>

        <section v-if="c.module.sortieren" class="space-y-4">
            <h2 class="text-lg font-semibold">Sortier-Spiel</h2>
            <EditField
                v-model="c.module.sortieren.anleitung"
                label="Frage zum Sortieren"
            />
            <div class="grid gap-3 sm:grid-cols-3">
                <div
                    v-for="(category, k) in c.module.sortieren.kategorien"
                    :key="category.id"
                    class="space-y-2 rounded-xl border p-3"
                >
                    <EditField
                        v-model="category.label"
                        :label="`Korb ${k + 1}`"
                        :error="err(`module.sortieren.kategorien.${k}.label`)"
                    />
                    <EditField v-model="category.sub" label="Untertitel" />
                </div>
            </div>
            <InputError :message="err('module.sortieren.begriffe')" />
            <div
                v-for="(term, t) in c.module.sortieren.begriffe"
                :key="term.id"
                class="grid gap-2 rounded-xl border p-3 sm:grid-cols-[1fr_12rem_auto] sm:items-start"
            >
                <div class="grid gap-1">
                    <EditField
                        v-model="term.text"
                        :label="`Begriff ${t + 1}`"
                        :error="
                            err(`module.sortieren.begriffe.${t}.text`) ??
                            err(`module.sortieren.begriffe.${t}.kategorie`)
                        "
                    />
                    <div v-if="showOrigin && term.herkunft === 'ergaenzt'">
                        <OriginBadge :origin="term.herkunft" variant="app" />
                    </div>
                </div>
                <div class="grid gap-1.5">
                    <Label :for="`korb-${term.id}`">Gehört in</Label>
                    <select
                        :id="`korb-${term.id}`"
                        v-model="term.kategorie"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                    >
                        <option
                            v-for="category in c.module.sortieren.kategorien"
                            :key="category.id"
                            :value="category.id"
                        >
                            {{
                                category.label ||
                                categories.find((x) => x.value === category.id)
                                    ?.label
                            }}
                        </option>
                    </select>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="text-destructive sm:mt-6"
                    :aria-label="`Begriff ${t + 1} entfernen`"
                    @click="c.module.sortieren.begriffe.splice(t, 1)"
                >
                    <Trash2 class="size-4" aria-hidden="true" />
                </Button>
                <div class="sm:col-span-3">
                    <EditField
                        v-model="term.erklaerung"
                        label="Erklärung bei falscher Antwort (optional)"
                    />
                </div>
            </div>
            <Button type="button" variant="outline" size="sm" @click="addTerm">
                <Plus class="size-4" aria-hidden="true" />
                Begriff hinzufügen
            </Button>
        </section>

        <section v-if="c.module.karten" class="space-y-4">
            <h2 class="text-lg font-semibold">Karteikarten</h2>
            <InputError :message="err('module.karten.eintraege')" />
            <div
                v-for="(card, k) in c.module.karten.eintraege"
                :key="card.id"
                class="grid gap-2 rounded-xl border p-3 sm:grid-cols-[1fr_2fr_auto] sm:items-start"
            >
                <div class="grid gap-1">
                    <EditField
                        v-model="card.vorne"
                        label="Vorderseite"
                        :error="err(`module.karten.eintraege.${k}.vorne`)"
                    />
                    <div v-if="showOrigin && card.herkunft === 'ergaenzt'">
                        <OriginBadge :origin="card.herkunft" variant="app" />
                    </div>
                </div>
                <EditField
                    v-model="card.hinten"
                    label="Rückseite"
                    :error="err(`module.karten.eintraege.${k}.hinten`)"
                    multiline
                    :rows="2"
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="text-destructive sm:mt-6"
                    :aria-label="`Karte ${k + 1} entfernen`"
                    @click="c.module.karten.eintraege.splice(k, 1)"
                >
                    <Trash2 class="size-4" aria-hidden="true" />
                </Button>
            </div>
            <Button type="button" variant="outline" size="sm" @click="addCard">
                <Plus class="size-4" aria-hidden="true" />
                Karte hinzufügen
            </Button>
        </section>

        <section v-if="c.module.lueckentext" class="space-y-4">
            <h2 class="text-lg font-semibold">
                Lückentext
                <OriginBadge
                    v-if="showOrigin"
                    :origin="c.module.lueckentext.herkunft"
                    variant="app"
                    class="ml-1"
                />
            </h2>
            <EditField
                v-model="form.clozeMarkup"
                label="Text mit Lücken"
                hint="Lücken in eckigen Klammern, andere richtige Schreibweisen mit | trennen: [Kohlenstoffdioxid|CO2]"
                multiline
                :rows="6"
                :error="
                    errors.clozeMarkup ?? err('module.lueckentext.segmente')
                "
            />
        </section>

        <section class="space-y-4">
            <h2 class="text-lg font-semibold">Zum Nachdenken</h2>
            <EditField
                v-model="c.nachdenken.frage"
                label="Frage"
                :error="err('nachdenken.frage')"
                multiline
                :rows="2"
            />
        </section>

        <div
            class="sticky bottom-0 z-10 -mx-4 border-t bg-background/95 backdrop-blur md:-mx-6"
        >
            <div
                class="mx-auto flex max-w-3xl flex-wrap items-center gap-3 px-4 py-3 md:px-6"
            >
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    Speichern
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="show(lesson.id)">Zur Lernseite</Link>
                </Button>
                <span
                    v-if="errorCount"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{
                        errorCount === 1
                            ? 'Ein Feld braucht'
                            : `${errorCount} Felder brauchen`
                    }}
                    noch eine Korrektur.
                </span>
                <span
                    v-else-if="form.isDirty"
                    class="text-sm text-muted-foreground"
                >
                    Ungespeicherte Änderungen
                </span>
            </div>
        </div>
    </form>
</template>
