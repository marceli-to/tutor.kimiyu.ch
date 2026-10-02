<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { ArrowLeft, EllipsisVertical, Pencil } from '@lucide/vue';
import { ref } from 'vue';
import CopyLink from '@/components/CopyLink.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { dashboard } from '@/routes';
import {
    destroy,
    edit,
    publish,
    regenerate,
    unpublish,
} from '@/routes/lessons';
import { regenerate as regenerateGraphic } from '@/routes/lessons/graphic';
import type { GraphicState } from '@/types';

const props = defineProps<{
    lessonId: number;
    status: string;
    childName: string;
    shareUrl: string | null;
    canPublish: boolean;
    canRegenerate: { quiz: boolean };
    quizCount: number;
    graphics: GraphicState[];
}>();

type Confirm = 'quiz' | 'graphic' | 'delete' | null;
const confirm = ref<Confirm>(null);
// Welche Grafik neu erstellt werden soll
const graphicNr = ref(1);

function graphicLabel(nr: number) {
    const label =
        nr === 1 && props.graphics.length > 1
            ? 'Grafik 1 (oben)'
            : `Grafik ${nr}`;
    const hidden = props.graphics.find(
        (graphic) => graphic.number === nr,
    )?.hidden;

    return hidden ? `${label} (ausgeblendet)` : label;
}

function confirmGraphic(nr: number) {
    graphicNr.value = nr;
    confirm.value = 'graphic';
}

const texts = {
    quiz: {
        title: 'Neues Quiz erstellen?',
        description: `Die KI schreibt ${props.quizCount} neue Fragen. Das dauert etwa eine Minute. Klappt es, prüfst du die Seite nochmals und gibst sie wieder frei. Klappt es nicht, bleibt alles wie es ist.`,
        action: 'Neues Quiz erstellen',
    },
    graphic: {
        title: 'Grafik neu erstellen?',
        description:
            'Die KI zeichnet die interaktive Grafik neu. Das dauert einige Minuten. Klappt es, prüfst du die Seite nochmals und gibst sie wieder frei. Klappt es nicht, bleibt alles wie es ist.',
        action: 'Grafik neu erstellen',
    },
    delete: {
        title: 'Lernseite löschen?',
        description: `Die Lernseite und der Lernstand von ${props.childName} dazu werden endgültig gelöscht. Die Kosten bleiben in der Kostenübersicht.`,
        action: 'Endgültig löschen',
    },
} as const;

function confirmForm() {
    switch (confirm.value) {
        case 'quiz':
            return regenerate.form([props.lessonId, 'quiz']);
        case 'graphic':
            return regenerateGraphic.form([props.lessonId, graphicNr.value]);
        default:
            return destroy.form(props.lessonId);
    }
}
</script>

<template>
    <div
        class="sticky top-0 z-20 border-b bg-background/95 font-sans text-sm text-foreground backdrop-blur"
    >
        <div
            class="mx-auto flex max-w-[780px] flex-wrap items-center gap-2 px-4 py-2"
        >
            <Button variant="ghost" size="sm" as-child>
                <Link :href="dashboard()">
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    Übersicht
                </Link>
            </Button>

            <Badge
                :variant="status === 'published' ? 'outline' : 'default'"
                class="mr-auto"
            >
                {{
                    status === 'published'
                        ? `Freigegeben für ${childName}`
                        : 'Zur Prüfung'
                }}
            </Badge>

            <Button variant="outline" size="sm" as-child>
                <Link :href="edit(lessonId)">
                    <Pencil class="size-4" aria-hidden="true" />
                    Bearbeiten
                </Link>
            </Button>

            <Form
                v-if="canPublish"
                v-bind="publish.form(lessonId)"
                v-slot="{ processing }"
            >
                <Button size="sm" type="submit" :disabled="processing">
                    Freigeben
                </Button>
            </Form>

            <CopyLink
                v-if="shareUrl"
                :url="shareUrl"
                :label="`Link für ${childName}`"
            />

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Weitere Aktionen"
                    >
                        <EllipsisVertical class="size-4" aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        :disabled="!canRegenerate.quiz"
                        @select="confirm = 'quiz'"
                    >
                        Neues Quiz erstellen
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-for="graphic in graphics"
                        :key="graphic.number"
                        :disabled="!graphic.canRegenerate"
                        @select="confirmGraphic(graphic.number)"
                    >
                        {{
                            graphics.length > 1
                                ? `${graphicLabel(graphic.number)} neu erstellen`
                                : 'Grafik neu erstellen'
                        }}
                    </DropdownMenuItem>
                    <template v-if="status === 'published'">
                        <DropdownMenuSeparator />
                        <Form
                            v-bind="unpublish.form(lessonId)"
                            v-slot="{ submit }"
                        >
                            <DropdownMenuItem @select="submit()">
                                Freigabe zurückziehen
                            </DropdownMenuItem>
                        </Form>
                    </template>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        class="text-destructive"
                        @select="confirm = 'delete'"
                    >
                        Löschen
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <Dialog
            :open="confirm !== null"
            @update:open="(open: boolean) => !open && (confirm = null)"
        >
            <DialogContent v-if="confirm">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            confirm === 'graphic' && graphics.length > 1
                                ? `${graphicLabel(graphicNr)} neu erstellen?`
                                : texts[confirm].title
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ texts[confirm].description }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline">Abbrechen</Button>
                    </DialogClose>
                    <Form
                        v-bind="confirmForm()"
                        v-slot="{ processing }"
                        @success="confirm = null"
                    >
                        <Button
                            type="submit"
                            :variant="
                                confirm === 'delete' ? 'destructive' : 'default'
                            "
                            :disabled="processing"
                        >
                            {{ texts[confirm].action }}
                        </Button>
                    </Form>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
