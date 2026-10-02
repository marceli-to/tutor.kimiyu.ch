# Neue Lernseite – Erweiterung (Design)

Stand: 2026-10-02. Wird laufend ergänzt; weitere Teile folgen.

## Ausgangslage

- Quelle ist entweder Fotos (max. 4) **oder** ein Thema (max. 120 Zeichen). Daneben ein Feld «Hinweise» (500 Zeichen).
- Die Hinweise erreichen nur den Analyse-Schritt. Module, Prüfung und Grafik sehen nur Fach, Stufe und die Zusammenfassung.
- Die interaktive Grafik plant die Analyse selbst (`hero_plan`: Muster + Idee). Eltern können sie nur an- oder abwählen. Genau eine Grafik pro Seite (`lessons.hero`).

## Reihenfolge der Umsetzung

Jede Phase ist einzeln auslieferbar.

1. **Teil 4** – Kosten senken (vor allem Config und Prompts, wirkt sofort auf jede Seite)
2. **Teil 1** – Fotos als Rahmen, Auftrag, Lücken ergänzen
3. **Teil 3b** – Upload-Extras
4. **Teil 3c** – Umfang und Zweck
5. **Teil 5** – Fachprofile (je Profil einzeln: Sprachen → Mathematik → Geometrie → Deutsch)
6. **Teil 2** – Grafiken selbst bestimmen
7. **Teil 3a** – Plan bestätigen

---

## Teil 4 – Kosten senken

### Ist-Zustand (Prod-Log, 4 vollständige Lernseiten, 3 Grafiken, alles Opus 5.5, Effort `high`)

| Schritt | Ø Input | Ø Output | Ø Kosten |
|---|---|---|---|
| grafik | 12k | 26k | $0.61 |
| module | 7k | 4.5k | $0.13 |
| pruefung-module | 9.4k | 4k | $0.13 |
| pruefung-seite | 9.4k | 2.8k | $0.10 |
| analyse | 3k | 2.2k | $0.07 |

≈ $1.05 pro Lernseite mit Grafik, ≈ $0.45 ohne. Output macht rund 85 % aus (Opus: $20 pro Mio. Output vs. $4 Input). Die Grafik allein rund 60 %, grösstenteils Denk-Tokens wegen Effort `high`. Prompt-Caching bleibt aus (zu wenige Seiten für den 5-Minuten-Cache).

### Massnahmen

1. **Modell und Effort pro Schritt** statt global (`ANTHROPIC_MODEL`/`ANTHROPIC_EFFORT` bleiben als Standard). Neu in `config/lessons.php`:

   | Schritt | Modell | Effort | Begründung |
   |---|---|---|---|
   | planung, analyse | Opus 5.5 | high | Fotos lesen (Handschrift, Layout, Abbildungen) |
   | module, reparatur | Sonnet 5.5 | medium | strukturiertes Schreiben aus vorhandenem Stoff |
   | pruefung | Sonnet 5.5 | medium | gezieltes Prüfen, Eltern prüfen zusätzlich |
   | grafik, grafik-reparatur | Opus 5.5 | medium | interaktiver Code, Fehler kosten eine Reparatur |
   | vokabeln (später) | Sonnet 5.5 | medium | |

   Per `.env` überschreibbar (`LESSON_MODEL_MODULE=…`, `LESSON_EFFORT_GRAFIK=…`). `LanguageModel` erhält Modell und Effort aus dem `ModelRequest` statt aus dem Konstruktor.
2. **Prüfung liefert nur Korrekturen**: Liste von Änderungen (`pfad` als JSON-Pointer, `wert` als neuer Wert, `bereich` und `aenderung` für die Eltern) statt der ganzen Seite. Ersetzt werden nur einzelne Werte (Text, Zahl) und Listen aus Texten (z. B. `optionen`, `loesungen`), mit dem gleichen Typ wie bisher; keine ganzen Objekte, Module oder Listen von Objekten, keine `null`-Felder. Korrekturen am selben Eintrag (z. B. `optionen` und `loesung` einer Quizfrage) werden zusammen angewendet und nur behalten, wenn die Seite danach gültig ist; sonst wird die ganze Gruppe verworfen und geloggt. `check_notes` entstehen aus `bereich` und `aenderung`, gleiche Hinweise nur einmal.
3. **Ein Prüf-Call statt zwei**: Seite und Module zusammen.
4. **Grafik-Prompt straffen**: ein kompaktes Beispiel statt beider Fixture-Grafiken in voller Länge.
5. **Kostenübersicht pro Schritt** auf der Kosten-Seite (Ø Tokens und Kosten je Schritt und Modell), um jede Massnahme zu messen.

Teil 3a (Plan bestätigen) und 3c (Umfang «Kurz») senken die Kosten zusätzlich.

### Vorgehen und Ziel

- Eine Massnahme nach der anderen, jeweils 3–4 Lernseiten erzeugen und im Log vergleichen. Zuerst die Grafik mit Effort `medium` (grösster Posten, grösste Unsicherheit bei der Qualität).
- Schätzung (nicht gemessen): ≈ $0.45–0.55 pro Lernseite mit Grafik.
- Nicht vorgesehen: Batch-API (50 % günstiger, aber Antwortzeit Minuten bis Stunden).

---

## Teil 1 – Fotos als Rahmen, Auftrag als Prompt

### Verhalten

| Eingabe | Verhalten |
|---|---|
| Nur Fotos | wie heute |
| Nur Auftrag | ersetzt «Nur ein Thema»: Auftrag = Thema + Anweisungen, die KI arbeitet aus Fachwissen |
| Fotos + Auftrag | **Fotos sind der verbindliche Rahmen** (Stoff, Begriffe, Definitionen, Niveau). Der Auftrag wählt Fokus, Blickwinkel oder Stil. Verlangt der Auftrag ein Thema, das die Fotos nicht abdecken, ergänzt die KI es aus Fachwissen und markiert es (siehe «Lücken ergänzen»). |
| Nichts | Validierungsfehler |

### Formular (`resources/js/pages/lessons/Create.vue`)

- Umschalter «Fotos vom Schulbuch | Nur ein Thema» entfällt.
- **Fotos** (optional): Upload wie heute. Hinweis: «Die Fotos geben den Rahmen vor: Stoff, Begriffe, Niveau.»
- **Auftrag** (optional): Textarea, max. 1000 Zeichen, mit 3–4 Beispiel-Chips zum Einfügen (z. B. «Prüfung am …, Fokus auf …», «Nur die Fachbegriffe», «Mit Beispielen aus dem Alltag»). Datenschutzhinweis bleibt.
- Mindestens eines von beiden ist Pflicht. Der Button bleibt bis dahin deaktiviert.
- Das Feld «Hinweise» entfällt (im Auftrag aufgegangen). Kind, Fach und Stufe bleiben.

### Daten

- Neue Spalten `lessons.prompt` (text, nullable), `lessons.photo_count` (Anzahl hochgeladener Fotos; bleibt, auch wenn die Fotos gelöscht sind) und `lessons.additions` (json, Liste der Ergänzungen aus der Analyse).
- `topic` und `notes` bleiben für bestehende Lernseiten und werden für neue nicht mehr befüllt. Beim Neu-Erstellen alter Seiten gehen sie weiter an die KI («zum Thema …», «Hinweise der Eltern»).
- `isFromTopic()` → `photo_count === 0` und Auftrag oder Thema vorhanden.
- Titel-Fallback (Dashboard, Kosten) über `Lesson::displayTitle()`: `title` → `topic` → Anfang des Auftrags → «Neue Lernseite».
- Umgesetzt (2026-10-02): Die Prüfung kann `herkunft` und IDs nicht ändern (Guard in `Corrections`). Der Banner «… Teile stammen nicht aus den Fotos» steht im Review-Hinweis und ist darum nur vor dem Freigeben sichtbar; die Badges bleiben für Eltern auch danach. Bausteine im Auftrag sind deaktiviert, wenn sie den Auftrag über 1000 Zeichen schieben würden.

### KI-Anfragen (`app/Lessons/Ai/Prompts.php`, `resources/prompts/*.md`)

- Analyse: drei Quellen-Varianten gemäss Tabelle; Regeln für «Fotos + Auftrag» im System-Prompt `analyse.md`.
- Der Auftrag geht als «Auftrag der Eltern» an **alle** Schritte (Analyse, Module, Prüfung, Grafik), über `Prompts::context()`.

### Lücken ergänzen

Zwei Fälle: Die Fotos sind zu dünn (z. B. nur ein Schema oder nur Aufgaben ohne Erklärtext), oder der Auftrag nennt ein Thema, das auf den Fotos fehlt.

- Die KI darf in beiden Fällen aus Fachwissen ergänzen. Was von den Fotos kommt, hat Vorrang: Ergänzungen verwenden die Begriffe und Definitionen des Buchs und widersprechen ihnen nicht.
- Jeder Block (Fakt, Spalte, Quizfrage, Karte, Begriff im Sortierspiel, Lückentext) erhält eine Herkunft: `foto` oder `ergaenzt` (Feld `herkunft` im Schema, Standard `foto`; bei «Nur Auftrag» alles `ergaenzt`, dort aber ohne Markierung).
- Die Analyse liefert `ergaenzungen[]`: pro Ergänzung ein Satz, was fehlte und was ergänzt wurde.
- Der Prüf-Schritt prüft ergänzte Teile besonders streng.
- **Nur für Eltern sichtbar:** Banner im Review («3 Teile stammen nicht aus den Fotos, bitte prüfen») und Badge «ergänzt» an den Blöcken in Ansicht und Bearbeiten, dort einzeln entfernbar. Nach dem Freigeben gilt der Inhalt als geprüft; das Kind sieht keine Markierung (`LessonView` lässt `herkunft` in der geteilten Ansicht weg).
- Sind die Fotos gar nicht lesbar oder ohne Schulstoff, bleibt es beim heutigen Abbruch (`quelle.lesbar = false`).

### Validierung (`StoreLessonRequest`)

- `prompt`: `nullable|string|max:1000`
- `images`: `required_without:prompt|array|max:4`, `source`/`topic`/`notes` entfallen als Eingaben.

---

## Teil 2 – Grafiken selbst bestimmen

Umgesetzt (2026-10-02), Plan: `docs/plans/2026-10-02-teil-2-grafiken.md`. Abweichungen und Entscheide unten unter «Umsetzung».

### Verhalten

- Abschnitt «Grafiken» im Formular statt der Checkbox, mit drei Modi:
  - **Keine**
  - **KI entscheidet** (Standard, wie heute: höchstens eine Grafik, KI wählt Muster und Idee)
  - **Selbst beschreiben**: bis zu 3 Grafiken. Pro Grafik eine Beschreibung (was soll sie zeigen, was kann man tun) und optional ein Muster (Regler, Ansichten, Schritte, Zeitstrahl, Hotspots, Rechner; Standard «KI wählt»).
- Die Analyse macht aus jedem Wunsch einen Plan innerhalb des Foto-Rahmens. Passt ein Wunsch nicht zum Stoff, wird das gemeldet (statt erfunden) und die Grafik entfällt mit Hinweis.
- Das Formular zeigt, dass jede Grafik die Erstellung verlängert und Kosten verursacht.

### Platzierung

- Grafik 1 ist der Hero oben auf der Seite (wie heute).
- Weitere Grafiken stehen **im passenden Abschnitt**: neuer Block-Typ `grafik` in `abschnitte[].bloecke[]` mit Verweis auf die Grafik (`{ "typ": "grafik", "nr": 2 }`). Die Analyse setzt den Block neben die Erklärung, die die Grafik veranschaulicht.

### Daten

- Neue Tabelle `lesson_graphics`: `lesson_id`, `position` (1 = Hero), `request` (Wunsch der Eltern, nullable), `pattern` (gewünscht, nullable), `plan` (json: muster, idee), `graphic` (json: muster, beschreibung, css, markup, script), `error`, Timestamps.
- `lessons.with_hero` wird zu `lessons.graphics_mode` (`none` | `auto` | `custom`).
- Migration: bestehendes `hero_plan`/`hero`/`hero_error` → `lesson_graphics` Position 1; danach Spalten entfernen.
- `hidden` (boolean): von den Eltern ausgeblendet (siehe «Umsetzung»).

### Erzeugung

- Analyse liefert `grafik_plaene[]` statt `hero_plan` (Schema in `Schemas.php`).
- Pro Grafik ein eigener Job (`GenerateLessonGraphic`), mit Validierung (`HeroValidator`) und einer Reparatur, unabhängig voneinander. Eine fehlerhafte Grafik kippt weder die Seite noch die anderen Grafiken.
- Module-Prompt kennt alle Grafik-Pläne (Quizfragen dürfen sich auf vorhandene Grafiken beziehen).
- «Neu generieren» pro Grafik (Route `lessons/{lesson}/grafik/{nr}/neu`), signierte iframe-URL pro Grafik.

### Darstellung

- `HeroFrame` wird für jede Grafik verwendet; `LessonBlock` rendert den Block `grafik` mit dem passenden Frame.
- Bearbeiten-Ansicht: Grafik-Block entfernen (verschieben ist nicht umgesetzt).

### Umsetzung (Entscheide)

- **Feste Jobs pro Position:** Die Pipeline stellt für jede mögliche Position einen `GenerateLessonGraphic`-Job ein (1 bei «KI entscheidet», 1–3 bei «Selbst beschreiben»). Ein Job ohne Plan für seine Position tut nichts. So bleibt die Pipeline unabhängig davon, wie viele Pläne die Analyse liefert.
- **Ersatz-Platzierung:** `LessonView` entfernt Blöcke von Grafiken, die nicht fertig sind. Eine fertige Grafik 2 oder 3 ohne Block kommt ans Ende des letzten Abschnitts, damit sie nie verloren geht. Doppelte Blöcke derselben Grafik werden verworfen (jede Grafik erscheint einmal).
- **Ausblenden:** Entfernen Eltern in der Bearbeiten-Ansicht den Block einer Grafik, wird sie ausgeblendet (`lesson_graphics.hidden`): kein Block, keine Ersatz-Platzierung, keine URL, die signierte Route antwortet mit 404. Sie bleibt gespeichert. Kommt der Block zurück oder wird die Grafik neu erstellt, ist sie wieder sichtbar (nach dem Neu-Erstellen am Ende des letzten Abschnitts). Im Menü steht «(ausgeblendet)».
- **Alte URL entfernt:** Die frühere Route ohne Nummer (`lernseiten/{lesson}/neu/grafik`) gibt es nicht mehr; nur noch `lernseiten/{lesson}/grafik/{nr}/neu`.
- **Kein Modus, kein Upload:** Ein Upload ohne `graphics_mode` wird abgelehnt (kein stiller Standard).
- **Kein Menüpunkt ohne Grafik:** «Grafik neu erstellen» gibt es nur für Grafiken, die als Zeile existieren und einen Plan haben.
- **Alte Spalten entfernt:** `lessons.hero`, `hero_plan`, `hero_error` und `with_hero` sind weg (Migration `2026_10_02_150000`). Beim Rollback kommen sie zurück, mit Grafik 1; Grafiken 2–3, Wünsche und «ausgeblendet» kennen die alten Spalten nicht. `LessonView` liefert weiterhin `hero` (= Grafik 1) für den Kopf der Seite.

---

## Teil 3 – Plan bestätigen, Upload-Extras, Umfang und Zweck

### 3a Plan bestätigen, bevor es teuer wird

Heute läuft alles in einem Durchgang; Fehllesungen oder schwache Grafik-Ideen fallen erst am Ende auf, nachdem Module, Prüfung und Grafiken bezahlt sind.

**Ablauf**

1. **Planung** (neuer, leichter Call mit Fotos): liefert `quelle`, `zusammenfassung`, Titel, Kernidee, geplante Abschnitte (Titel + ein Satz), `ergaenzungen[]` und `grafik_plaene[]`.
2. Neuer Status **`planned`** («Plan prüfen»). Die Lernseite zeigt den Plan statt des Fortschritts.
3. Eltern können:
   - Abschnitte entfernen oder umbenennen,
   - Ergänzungen streichen,
   - Grafik-Beschreibungen ändern, entfernen oder eine hinzufügen (max. 3),
   - eine Anmerkung zum Plan schreiben («mehr Gewicht auf …»),
   - dann **Erstellen** oder **Verwerfen**.
4. Nach «Erstellen» läuft die bisherige Kette: Textteil (mit Fotos + bestätigtem Plan) → Module → Prüfung → Grafiken → fertig.

**Details**

- Checkbox im Formular «Plan vorher anzeigen» (Standard an). Aus: der Plan wird ohne Halt bestätigt (heutiges Verhalten).
- Der bestätigte Plan wird gespeichert (`lessons.plan`, json) und geht an alle folgenden Schritte.
- Fotos bleiben bis nach dem Textteil gespeichert. Unbestätigte Pläne verfallen nach 7 Tagen: Fotos werden gelöscht, die Lernseite wird verworfen (geplanter Befehl, täglich).
- Kosten: Der Plan zeigt die bisherigen Kosten und eine grobe Schätzung für den Rest (abhängig von Umfang und Anzahl Grafiken).
- «Nochmals planen» mit geänderter Anmerkung möglich (ein weiterer Planungs-Call).

### 3b Upload-Extras (`Create.vue`)

- **Drag & drop** auf den ganzen Foto-Bereich, mit sichtbarer Drop-Zone.
- **Einfügen** aus der Zwischenablage (Cmd/Ctrl+V, z. B. Screenshot).
- **Reihenfolge** per Ziehen ändern (Maus und Touch, plus Pfeil-Buttons für Tastatur). Die Reihenfolge wird als `position` gespeichert und so an die KI geschickt («Seite 1 von 3»).
- **Kamera direkt** auf dem Handy: zusätzlicher Button «Foto aufnehmen» (`capture="environment"`).
- **Qualitätscheck im Browser** nach dem Verkleinern: Helligkeit und Schärfe (Varianz des Laplace-Filters auf Canvas). Bei schlechtem Wert ein Hinweis am Foto («Wirkt unscharf – nochmals aufnehmen?»), Upload bleibt möglich.
- Umgesetzt (2026-10-02): Die Fotoauswahl ist eine eigene Komponente (`PhotoPicker.vue`), alle Quellen laufen über `addFiles()`. Reihenfolge per nativem HTML5-Drag-&-drop für die Maus, dazu Pfeil-Buttons für Touch und Tastatur (natives Ziehen geht auf Touchscreens nicht); jedes Foto zeigt seine Nummer. Die KI bekommt bei 2+ Fotos den Satz «Die Fotos sind in der Reihenfolge der Seiten: Foto 1 ist die erste Seite.» Die Kachel «Foto aufnehmen» erscheint nur auf Touch-Geräten (`pointer: coarse`), weil Desktop-Browser `capture` ignorieren. Beim Einfügen in ein Textfeld gewinnt Text, wenn die Zwischenablage welchen enthält. Qualitätscheck in `lib/imageQuality.ts`; die Schwellen (Helligkeit < 70, Laplace-Varianz < 60) sind noch nicht an echten Fotos geprüft.

### 3c Umfang und Zweck

- **Zweck:** «Neuer Stoff» (mehr Erklärung, behutsamer Einstieg) oder «Prüfungsvorbereitung» (mehr Übung, Zusammenfassung am Schluss, Fokus auf Begriffe). Standard: «Neuer Stoff».
- **Umfang:** Kurz / Normal / Ausführlich. Steuert Anzahl Abschnitte, Quizfragen, Karteikarten und Sortier-Begriffe über feste Richtwerte in der Config (`lessons.scope`).
- **Module:** Quiz, Sortierspiel, Karteikarten, Lückentext einzeln abwählbar (mindestens eines). Das Inhaltsschema erlaubt dafür fehlende Module; Ansicht und Lernstand kommen damit zurecht.
- Neue Spalten: `lessons.purpose`, `lessons.scope`, `lessons.modules` (json).
- Einstellungen werden pro Kind und Fach gemerkt und beim nächsten Mal vorausgefüllt (zuletzt verwendete Werte, kein eigenes UI).
- Umgesetzt (2026-10-02), Plan: `docs/plans/2026-10-02-teil-3c-umfang.md`. `modules` heisst **erlaubte** Module: ein angekreuztes Quiz kommt immer, die anderen nur, wenn sie zum Stoff passen; der Generator setzt nicht erlaubte Module nach dem Modul-Schritt auf null. Das Quiz ist im Inhalt optional (`module.quiz` darf fehlen). Alte Lernseiten haben `modules = null`, das heisst alle erlaubt. Die Anzahlen pro Umfang stehen nur in `config('lessons.scope')`, die Prompts lesen sie von dort. «Gemerkt» heisst: die Werte der jüngsten nicht gelöschten Lernseite der Eltern für dasselbe Kind und Fach (Fach klein geschrieben und ohne Leerzeichen am Rand verglichen), berechnet in `LessonController::create()`, ohne eigene Tabelle. Vorausgefüllt werden Zweck, Umfang, Module und Grafik-Modus, nur für Felder, die im Formular noch nicht von Hand geändert wurden; eigene Grafikwünsche werden nicht gemerkt, ein gemerktes «Selbst beschreiben» wird zu «KI entscheidet». Ohne Kind (erste Lernseite) gibt es nichts vorauszufüllen. Grafiken, Zweck, Umfang und Module stehen im zugeklappten Bereich «Mehr Optionen»; Fehler darin klappen ihn auf.

---

## Teil 5 – Fachprofile

Das System ist heute auf Naturwissenschaften zugeschnitten (Referenzen Fotosynthese/Ökosystem, «probieren/Experimente», Sortieren nach Kategorien, Grafik-Muster). Statt eigener Templates gibt es **Fachprofile**: gleicher Seitenaufbau, gleiche Bausteine, aber pro Profil:

1. **Prompt-Zusatz** für Analyse/Planung, Module und Prüfung (`resources/prompts/profile/<profil>.md`), inkl. eines kurzen Beispiels.
2. **Erlaubte und bevorzugte Blöcke und Module.**
3. **Standards:** Grafik-Modus, Module (Teil 3c), Modell pro Schritt (Teil 4).

### Zuordnung

- Profil wird aus dem Fach abgeleitet (Mapping in `config/lessons.php`, z. B. Französisch/Englisch → `sprachen`, Mathematik → `mathematik`, Geometrie → `geometrie`, Deutsch → `deutsch`, Biologie/Chemie/Physik/NT → `naturwissenschaften`; sonst `allgemein`).
- Im Formular (unter «Mehr Optionen») änderbar. Gespeichert als `lessons.profile`.

### Profile

**Naturwissenschaften** – heutiges Verhalten, unverändert.

**Allgemein** – heutiges Verhalten ohne Pflicht zu Experimenten (z. B. Geschichte, Geografie; Zeitstrahl und Karte sind vorhandene Grafik-Muster).

**Sprachen** (Französisch, Englisch)
- Block `vokabeln`: Tabelle Fremdsprache ↔ Deutsch, optional Wortart, Genus, Beispielsatz.
- Block `konjugation`: Verb-Tabelle (Personen × Formen).
- Grammatikregel als `box` mit Beispielsätzen.
- Karteikarten **in beide Richtungen** (Umschalter im Modul).
- Lückentext und Karten: Akzente zählen. Fehlt nur ein Akzent, gilt die Antwort als «fast richtig» mit Hinweis («Achte auf den Akzent: é»).
- **Aussprache** per Browser-Sprachausgabe (`speechSynthesis`, Sprache aus dem Profil), Button an Vokabeln und Karten. Keine Kosten, kein Netzwerk.
- Kein `probieren`, Grafik-Modus standardmässig «Keine».
- Modelle: Analyse Opus (Fotos lesen), alles andere Sonnet.

**Mathematik**
- Block `rechenweg`: Aufgabe, nummerierte Schritte mit Begründung, Resultat.
- Modul `aufgaben`: Übungsaufgaben mit eigener Eingabe; Zahl mit Toleranz und Einheit oder exakter Ausdruck (Brüche gekürzt vergleichen); Tipp und Lösungsweg nach der Antwort. Ergebnisse gehen in den Lernstand.
- **Formeln mit KaTeX** (neue Abhängigkeit, im Profil Mathematik/Geometrie/Naturwissenschaften): TeX in `$…$` innerhalb von Texten, gerendert in `LessonBlock` und Modulen; serverseitig validiert (Parse-Fehler → Reparatur).
- Kein `probieren`; Grafik-Muster `rechner` bevorzugt.

**Geometrie**
- Alles aus Mathematik.
- Block `figur`: statische SVG-Figur mit Beschriftung (Punkte, Strecken, Winkel, Flächen) aus einer **eingeschränkten Beschreibung** (JSON: Punkte mit Koordinaten, Linien, Bögen, Flächen, Labels), die die App selbst zu SVG rendert – kein freies SVG vom Modell. Hell/Dunkel über die Kategorie-Farben.
- Interaktive Konstruktionen über Teil 2 (Grafiken beschreiben).

**Deutsch**
- Regel-Box mit Beispielen und Gegenbeispielen.
- Modul `fehler_finden`: Satz antippen, falsches Wort markieren, Korrektur eingeben.
- Lückentext für Rechtschreibung (Gross/Klein zählt hier, abweichend vom Standard).
- Kein `probieren`, Grafik-Modus standardmässig «Keine».

### Auswirkungen

- Schema: neue Block-Typen und Module optional; `ContentValidator` prüft sie nur, wenn das Profil sie erlaubt.
- Bearbeiten-Ansicht: Editoren für `vokabeln`, `konjugation`, `rechenweg`, `aufgaben`, `fehler_finden`; `figur` vorerst nur entfernen, nicht bearbeiten.
- Lernstand: `aufgaben` und `fehler_finden` speichern Versuche wie Quiz.
- Fixtures: je Profil eine Beispielseite (z. B. «Passé composé», «Dreisatz», «Winkel an Parallelen», «Das und dass») für Tests, Fake-Modell und als Prompt-Beispiel.

---

## Tests (Pest)

- Teil 5: Fach → Profil-Zuordnung und Override; Prompt-Zusatz erscheint je Profil; neue Blöcke/Module validieren und rendern; Antwortprüfung `aufgaben` (Toleranz, Brüche, Einheiten); `figur`-JSON → SVG (ungültige Koordinaten abgewiesen); KaTeX-Fehler lösen Reparatur aus; Versuche aus neuen Modulen erscheinen im Lernstand.

- Teil 4: Jeder Schritt nutzt Modell und Effort aus der Config (Override per `.env`); Prüf-Korrekturen werden angewendet, ungültige Pfade verworfen; Kosten pro Schritt korrekt nach Modellpreis berechnet.

- Teil 1: Fotos / Auftrag / beides / nichts; Auftrag erscheint in Analyse-, Module-, Prüf- und Grafik-Anfrage; ergänzte Blöcke zeigen Badge und Banner in der Eltern-Ansicht, aber kein `herkunft` in der geteilten Ansicht.
- Teil 2: Modi `none`/`auto`/`custom`; mehrere Grafiken mit einer fehlerhaften (andere bleiben); Neu-generieren einer einzelnen Grafik; Migration bestehender Heroes; Block `grafik` mit ungültiger Nummer wird vom `ContentValidator` abgewiesen.

- Teil 3: Status `planned` hält die Kette an; Plan bearbeiten und bestätigen startet die Kette mit dem Plan im Prompt; «Plan vorher anzeigen» aus läuft durch; verfallene Pläne werden aufgeräumt (Fotos gelöscht); Umfang/Zweck/Module erscheinen im Prompt; abgewählte Module fehlen im Inhalt und die Ansicht rendert trotzdem.

## Offen / folgt

- Später denkbar: auf einer bestehenden Lernseite aufbauen, Vokabel-Modus für Sprachen.

- Weitere Teile (vom Auftraggeber angekündigt).
