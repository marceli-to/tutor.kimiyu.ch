# Auftrag: Lernseiten-Generator (Laravel + Vue)

Ich möchte eine Web-App bauen, die aus einem Foto einer Schulbuchseite eine interaktive Lernseite für Schülerinnen und Schüler (Sek I, Schweiz) erzeugt. Bevor du Code schreibst: Lies diesen Auftrag, schau dir die Referenzdateien an, stell mir offene Fragen und schlag mir einen Umsetzungsplan in Phasen vor. Erst nach meinem OK loslegen.

## Referenzen

Im Ordner `docs/lernseite-skill/` liegt ein bestehender Claude-Skill, der genau diese Seiten heute manuell erzeugt:

- `SKILL.md`: Didaktik, Seitenaufbau, Sprachregeln, Hero-Muster, Prüfschritte. Das ist die fachliche Spezifikation für die App.
- `assets/template.html`: Design-Tokens, Typografie und die Logik der Lernmodule (Quiz, Sortierspiel, Karteikarten, Lückentext). Diese Module sollen als Vue-Komponenten nachgebaut werden, gleiches Verhalten und gleicher Look.
- `references/fotosynthese.html`, `references/oekosystem.html`: zwei fertige Beispielseiten als Qualitätsmassstab.

## Stack

- Laravel (aktuelle Version)
- Vue 3 mit Composition API und `<script setup>`
- Vite als Build-Tool (Laravel-Vite-Plugin)
- Tailwind CSS (aktuelle Version); die Design-Tokens aus `template.html` als Theme-Variablen übernehmen
- Keine weiteren Frameworks ohne Rückfrage. Wie Laravel und Vue verbunden werden (z. B. Inertia oder Laravel-API mit Vue-SPA), schlägst du im Plan mit Begründung vor.
- Claude API für Bildanalyse und Inhaltsgenerierung. Modellname und API-Key über `.env`/`config/services.php`, nicht im Code. Prüfe in der aktuellen Anthropic-Doku, welches Modell für Vision und strukturierte Ausgabe geeignet ist und wie Bilder übergeben werden.
- Queue für die Generierung (dauert länger als ein normaler Request), Status-Polling oder Broadcasting im Frontend.

## Kernarchitektur

Das Modell generiert **keine komplette HTML-Seite**, sondern strukturierte Daten. Die App rendert diese in feste Komponenten. Grund: konsistentes Design, testbar, günstiger, sicherer.

1. **Upload:** 1–4 Fotos (Seiten eines Themas), optional Freitext («Prüfung am Freitag über …»), Stufe und Fach als Auswahl.
2. **Analyse-Call:** Bilder + Systemprompt (abgeleitet aus `SKILL.md`) → JSON nach einem festen Schema. Nutze Tool Use bzw. strukturierte Ausgabe, damit das Schema garantiert ist. Validiere das Resultat serverseitig (Laravel Validator oder JSON-Schema); bei Fehlern ein Reparatur-Call mit der Fehlermeldung, maximal einmal.
3. **Schema (Vorschlag, gerne verbessern):**
    - `meta`: titel, fach, stufe, kernidee, emoji, farben (accent, cat1–cat3 für hell/dunkel)
    - `hero`: typ (einer der Hero-Muster aus SKILL.md), titel, anleitung, `html` (siehe unten)
    - `erklaerung`: formel/merksatz, fakten[] {titel, text}, spalten[] {titel, text, kategorie}
    - `probieren`: experimente[], alltagsvergleich
    - `module`: quiz[] {frage, optionen[], loesung, tipp, erklaerung}, sortieren {kategorien[], begriffe[]}, karten[] {vorne, hinten}, lueckentext {text mit [Lücke|Alternative]}
    - `nachdenken`: frage
4. **Hero-Grafik:** Das ist der einzige frei generierte Teil. Das Modell liefert ein eigenständiges HTML/SVG-Fragment mit Inline-Skript. Anzeige zwingend in einem `<iframe sandbox="allow-scripts" srcdoc="…">` ohne `allow-same-origin`, mit strikter CSP (kein Netzwerk). Farben und Schriften per CSS-Variablen ins iframe übergeben. Optional als zweiter, separater API-Call, damit ein fehlerhafter Hero nicht die ganze Seite kippt.
5. **Prüf-Call (optional, per Config abschaltbar):** Ein zweiter Durchgang prüft Quiz-Lösungen, Sortierung und fachliche Richtigkeit und korrigiert das JSON.
6. **Darstellung:** Lernseite als Vue-Seite aus den Daten. Module als Komponenten: `QuizModule`, `SortModule`, `FlashcardModule`, `ClozeModule`, `HeroFrame`. Hell/Dunkel-Modus, mobil ab 360px, Tastaturbedienung, `prefers-reduced-motion`.

## Funktionen

- Nutzerkonten (einfach, Laravel Breeze o. ä.). Ein Elternteil kann mehrere Kinder-Profile anlegen.
- Lernseiten-Bibliothek pro Kind, sortiert nach Fach und Datum.
- Bearbeiten: Texte, Quizfragen und Lösungen im Browser korrigieren, bevor die Seite freigegeben wird (Eltern prüfen den Inhalt).
- «Neu generieren» einzelner Teile (nur Quiz, nur Hero).
- Teilen per nicht erratbarem Link (read-only), damit das Kind die Seite auf dem eigenen Gerät öffnen kann.
- Lernstand: Quiz- und Sortier-Resultate pro Kind speichern, einfache Übersicht «was sitzt, was nicht».

## Nicht-funktionale Anforderungen

- **Datenschutz (revDSG):** Fotos nach erfolgreicher Generierung löschen (konfigurierbar), keine Personendaten der Kinder an die API schicken, Hosting in der Schweiz vorsehen. Datenschutzhinweis-Seite als Platzhalter.
- **Urheberrecht:** Der Systemprompt verlangt, dass alle Inhalte in eigenen Worten formuliert und keine Buchabbildungen nachgebaut werden. Fotos nie öffentlich ausliefern.
- **Kosten:** Token-Verbrauch pro Generierung loggen, Limit pro Konto und Tag (Config).
- **Sprache:** UI und Inhalte Deutsch, Schweizer Rechtschreibung (ss statt ß, «»).
- **Tests:** Pest. Unit-Tests für Schema-Validierung und Lückentext-Parser, Feature-Tests für Upload → Job → Seite mit gemocktem API-Client. Einige echte Beispiel-JSONs (Fotosynthese, Ökosystem) als Fixtures, abgeleitet aus den Referenzseiten.
- API-Client als eigene Service-Klasse hinter einem Interface, damit er in Tests ersetzt werden kann.

## Vorgehen

1. Plan und offene Fragen an mich.
2. Phase 1: Datenmodell, Schema, Vue-Module mit den Fixtures (ohne API). So sehe ich früh, ob Darstellung und Module stimmen.
3. Phase 2: API-Anbindung, Job, Validierung, Hero-iframe.
4. Phase 3: Konten, Kinderprofile, Bibliothek, Teilen, Bearbeiten.
5. Phase 4: Lernstand, Limits, Feinschliff.

Nach jeder Phase kurz zusammenfassen, was steht, und auf mein Review warten.
