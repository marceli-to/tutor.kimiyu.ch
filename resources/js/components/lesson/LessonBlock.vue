<script setup lang="ts">
import FigureBlock from '@/components/lesson/FigureBlock.vue';
import GraphicFrame from '@/components/lesson/GraphicFrame.vue';
import MathText from '@/components/lesson/MathText.vue';
import OriginBadge from '@/components/lesson/OriginBadge.vue';
import SpeakButton from '@/components/lesson/SpeakButton.vue';
import { categoryClasses } from '@/lib/lesson';
import type { LessonBlock, LessonGraphics } from '@/types';

defineProps<{
	block: LessonBlock;
	graphics: LessonGraphics;
	// Languages lesson: read-aloud button for the foreign words
	speechLang?: string | null;
}>();
</script>

<template>
	<!-- Own line above the block, so the layout stays the same without a badge -->
	<div
		v-if="block.origin === 'added'"
		class="mt-4 mb-1 flex justify-end"
		:class="{ 'max-w-[66ch]': block.type === 'paragraph' }"
	>
		<OriginBadge :origin="block.origin" />
	</div>

	<p v-if="block.type === 'paragraph'" class="mb-4 max-w-[66ch]">
		<MathText :text="block.text" />
	</p>

	<div
		v-else-if="block.type === 'formula'"
		class="mt-4 overflow-x-auto rounded-[14px] border border-ls-line bg-ls-card px-5 py-4 font-display text-[clamp(1.1rem,3.6vw,1.45rem)] font-medium"
	>
		<MathText :text="block.text" />
		<small
			v-if="block.addendum"
			class="mt-1.5 block font-reading text-base text-ls-muted"
		>
			<MathText :text="block.addendum" />
		</small>
	</div>

	<div v-else-if="block.type === 'facts'" class="mt-4 grid gap-5">
		<div
			v-for="(fact, k) in block.entries"
			:key="k"
			class="border-l-4 border-ls-accent pl-4"
		>
			<h3 class="mb-1.5 text-[1.15rem] font-medium">
				<MathText :text="fact.title" />
			</h3>
			<p class="m-0 max-w-[66ch]"><MathText :text="fact.text" /></p>
		</div>
	</div>

	<div
		v-else-if="block.type === 'columns'"
		class="mt-4 grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-4"
	>
		<div
			v-for="(column, k) in block.entries"
			:key="k"
			class="rounded-2xl px-5 py-4"
			:class="categoryClasses[column.category].bg"
		>
			<h3
				class="mb-1.5 text-[1.15rem] font-medium"
				:class="categoryClasses[column.category].text"
			>
				<MathText :text="column.title" />
			</h3>
			<p
				v-for="(paragraph, n) in column.paragraphs"
				:key="n"
				class="mb-2 last:mb-0"
			>
				<MathText :text="paragraph" />
			</p>
		</div>
	</div>

	<figure
		v-else-if="block.type === 'graphic' && graphics[block.number]"
		class="m-0"
	>
		<GraphicFrame :graphic="graphics[block.number]!" />
		<figcaption
			v-if="graphics[block.number]!.description"
			class="mt-2 max-w-[66ch] text-base text-ls-muted"
		>
			{{ graphics[block.number]!.description }}
		</figcaption>
	</figure>

	<div
		v-else-if="block.type === 'box'"
		class="mt-4 rounded-2xl border border-ls-line bg-ls-card px-5 py-4"
	>
		<h3 class="mb-1.5 text-[1.15rem] font-medium">
			<MathText :text="block.title" />
		</h3>
		<p
			v-for="(paragraph, n) in block.paragraphs"
			:key="n"
			class="mb-4 max-w-[66ch] last:mb-0"
		>
			<MathText :text="paragraph" />
		</p>
	</div>

	<div
		v-else-if="block.type === 'vocabulary'"
		class="mt-4 overflow-hidden rounded-2xl border border-ls-line bg-ls-card"
	>
		<h3 v-if="block.title" class="px-5 pt-4 text-[1.15rem] font-medium">
			<MathText :text="block.title" />
		</h3>
		<table class="w-full border-collapse text-left">
			<thead class="sr-only">
				<tr>
					<th scope="col">Fremdsprache</th>
					<th scope="col">Deutsch</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="(entry, k) in block.entries"
					:key="k"
					class="border-t border-ls-line first:border-t-0"
				>
					<td class="py-2.5 pr-3 pl-5 align-top">
						<span class="flex items-start gap-1">
							<span class="pt-0.5 font-medium">{{
								entry.foreign
							}}</span>
							<SpeakButton
								v-if="speechLang"
								:text="entry.foreign"
								:lang="speechLang"
								class="-my-1"
							/>
						</span>
					</td>
					<td class="py-2.5 pr-5 pl-3 align-top">
						{{ entry.german }}
						<small
							v-if="entry.info"
							class="block text-[0.95rem] text-ls-muted"
						>
							{{ entry.info }}
						</small>
					</td>
				</tr>
			</tbody>
		</table>
	</div>

	<div
		v-else-if="block.type === 'worked_solution'"
		class="mt-4 rounded-2xl border border-ls-line bg-ls-card px-5 py-4"
	>
		<h3 class="mb-3 text-[1.15rem] font-medium">
			<span class="text-ls-muted">Beispiel:</span>
			<MathText :text="block.task" />
		</h3>
		<ol class="m-0 grid list-none gap-3 p-0">
			<li
				v-for="(step, k) in block.steps"
				:key="k"
				class="grid grid-cols-[1.75rem_1fr] gap-x-2"
			>
				<span
					class="flex size-7 items-center justify-center rounded-full bg-ls-accent-bg text-sm font-bold text-ls-accent"
					aria-hidden="true"
					>{{ k + 1 }}</span
				>
				<div class="max-w-[66ch] pt-0.5">
					<MathText :text="step.text" />
					<small
						v-if="step.reason"
						class="mt-0.5 block text-[0.95rem] text-ls-muted"
					>
						<MathText :text="step.reason" />
					</small>
				</div>
			</li>
		</ol>
		<p
			class="mt-4 mb-0 border-t border-ls-line pt-3 font-medium text-ls-accent"
		>
			<MathText :text="block.result" />
		</p>
	</div>

	<FigureBlock v-else-if="block.type === 'figure'" :figure="block" />

	<div
		v-else-if="block.type === 'conjugation'"
		class="mt-4 max-w-md rounded-2xl border border-ls-line bg-ls-card px-5 py-4"
	>
		<h3 class="mb-2 text-[1.15rem] font-medium">
			{{ block.verb }}
			<span class="font-normal text-ls-muted">· {{ block.tense }}</span>
		</h3>
		<table class="w-full border-collapse text-left">
			<tbody>
				<tr v-for="(row, k) in block.forms" :key="k">
					<th
						scope="row"
						class="w-[45%] py-1 pr-4 align-top font-normal text-ls-muted"
					>
						{{ row.person }}
					</th>
					<td class="py-1 align-top font-medium">{{ row.form }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>
