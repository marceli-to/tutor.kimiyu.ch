<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { home } from '@/routes';
</script>

<template>
	<Head title="Datenschutz" />

	<main class="mx-auto max-w-2xl space-y-6 p-6 md:py-12">
		<Link
			:href="home()"
			class="text-sm text-muted-foreground hover:text-foreground"
			>← Zur Startseite</Link
		>
		<h1 class="text-2xl font-semibold">Datenschutz</h1>

		<p
			class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
		>
			Platzhalter: Diese Seite wird vor dem Start durch eine vollständige
			Datenschutzerklärung nach revDSG ersetzt.
		</p>

		<section class="space-y-2">
			<h2 class="font-medium">Was die App speichert</h2>
			<p class="text-muted-foreground">
				Konto (Name, E-Mail, Passwort verschlüsselt), Vornamen und Stufe
				der Kinder, die erstellten Lernseiten und die Resultate aus Quiz
				und Übungen.
			</p>
		</section>

		<section class="space-y-2">
			<h2 class="font-medium">Fotos</h2>
			<p class="text-muted-foreground">
				Fotos werden vor dem Hochladen verkleinert und ohne Metadaten
				(z. B. Standort) gespeichert. Sie sind nie öffentlich abrufbar
				und werden gelöscht, sobald die Lernseite erstellt ist.
			</p>
		</section>

		<section class="space-y-2">
			<h2 class="font-medium">Künstliche Intelligenz</h2>
			<p class="text-muted-foreground">
				Zum Erstellen der Lernseiten werden die Fotos oder das Thema,
				Fach, Stufe und die Hinweise an die Claude API von Anthropic
				(USA) übermittelt. Namen der Kinder werden nie übermittelt.
				Bitte keine persönlichen Angaben in die Hinweise schreiben.
			</p>
		</section>

		<section class="space-y-2">
			<h2 class="font-medium">Hosting</h2>
			<p class="text-muted-foreground">
				Die App und die Datenbank liegen bei einem Hosting-Anbieter in
				der Schweiz.
			</p>
		</section>
	</main>
</template>
