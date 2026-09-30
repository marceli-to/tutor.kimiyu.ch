<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

const steps = [
    {
        title: 'Fotografieren',
        text: 'Du fotografierst eine Seite aus dem Schulbuch oder gibst ein Thema ein.',
    },
    {
        title: 'Lernseite erhalten',
        text: 'Die App erstellt eine interaktive Lernseite mit Grafik, Quiz und Übungen.',
    },
    {
        title: 'Prüfen und teilen',
        text: 'Du schaust sie kurz durch und teilst sie mit deinem Kind.',
    },
];
</script>

<template>
    <Head title="Willkommen" />

    <div
        class="flex min-h-svh flex-col bg-background text-foreground antialiased"
    >
        <header
            class="mx-auto flex w-full max-w-4xl items-center justify-between px-4 py-5 sm:px-6"
        >
            <div class="flex items-center gap-2 font-semibold">
                <AppLogoIcon class="size-6 fill-current" />
                <span>Lernseiten</span>
            </div>
            <nav class="flex items-center gap-2">
                <Button
                    v-if="$page.props.auth.user"
                    as-child
                    variant="outline"
                    size="sm"
                >
                    <Link :href="dashboard()">Zur Übersicht</Link>
                </Button>
                <Button v-else as-child variant="ghost" size="sm">
                    <Link :href="login()">Anmelden</Link>
                </Button>
            </nav>
        </header>

        <main
            class="mx-auto flex w-full max-w-4xl flex-1 flex-col justify-center px-4 py-12 sm:px-6"
        >
            <div class="max-w-2xl">
                <h1
                    class="text-3xl font-semibold tracking-tight text-balance sm:text-5xl"
                >
                    Aus dem Schulbuch wird eine Lernseite
                </h1>
                <p
                    class="mt-5 text-base leading-relaxed text-pretty text-muted-foreground sm:text-lg"
                >
                    Fotografiere eine Seite aus dem Schulbuch oder gib ein Thema
                    ein. Daraus entsteht eine interaktive Lernseite mit Grafik,
                    Quiz und Übungen. Du prüfst sie und teilst sie dann mit
                    deinem Kind.
                </p>

                <div class="mt-8 flex flex-wrap gap-3">
                    <Button v-if="$page.props.auth.user" as-child size="lg">
                        <Link :href="dashboard()">Zur Übersicht</Link>
                    </Button>
                    <template v-else>
                        <Button as-child size="lg">
                            <Link :href="register()">Konto erstellen</Link>
                        </Button>
                        <Button as-child size="lg" variant="outline">
                            <Link :href="login()">Anmelden</Link>
                        </Button>
                    </template>
                </div>
            </div>

            <ol class="mt-16 grid gap-4 sm:grid-cols-3">
                <li
                    v-for="(step, index) in steps"
                    :key="step.title"
                    class="rounded-xl border bg-card p-5 text-card-foreground"
                >
                    <span
                        class="flex size-7 items-center justify-center rounded-full bg-muted text-sm font-medium"
                        aria-hidden="true"
                        >{{ index + 1 }}</span
                    >
                    <h2 class="mt-4 font-medium">{{ step.title }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ step.text }}
                    </p>
                </li>
            </ol>
        </main>

        <footer
            class="mx-auto flex w-full max-w-4xl items-center justify-between gap-4 px-4 py-6 text-sm text-muted-foreground sm:px-6"
        >
            <span>© {{ new Date().getFullYear() }} Lernseiten</span>
            <a
                href="/datenschutz"
                class="underline-offset-4 hover:text-foreground hover:underline"
                >Datenschutz</a
            >
        </footer>
    </div>
</template>
