<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import LessonPage from '@/components/lesson/LessonPage.vue';
import { mathToPlain } from '@/lib/math';
import { saveAnswer } from '@/lib/saveAnswer';
import { answer as answerRoute, index } from '@/routes/shared';
import type {
	LessonContent,
	LessonGraphics,
	LessonProfile,
	ModuleAnswer,
	Palette,
} from '@/types';

const props = defineProps<{
	token: string;
	lesson: {
		id: number;
		subject: string;
		profile: LessonProfile;
		speechLang: string | null;
		// TeX between $…$ is rendered as a formula
		math: boolean;
		level: string;
		content: LessonContent;
		palette: Palette;
		graphics: LessonGraphics;
	};
}>();

function onAnswer(answer: ModuleAnswer) {
	saveAnswer(answerRoute([props.token, props.lesson.id]).url, answer);
}
</script>

<template>
	<Head
		:title="
			lesson.math
				? mathToPlain(lesson.content.meta.title)
				: lesson.content.meta.title
		"
	>
		<meta name="robots" content="noindex, nofollow" />
	</Head>

	<LessonPage
		:content="lesson.content"
		:palette="lesson.palette"
		:graphics="lesson.graphics"
		:subject="lesson.subject"
		:profile="lesson.profile"
		:speech-lang="lesson.speechLang"
		:math="lesson.math"
		:level="lesson.level"
		@answer="onAnswer"
	>
		<template #before>
			<Link
				:href="index(token)"
				class="mb-6 inline-flex items-center gap-2 text-base text-ls-muted hover:text-ls-ink"
			>
				<ArrowLeft class="size-4" aria-hidden="true" />
				Alle Lernseiten
			</Link>
		</template>
	</LessonPage>
</template>
