<script setup lang="ts">
import { useId } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps<{
	label: string;
	multiline?: boolean;
	rows?: number;
	error?: string;
	hint?: string;
	placeholder?: string;
}>();

const model = defineModel<string | null | undefined>({ required: true });
const id = useId();
</script>

<template>
	<div class="grid gap-1.5">
		<Label :for="id">{{ label }}</Label>
		<textarea
			v-if="multiline"
			:id="id"
			:value="model ?? ''"
			:rows="rows ?? 3"
			:placeholder="placeholder"
			:aria-invalid="error ? true : undefined"
			class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs aria-invalid:border-destructive"
			@input="model = ($event.target as HTMLTextAreaElement).value"
		/>
		<Input
			v-else
			:id="id"
			:model-value="model ?? ''"
			:placeholder="placeholder"
			:aria-invalid="error ? true : undefined"
			@update:model-value="model = String($event)"
		/>
		<p v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
		<InputError :message="error" />
	</div>
</template>
