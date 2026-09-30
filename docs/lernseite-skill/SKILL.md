---
name: lernseite
description: Erstellt aus einem Foto einer Schulbuchseite, Arbeitsblatt, Heftnotizen oder einem genannten Schulthema eine interaktive Lernseite (HTML-Artifact) mit einer interaktiven Hauptgrafik, kurzen Erklärungen, Sortierspiel, Karteikarten, Lückentext und Quiz – auf Deutsch in Schweizer Rechtschreibung. Unbedingt verwenden, wenn jemand ein Foto aus einem Schulbuch hochlädt, «Lernseite», «Lernblatt», «zum Lernen», «für die Prüfung», «mit meiner Tochter/meinem Sohn lernen» schreibt oder Schulstoff (Sek, Primar, Gymi) interaktiv aufbereiten möchte, auch wenn das Wort Lernseite nicht fällt.
---

# Lernseite

Aus Schulstoff wird eine einzelne, selbsttragende HTML-Seite, mit der ein Kind selbständig lernt. Vorbilder für Ton, Aufbau und Qualität: `references/fotosynthese.html` und `references/oekosystem.html`. Lies mindestens eine davon, bevor du beginnst.

## Ablauf

### 1. Quelle verstehen
- Lies das Foto vollständig: Fach, Thema, Stufe, Kernaussagen, Fachbegriffe, Definitionen, Formeln, Merksätze, Abbildungen.
- Ist das Foto unleserlich, abgeschnitten oder unklar, welches Thema gemeint ist: kurz nachfragen statt raten.
- Die Stufe steht oft im Buch oder in der Anfrage. Sonst Sek I (ca. 12–15 Jahre) annehmen.
- Bleib beim Stoff der Seite. Nichts hinzufügen, was deutlich über die Stufe hinausgeht. Wenn das Buch eine bestimmte Definition verwendet, übernimm deren Inhalt (in eigenen Worten), auch wenn es genauere Definitionen gäbe – die Prüfung fragt die Buchversion ab.

### 2. Planen (kurz, im Kopf)
- **Kernidee in einem Satz:** Was muss das Kind nach dem Lernen verstanden haben?
- **Hero-Grafik wählen** (siehe Muster unten): Die Interaktion muss den Mechanismus zeigen, nicht nur dekorieren.
- **Module wählen:** Quiz ist immer dabei (5 Fragen). Dazu 1–3 passende weitere:
  - Sortierspiel: wenn der Stoff Kategorien hat (belebt/unbelebt, Laub-/Nadelbaum, Säure/Base, Verb/Nomen).
  - Karteikarten: bei vielen Fachbegriffen oder Vokabeln.
  - Lückentext: bei Definitionen, Abläufen, Merksätzen, Grammatikregeln.
- **Farben:** zwei bis drei Farben, die zum Thema passen (Pflanzen grün, Wasser blau, Geschichte erdig). Kategorie-Farben (cat1–cat3) müssen zur Hero-Grafik passen, damit die Farbe überall dasselbe bedeutet.

### 3. Bauen
- Kopiere `assets/template.html` und fülle nur die markierten Stellen:
  - `{{TITEL}}`, `{{STUFE}}` (z. B. «2. Sek»), `{{FACH}}`
  - Farbblock THEMA-FARBEN (hell und beide Dunkel-Varianten)
  - INHALT: Hero, Erklärteil, Modul-Container
  - `LESSON`-Daten
  - HERO-SKRIPT und thema-spezifisches CSS
- Die Modul-Engines nicht verändern. Sie rendern automatisch in `#quiz`, `#sorter`, `#cards`, `#cloze`, wenn Container und Daten vorhanden sind.

**Empfohlener Seitenaufbau:**
1. `h1` als Frage oder Formel, die neugierig macht («Wie macht ein Blatt Zucker aus Licht?»), darunter eine Zeile, was man mit der Grafik tun kann.
2. Hero-Grafik (`.stage`) mit Bedienelementen und einer Live-Erklärung, die sich mit der Interaktion ändert.
3. Das Wichtigste: Formel/Merksatz (`.formel`), dann 2–4 Fakten (`.facts`/`.fact`) oder Begriffs-Spalten (`.cols`/`.col.c1…`).
4. «Probier es aus» (`.try`): 2–3 konkrete Experimente mit der Hero-Grafik, dazu ein Alltagsvergleich.
5. Module: Sortier-Spiel, Karteikarten, Lückentext, dann «Teste dich selbst» (Quiz).
6. «Zum Nachdenken»: eine offene Transferfrage ohne Lösung.

### 4. Prüfen (Pflicht)
- Jede Quiz-Lösung (`a`, 0-basiert!) nochmals gegen die Frage prüfen. Die Distraktoren müssen plausibel, aber eindeutig falsch sein. Keine «alle obigen»-Antworten.
- Die richtige Antwort nicht immer an derselben Position.
- Sortier-Begriffe: jeder eindeutig einer Kategorie zuordenbar.
- Lückentext: gängige Schreibvarianten als Alternativen angeben (`[CO₂|CO2|Kohlenstoffdioxid]`).
- Fachliche Richtigkeit aller Aussagen.
- Skript-Syntax prüfen, z. B. alle `<script>`-Inhalte extrahieren und mit `node --check` testen.
- SVG: keine Beschriftung überlappt eine andere oder wird abgeschnitten.

### 5. Ausliefern
- In claude.ai: Datei in `/mnt/user-data/outputs/<thema>.html` speichern und als Artifact publizieren (passendes Emoji als Favicon).
- Sonst: Datei speichern und präsentieren.
- Antwort an den Nutzer: 2–4 Sätze, was die Seite enthält und womit man anfangen soll. Keine Wiederholung des Inhalts.

## Sprache

- Deutsch, Schweizer Rechtschreibung: immer «ss» statt «ß», Anführungszeichen «…».
- Kurze Sätze, aktive Verben, Du-Form. Ein Gedanke pro Satz.
- Fachbegriffe verwenden (sie kommen an der Prüfung), aber beim ersten Auftreten erklären.
- Ein Alltagsvergleich pro Kernidee (Backen, Sport, Handy-Akku …).
- Keine Texte aus dem Buch abschreiben: alles in eigenen Worten neu formulieren. Keine Abbildungen aus dem Buch nachbauen; eigene, schematische Grafiken zeichnen.
- Feedback ermutigend, nie herablassend.

## Hero-Muster

Wähle das Muster, das den Kern des Themas am besten sichtbar macht.

| Muster | Passt für | Beispiel |
|---|---|---|
| **Regler** (Parameter ändern, Wirkung sehen) | Abhängigkeiten, Ursache–Wirkung | Fotosynthese: Licht/CO₂/Wasser → Leistung; Hebelgesetz; Angebot/Nachfrage |
| **Ansichten umschalten** (Teile ein-/ausblenden) | Begriffe, die Teile eines Ganzen sind | Biotop/Biozönose/Ökosystem; Zellbestandteile; Schichten der Erde |
| **Schritt-für-Schritt** (Weiter/Zurück, Stufen hervorheben) | Abläufe, Kreisläufe | Wasserkreislauf, Verdauung, Gesetzgebung, schriftliche Division |
| **Zeitstrahl** (Ereignisse antippen) | Geschichte, Entwicklung | Industrialisierung, Evolution, Schweizer Bundesstaat |
| **Karte/Schema antippen** (Hotspots mit Erklärung) | Aufbau, Geografie | Aufbau des Auges, Kantone, Vulkan im Querschnitt |
| **Rechner/Umformer** | Mathe, Physik, Chemie | Dreisatz, Einheiten umrechnen, Prozentrechnen |

Umsetzungshinweise:
- SVG mit `viewBox`, Breite 100 %. Beschriftungen als `<text>` mit 13–16px, Farben über CSS-Variablen, damit der Dunkelmodus funktioniert.
- Bei «Ansichten umschalten» die Modi als Klassen auf einem Container setzen und per CSS Opacity/Graustufen steuern (siehe `oekosystem.html`).
- Bei «Regler» Linienstärke und Deckkraft an den Wert koppeln und eine Anzeige «Was bremst gerade?» o. ä. liefern (siehe `fotosynthese.html`).
- Animationen nur, wenn sie etwas zeigen (Fluss, Bewegung), und immer respektiert `prefers-reduced-motion`.
- Mobil muss alles bedienbar bleiben (ab 360px Breite).

## Technische Regeln (für publizierte Artifacts)

- Eine einzige HTML-Datei, kein externer Code ausser den Google Fonts aus der Vorlage.
- Keine Bilder von externen Servern; Grafiken als Inline-SVG.
- Keine Netzwerkanfragen, keine Claude-API-Aufrufe.
- `localStorage` nur mit try/catch, falls überhaupt nötig (z. B. Quiz-Bestwert).
