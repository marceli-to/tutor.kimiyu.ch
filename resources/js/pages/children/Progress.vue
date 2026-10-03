<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import MathText from '@/components/lesson/MathText.vue';
import { index, progress } from '@/routes/children';
import { show } from '@/routes/lessons';

type Status = 'mastered' | 'almost' | 'practice' | 'open';

const props = defineProps<{
	child: { id: number; name: string; lastActivity: string | null };
	lessons: {
		id: number;
		title: string;
		subject: string;
		emoji: string | null;
		published: boolean;
		math: boolean;
		counts: Record<Status, number>;
		total: number;
		open: { module: string; id: string; text: string; status: Status }[];
	}[];
}>();

setLayoutProps({
	breadcrumbs: [
		{ title: 'Kinder', href: index() },
		{
			title: `Lernstand ${props.child.name}`,
			href: progress(props.child.id),
		},
	],
});

const statuses: { key: Status; label: string; bar: string; dot: string }[] = [
	{
		key: 'mastered',
		label: 'sitzt',
		bar: 'bg-green-600 dark:bg-green-500',
		dot: 'bg-green-600 dark:bg-green-500',
	},
	{ key: 'almost', label: 'fast', bar: 'bg-amber-500', dot: 'bg-amber-500' },
	{
		key: 'practice',
		label: 'üben',
		bar: 'bg-red-600 dark:bg-red-500',
		dot: 'bg-red-600 dark:bg-red-500',
	},
	{
		key: 'open',
		label: 'noch offen',
		bar: 'bg-muted',
		dot: 'bg-muted-foreground/40',
	},
];

const moduleLabel: Record<string, string> = {
	quiz: 'Quiz',
	sorting: 'Sortieren',
	cloze: 'Lückentext',
};
</script>

<template>
	<Head :title="`Lernstand ${child.name}`" />

	<div class="mx-auto w-full max-w-3xl space-y-8 p-4 md:p-6">
		<Heading
			:title="`Lernstand ${child.name}`"
			:description="
				child.lastActivity
					? `Zuletzt geübt ${child.lastActivity}. Es zählen nur Antworten über den Link von ${child.name}.`
					: `${child.name} hat noch nichts geübt. Es zählen nur Antworten über den Link von ${child.name}.`
			"
		/>

		<ul
			class="flex flex-wrap gap-x-5 gap-y-2 text-sm text-muted-foreground"
		>
			<li
				v-for="s in statuses"
				:key="s.key"
				class="flex items-center gap-2"
			>
				<span
					class="size-2.5 rounded-full"
					:class="s.dot"
					aria-hidden="true"
				/>
				<span>
					<strong class="font-medium text-foreground">{{
						s.label
					}}</strong>
					<template v-if="s.key === 'mastered'"
						>: zweimal nacheinander richtig</template
					>
					<template v-else-if="s.key === 'almost'"
						>: zuletzt richtig</template
					>
					<template v-else-if="s.key === 'practice'"
						>: zuletzt falsch</template
					>
				</span>
			</li>
		</ul>

		<p v-if="!lessons.length" class="text-muted-foreground">
			Noch keine fertigen Lernseiten für {{ child.name }}.
		</p>

		<section
			v-for="lesson in lessons"
			:key="lesson.id"
			class="space-y-3 rounded-xl border p-4"
			:aria-labelledby="`lesson-${lesson.id}`"
		>
			<div class="flex items-start gap-3">
				<span class="text-2xl" aria-hidden="true">{{
					lesson.emoji ?? '📄'
				}}</span>
				<div class="min-w-0 flex-1">
					<h2 :id="`lesson-${lesson.id}`" class="font-medium">
						<Link :href="show(lesson.id)" class="hover:underline"
							><MathText :text="lesson.title" :math="lesson.math"
						/></Link>
					</h2>
					<p class="text-sm text-muted-foreground">
						{{ lesson.subject }}
						<template v-if="!lesson.published">
							· noch nicht freigegeben</template
						>
					</p>
				</div>
			</div>

			<div
				v-if="lesson.total"
				class="flex h-3 overflow-hidden rounded-full bg-muted"
				role="img"
				:aria-label="
					statuses
						.map((s) => `${lesson.counts[s.key]} ${s.label}`)
						.join(', ')
				"
			>
				<span
					v-for="s in statuses"
					:key="s.key"
					:class="s.bar"
					:style="{
						width: `${(lesson.counts[s.key] / lesson.total) * 100}%`,
					}"
				/>
			</div>
			<p class="text-sm text-muted-foreground">
				{{ lesson.counts.mastered }} von {{ lesson.total }} Aufgaben
				sitzen
				<template v-if="lesson.counts.almost">
					· {{ lesson.counts.almost }} fast</template
				>
				<template v-if="lesson.counts.practice">
					· {{ lesson.counts.practice }} zum Üben</template
				>
			</p>

			<details v-if="lesson.open.length" class="text-sm">
				<summary class="cursor-pointer font-medium">
					Was noch nicht sitzt
				</summary>
				<ul class="mt-2 space-y-1.5">
					<li
						v-for="item in lesson.open"
						:key="`${item.module}-${item.id}`"
						class="flex items-start gap-2"
					>
						<span
							class="mt-1.5 size-2 shrink-0 rounded-full"
							:class="
								statuses.find((s) => s.key === item.status)?.dot
							"
							aria-hidden="true"
						/>
						<span>
							<MathText :text="item.text" :math="lesson.math" />
							<span class="text-muted-foreground">
								({{ moduleLabel[item.module] }},
								{{
									item.status === 'practice'
										? 'üben'
										: 'fast'
								}})
							</span>
						</span>
					</li>
				</ul>
			</details>
		</section>
	</div>
</template>
