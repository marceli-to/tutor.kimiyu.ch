<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
	layout: {
		title: 'E-Mail-Adresse bestätigen',
		description:
			'Bitte bestätige deine E-Mail-Adresse über den Link, den wir dir gerade geschickt haben.',
	},
});

defineProps<{
	status?: string;
}>();
</script>

<template>
	<Head title="E-Mail-Adresse bestätigen" />

	<div
		v-if="status === 'verification-link-sent'"
		class="mb-4 text-center text-sm font-medium text-green-600"
	>
		Wir haben dir einen neuen Bestätigungslink an deine E-Mail-Adresse
		geschickt.
	</div>

	<Form
		v-bind="send.form()"
		class="space-y-6 text-center"
		v-slot="{ processing }"
	>
		<Button :disabled="processing" variant="secondary">
			<Spinner v-if="processing" />
			Bestätigungs-E-Mail erneut senden
		</Button>

		<TextLink :href="logout()" as="button" class="mx-auto block text-sm">
			Abmelden
		</TextLink>
	</Form>
</template>
