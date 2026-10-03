<script setup lang="ts">
import { computed } from 'vue';
import MathText from '@/components/lesson/MathText.vue';
import type { FigureData } from '@/types';

// Geometry figure drawn by the app from points (coordinates 0–100); the model never writes SVG.
// Colours come from the lesson theme, so light and dark mode follow automatically.
const props = defineProps<{
	figure: FigureData;
}>();

type Point = { x: number; y: number };

const points = computed(
	() => new Map(props.figure.points.map((point) => [point.id, point])),
);

// Labels move away from the middle of the figure, where the lines meet
const centre = computed<Point>(() => {
	const all = props.figure.points;

	return {
		x: all.reduce((sum, p) => sum + p.x, 0) / all.length,
		y: all.reduce((sum, p) => sum + p.y, 0) / all.length,
	};
});

function away(from: Point, distance: number): Point {
	const dx = from.x - centre.value.x;
	const dy = from.y - centre.value.y;
	const length = Math.hypot(dx, dy);

	return length < 0.01
		? { x: from.x, y: from.y - distance }
		: {
				x: from.x + (dx / length) * distance,
				y: from.y + (dy / length) * distance,
			};
}

const pointLabels = computed(() =>
	props.figure.points
		.filter((point) => point.label)
		.map((point) => ({
			id: point.id,
			text: point.label!,
			...away(point, 5.5),
		})),
);

const lines = computed(() =>
	props.figure.lines.flatMap((line, i) => {
		const a = points.value.get(line.from);
		const b = points.value.get(line.to);

		if (!a || !b) {
			return [];
		}

		// Label beside the middle of the line, on the side away from the centre
		const mid = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
		const length = Math.hypot(b.x - a.x, b.y - a.y) || 1;
		let normal = { x: (a.y - b.y) / length, y: (b.x - a.x) / length };

		if (
			(mid.x - centre.value.x) * normal.x +
				(mid.y - centre.value.y) * normal.y <
			0
		) {
			normal = { x: -normal.x, y: -normal.y };
		}

		return [
			{
				key: i,
				a,
				b,
				dashed: line.style === 'dashed',
				label: line.label
					? {
							text: line.label,
							x: mid.x + normal.x * 4.5,
							y: mid.y + normal.y * 4.5,
						}
					: null,
			},
		];
	}),
);

const angles = computed(() =>
	props.figure.angles.flatMap((angle, i) => {
		const vertex = points.value.get(angle.vertex);
		const from = points.value.get(angle.from);
		const to = points.value.get(angle.to);

		if (!vertex || !from || !to) {
			return [];
		}

		const start = Math.atan2(from.y - vertex.y, from.x - vertex.x);
		let delta = Math.atan2(to.y - vertex.y, to.x - vertex.x) - start;

		// Always the angle below 180°
		if (delta > Math.PI) {
			delta -= 2 * Math.PI;
		} else if (delta < -Math.PI) {
			delta += 2 * Math.PI;
		}

		// The arc fits the shorter leg, so small figures stay readable
		const radius = Math.max(
			4,
			Math.min(
				10,
				0.35 *
					Math.min(
						Math.hypot(from.x - vertex.x, from.y - vertex.y),
						Math.hypot(to.x - vertex.x, to.y - vertex.y),
					),
			),
		);
		const at = (direction: number, r: number) => ({
			x: vertex.x + Math.cos(direction) * r,
			y: vertex.y + Math.sin(direction) * r,
		});
		const a = at(start, radius);
		const b = at(start + delta, radius);
		const arc = `A${radius} ${radius} 0 0 ${delta > 0 ? 1 : 0} ${b.x} ${b.y}`;
		const right = Math.abs(Math.abs(delta) - Math.PI / 2) < 0.01;
		// Right angle: a small square instead of an arc
		const square = [
			at(start, radius * 0.6),
			at(start + delta / 2, radius * 0.6 * Math.SQRT2),
			at(start + delta, radius * 0.6),
		];

		return [
			{
				key: i,
				path: right
					? `M${square.map((p) => `${p.x} ${p.y}`).join('L')}`
					: `M${a.x} ${a.y}${arc}`,
				wedge: right
					? null
					: `M${vertex.x} ${vertex.y}L${a.x} ${a.y}${arc}Z`,
				label: angle.label
					? {
							text: angle.label,
							...at(start + delta / 2, radius + 4.5),
						}
					: null,
			},
		];
	}),
);

// Screen readers get the title and every label instead of the drawing
const description = computed(() => {
	const named = (labels: (string | null | undefined)[]) =>
		labels.filter((label): label is string => !!label).join(', ');
	const parts = [
		props.figure.title,
		named(props.figure.points.map((p) => p.label)) &&
			`Punkte: ${named(props.figure.points.map((p) => p.label))}`,
		named(props.figure.lines.map((l) => l.label)) &&
			`Linien: ${named(props.figure.lines.map((l) => l.label))}`,
		named(props.figure.angles.map((a) => a.label)) &&
			`Winkel: ${named(props.figure.angles.map((a) => a.label))}`,
	];

	return parts.filter(Boolean).join('. ') || 'Geometrische Figur';
});
</script>

<template>
	<figure
		class="mt-4 mb-0 rounded-2xl border border-ls-line bg-ls-card px-4 py-4"
	>
		<svg
			viewBox="-8 -8 116 116"
			class="mx-auto block w-full max-w-[420px]"
			role="img"
			:aria-label="description"
		>
			<template v-for="angle in angles" :key="`w${angle.key}`">
				<path
					v-if="angle.wedge"
					:d="angle.wedge"
					class="fill-ls-accent-bg"
				/>
			</template>
			<line
				v-for="line in lines"
				:key="`l${line.key}`"
				:x1="line.a.x"
				:y1="line.a.y"
				:x2="line.b.x"
				:y2="line.b.y"
				:class="line.dashed ? 'stroke-ls-muted' : 'stroke-ls-ink'"
				:stroke-width="line.dashed ? 0.6 : 0.8"
				:stroke-dasharray="line.dashed ? '2 1.5' : undefined"
				stroke-linecap="round"
			/>
			<path
				v-for="angle in angles"
				:key="`a${angle.key}`"
				:d="angle.path"
				class="stroke-ls-accent"
				fill="none"
				stroke-width="0.7"
			/>
			<circle
				v-for="point in figure.points.filter((p) => p.label)"
				:key="`p${point.id}`"
				:cx="point.x"
				:cy="point.y"
				r="1.1"
				class="fill-ls-ink"
			/>
			<g
				font-size="5"
				text-anchor="middle"
				dominant-baseline="central"
				paint-order="stroke"
				stroke-width="1.4"
				stroke-linejoin="round"
				class="stroke-ls-card"
			>
				<text
					v-for="label in pointLabels"
					:key="`pl${label.id}`"
					:x="label.x"
					:y="label.y"
					class="fill-ls-ink font-bold"
				>
					{{ label.text }}
				</text>
				<template v-for="line in lines" :key="`ll${line.key}`">
					<text
						v-if="line.label"
						:x="line.label.x"
						:y="line.label.y"
						class="fill-ls-ink italic"
					>
						{{ line.label.text }}
					</text>
				</template>
				<template v-for="angle in angles" :key="`al${angle.key}`">
					<text
						v-if="angle.label"
						:x="angle.label.x"
						:y="angle.label.y"
						class="fill-ls-accent"
						font-size="4.5"
					>
						{{ angle.label.text }}
					</text>
				</template>
			</g>
		</svg>
		<figcaption
			v-if="figure.title"
			class="mt-2 text-center text-[0.95rem] text-ls-muted"
			aria-hidden="true"
		>
			<MathText :text="figure.title" />
		</figcaption>
	</figure>
</template>
