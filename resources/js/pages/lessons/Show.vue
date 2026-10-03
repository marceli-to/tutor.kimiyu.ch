<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import GenerationStatus from '@/components/lesson/GenerationStatus.vue';
import LessonPage from '@/components/lesson/LessonPage.vue';
import ParentToolbar from '@/components/lesson/ParentToolbar.vue';
import ReviewNotice from '@/components/lesson/ReviewNotice.vue';
import { mathToPlain } from '@/lib/math';
import type {
	GraphicState,
	LessonContent,
	LessonGraphics,
	LessonProfile,
	Palette,
} from '@/types';

const props = defineProps<{
	// Only for the parents, not in the local preview
	parent: {
		childName: string;
		shareUrl: string | null;
		canPublish: boolean;
		canRegenerate: { quiz: boolean };
		quizCount: number;
		graphics: GraphicState[];
		additions: string[];
	} | null;
	lesson: {
		id: number;
		status:
			| 'draft'
			| 'generating'
			| 'planned'
			| 'review'
			| 'published'
			| 'failed';
		step: string | null;
		error: string | null;
		canRetry: boolean;
		fromTopic: boolean;
		// Positions of the graphics being built (for the progress display)
		plannedGraphics: number[];
		// ElevenLabs reads the foreign words (for the progress display)
		speaks: boolean;
		subject: string;
		profile: LessonProfile;
		speechLang: string | null;
		// TeX between $…$ is rendered as a formula
		math: boolean;
		level: string;
		content: LessonContent | null;
		palette: Palette | null;
		graphics: LessonGraphics;
		checkNotes: { area: string; change: string }[];
	};
}>();

// The browser tab can't show formulas
const pageTitle = computed(() => {
	const title = props.lesson.content?.meta.title;

	if (title === undefined) {
		return 'Lernseite entsteht';
	}

	return props.lesson.math ? mathToPlain(title) : title;
});

// A regenerated part keeps the status (the child still sees the page), only the step shows it
const regenerating = computed(
	() => props.lesson.step?.startsWith('regenerate-') ?? false,
);

const generating = computed(
	() =>
		['draft', 'generating'].includes(props.lesson.status) ||
		regenerating.value,
);

// While the page is being created, poll the state every 3 seconds
// (with the parent's actions and graphic errors, which change at the end as well)
const { start, stop } = usePoll(
	3000,
	{ only: ['lesson', 'parent'] },
	{ autoStart: generating.value },
);

watch(generating, (active) => (active ? start() : stop()));
</script>

<template>
	<Head :title="pageTitle" />

	<GenerationStatus
		v-if="
			generating ||
			lesson.status === 'failed' ||
			!lesson.content ||
			!lesson.palette
		"
		:lesson-id="lesson.id"
		:status="lesson.status"
		:step="lesson.step"
		:error="lesson.error"
		:can-retry="lesson.canRetry"
		:planned-graphics="lesson.plannedGraphics"
		:speaks="lesson.speaks"
	/>

	<template v-else>
		<ParentToolbar
			v-if="parent"
			:lesson-id="lesson.id"
			:status="lesson.status"
			:child-name="parent.childName"
			:share-url="parent.shareUrl"
			:can-publish="parent.canPublish"
			:can-regenerate="parent.canRegenerate"
			:quiz-count="parent.quizCount"
			:graphics="parent.graphics"
		/>

		<LessonPage
			:content="lesson.content"
			:palette="lesson.palette"
			:graphics="lesson.graphics"
			:subject="lesson.subject"
			:profile="lesson.profile"
			:speech-lang="lesson.speechLang"
			:math="lesson.math"
			:level="lesson.level"
		>
			<template v-if="lesson.status === 'review' || lesson.error" #before>
				<ReviewNotice
					:status="lesson.status"
					:error="lesson.error"
					:check-notes="lesson.checkNotes"
					:graphics="parent?.graphics ?? []"
					:from-topic="lesson.fromTopic"
					:additions="parent?.additions ?? []"
				/>
			</template>
		</LessonPage>
	</template>
</template>
