<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import CopyLink from '@/components/CopyLink.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, index, renewLink, store, update } from '@/routes/children';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Kinder', href: index() }],
    },
});

defineProps<{
    children: {
        id: number;
        name: string;
        level: string | null;
        lessons: number;
        published: number;
        shareUrl: string;
    }[];
}>();

const editing = ref<number | null>(null);
</script>

<template>
    <Head title="Kinder" />

    <div class="mx-auto w-full max-w-3xl space-y-8 p-4 md:p-6">
        <Heading
            title="Kinder"
            description="Jedes Kind hat einen eigenen Link. Darüber sieht es nur die Lernseiten, die du freigegeben hast, ohne Anmeldung."
        />

        <ul class="space-y-4">
            <li
                v-for="child in children"
                :key="child.id"
                class="rounded-xl border p-4"
            >
                <Form
                    v-if="editing === child.id"
                    v-bind="update.form(child.id)"
                    class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                    v-slot="{ errors, processing }"
                    @success="editing = null"
                >
                    <div class="grid gap-1.5">
                        <Label :for="`name-${child.id}`">Vorname</Label>
                        <Input
                            :id="`name-${child.id}`"
                            name="name"
                            :default-value="child.name"
                            required
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label :for="`level-${child.id}`">Stufe</Label>
                        <Input
                            :id="`level-${child.id}`"
                            name="level"
                            :default-value="child.level ?? ''"
                            placeholder="z. B. 2. Sek"
                        />
                    </div>
                    <div class="flex gap-2">
                        <Button type="submit" :disabled="processing">
                            Speichern
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="editing = null"
                        >
                            Abbrechen
                        </Button>
                    </div>
                </Form>

                <div
                    v-else
                    class="flex flex-wrap items-start justify-between gap-3"
                >
                    <div>
                        <p class="font-medium">
                            {{ child.name }}
                            <span
                                v-if="child.level"
                                class="font-normal text-muted-foreground"
                            >
                                · {{ child.level }}
                            </span>
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ child.lessons }}
                            {{
                                child.lessons === 1
                                    ? 'Lernseite'
                                    : 'Lernseiten'
                            }}, davon {{ child.published }} freigegeben
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="editing = child.id"
                    >
                        Bearbeiten
                    </Button>
                </div>

                <div
                    class="mt-4 flex flex-wrap items-center gap-2 border-t pt-4"
                >
                    <CopyLink :url="child.shareUrl" />

                    <Dialog>
                        <DialogTrigger as-child>
                            <Button type="button" variant="ghost" size="sm">
                                Neuen Link erstellen
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Neuen Link erstellen?</DialogTitle>
                                <DialogDescription>
                                    Der bisherige Link von {{ child.name }}
                                    funktioniert danach nicht mehr. Das ist
                                    sinnvoll, wenn der Link an die falsche
                                    Person gegangen ist.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <DialogClose as-child>
                                    <Button variant="outline">Abbrechen</Button>
                                </DialogClose>
                                <Form
                                    v-bind="renewLink.form(child.id)"
                                    v-slot="{ processing }"
                                >
                                    <DialogClose as-child>
                                        <Button
                                            type="submit"
                                            :disabled="processing"
                                        >
                                            Neuen Link erstellen
                                        </Button>
                                    </DialogClose>
                                </Form>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>

                    <Dialog>
                        <DialogTrigger as-child>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="text-destructive"
                            >
                                Löschen
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle
                                    >{{ child.name }} löschen?</DialogTitle
                                >
                                <DialogDescription>
                                    Alle {{ child.lessons }} Lernseiten und der
                                    Lernstand von {{ child.name }} werden
                                    endgültig gelöscht.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <DialogClose as-child>
                                    <Button variant="outline">Abbrechen</Button>
                                </DialogClose>
                                <Form
                                    v-bind="destroy.form(child.id)"
                                    v-slot="{ processing }"
                                >
                                    <DialogClose as-child>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            :disabled="processing"
                                        >
                                            Endgültig löschen
                                        </Button>
                                    </DialogClose>
                                </Form>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </li>
        </ul>

        <section class="rounded-xl border p-4">
            <h2 class="mb-3 font-medium">Kind hinzufügen</h2>
            <Form
                v-bind="store.form()"
                class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                reset-on-success
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-1.5">
                    <Label for="new-name">Vorname</Label>
                    <Input id="new-name" name="name" required />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="new-level">Stufe</Label>
                    <Input
                        id="new-level"
                        name="level"
                        placeholder="z. B. 2. Sek"
                    />
                </div>
                <Button type="submit" :disabled="processing">Hinzufügen</Button>
            </Form>
            <p class="mt-3 text-sm text-muted-foreground">
                Der Name bleibt in der App und wird nie an die KI geschickt.
            </p>
        </section>
    </div>
</template>
