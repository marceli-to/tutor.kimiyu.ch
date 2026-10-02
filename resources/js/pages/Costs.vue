<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { costs } from '@/routes';
import { show } from '@/routes/lessons';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Kosten', href: costs() }],
    },
});

defineProps<{
    total: number;
    months: {
        month: string;
        lessons: number;
        calls: number;
        costUsd: number;
    }[];
    steps: {
        step: string;
        model: string;
        calls: number;
        failed: number;
        inputTokens: number;
        outputTokens: number;
        avgUsd: number;
        totalUsd: number;
    }[];
    lessons: {
        id: number | null;
        title: string;
        child: string | null;
        date: string;
        deleted: boolean;
        calls: number;
        failed: number;
        outputTokens: number;
        seconds: number;
        costUsd: number;
    }[];
}>();

const usd = (value: number, digits = 2) =>
    new Intl.NumberFormat('de-CH', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(value);

const number = (value: number) => new Intl.NumberFormat('de-CH').format(value);
</script>

<template>
    <Head title="Kosten" />

    <div class="mx-auto w-full max-w-4xl space-y-8 p-4 md:p-6">
        <Heading
            title="Kosten"
            description="Was die Lernseiten bei der Claude API gekostet haben. Enthält auch fehlgeschlagene Aufrufe."
        />

        <p class="text-3xl font-semibold tabular-nums">
            {{ usd(total) }}
            <span class="text-base font-normal text-muted-foreground"
                >insgesamt</span
            >
        </p>

        <section v-if="months.length" class="space-y-2">
            <h2 class="font-medium">Pro Monat</h2>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Monat
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Lernseiten
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                API-Aufrufe
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Kosten
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in months" :key="m.month" class="border-t">
                            <td class="px-4 py-2">{{ m.month }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ m.lessons }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ m.calls }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ usd(m.costUsd) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="steps.length" class="space-y-2">
            <h2 class="font-medium">Pro Schritt</h2>
            <p class="text-sm text-muted-foreground">
                Durchschnitt pro Aufruf.
            </p>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Schritt
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Aufrufe
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Ø Input
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Ø Output
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Ø Kosten
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Total
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="s in steps"
                            :key="`${s.step}|${s.model}`"
                            class="border-t"
                        >
                            <td class="px-4 py-2">
                                {{ s.step }}
                                <span class="block text-muted-foreground">{{
                                    s.model
                                }}</span>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ s.calls }}
                                <span
                                    v-if="s.failed"
                                    class="block text-destructive"
                                    >{{ s.failed }} fehlgeschlagen</span
                                >
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ number(s.inputTokens) }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ number(s.outputTokens) }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ usd(s.avgUsd, 3) }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ usd(s.totalUsd) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="lessons.length" class="space-y-2">
            <h2 class="font-medium">Pro Lernseite</h2>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Lernseite
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Aufrufe
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Output-Tokens
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Dauer
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Kosten
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="l in lessons"
                            :key="l.id ?? 'geloescht'"
                            class="border-t"
                        >
                            <td class="px-4 py-2">
                                <span
                                    v-if="l.deleted || l.id === null"
                                    class="font-medium"
                                    >{{ l.title }}</span
                                >
                                <Link
                                    v-else
                                    :href="show(l.id)"
                                    class="font-medium hover:underline"
                                    >{{ l.title }}</Link
                                >
                                <span
                                    v-if="l.child"
                                    class="block text-muted-foreground"
                                    >{{ l.child }} · {{ l.date
                                    }}<template v-if="l.deleted">
                                        · gelöscht</template
                                    ></span
                                >
                                <span
                                    v-else
                                    class="block text-muted-foreground italic"
                                    >gelöscht</span
                                >
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ l.calls }}
                                <span
                                    v-if="l.failed"
                                    class="block text-destructive"
                                    >{{ l.failed }} fehlgeschlagen</span
                                >
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ number(l.outputTokens) }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ Math.round(l.seconds / 60) }} Min.
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ usd(l.costUsd, 3) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <p v-else class="text-muted-foreground">Noch keine API-Aufrufe.</p>
    </div>
</template>
