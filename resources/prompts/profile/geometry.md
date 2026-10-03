Geometrie: Die Seite erklärt Figuren, Winkel, Konstruktionen und ihre Eigenschaften. Das Kind soll danach selbst Winkel und Längen bestimmen und Figuren konstruieren können.

- Keine Experimente: Es gibt kein Feld `try_it`.
- Bausteine: `paragraph`, `formula`, `box`, `graphic`, `worked_solution` und `figure`. Spalten (`columns`) und Faktenlisten (`facts`) gibt es hier nicht; Regeln und Begriffe fasst du in einer `box` zusammen.
- Lernmodule: `quiz`, `flashcards` (z. B. vorne der Begriff, hinten die Eigenschaft), `cloze` und `exercises`. Ein Sortierspiel gibt es hier nicht.
- Figuren zeigst du nur mit dem Baustein `figure`, nie als Zeichnung in Worten oder als SVG. Die App zeichnet die Figur aus deinen Angaben:
    - `title`: was die Figur zeigt, ein kurzer Satz, oder null. Er dient auch als Beschreibung für Screenreader.
    - `points`: 2–12 Punkte mit `id` (kurz und eindeutig, z. B. `A`, `B`, `G1`), `x` und `y` zwischen 0 und 100 (0/0 ist oben links, y wächst nach unten) und `label` (die Beschriftung, z. B. «A») oder null für Hilfspunkte wie die Enden einer Geraden.
    - `lines`: höchstens 16 Strecken von Punkt `from` zu Punkt `to`, mit `label` (z. B. «g», «a = 5 cm») oder null und `style`: `solid`, oder `dashed` für Hilfslinien.
    - `angles`: höchstens 6 Winkel mit Scheitel `vertex` und den Schenkeln zu den Punkten `from` und `to`; gezeichnet wird immer der Winkel unter 180°. `label` ist der Name oder die Grösse («α», «60°») oder null.
    - Jede `id` in `lines` und `angles` muss es in `points` geben. Beschriftungen ohne TeX: griechische Buchstaben direkt (α, β, γ, δ), Grad mit «°».
    - Zeichne massstabsgetreu: Rechne die Koordinaten so, dass Winkel und Längen der Figur zu den Zahlen im Text passen. Lass rundherum etwas Rand (etwa 5–95).
- Formeln und Regeln (Winkelsumme, Flächen, Umfang) als Baustein `formula` in TeX zwischen Dollarzeichen, mit einem Satz in `addendum`, was sie bedeuten: `$\alpha + \beta = 180°$`, `$A = \frac{g \cdot h}{2}$`. Jedes `$` wird geschlossen, geschweifte Klammern gehen auf; andere Begrenzer wie `\(…\)` gibt es nicht.
- Zahlen im Schweizer Format: Dezimalkomma (im TeX `3{,}5`).
- Immer genau ein durchgerechnetes Beispiel oder eine Konstruktion als Baustein `worked_solution`: `task` (die Aufgabe in einem Satz), `steps` mit 2–8 Schritten (was gerechnet oder gezeichnet wird) und `result` (das Ergebnis als Antwortsatz). Pro Schritt `reason` (warum, z. B. welche Regel gilt) oder null.
- Quizfragen mit konkreten Winkeln und Längen; die falschen Optionen entsprechen typischen Fehlern (Nebenwinkel statt Stufenwinkel, Ergänzung zu 90° statt 180°, Umfang statt Fläche).
- Das Lernmodul `exercises` (Aufgaben zum selbst Rechnen) ist dabei, wenn es in «Erlaubte Lernmodule» steht, mit genau so vielen Aufgaben wie dort angegeben. Bei vielen Aufgaben steigt die Schwierigkeit langsam an: zuerst wie im durchgerechneten Beispiel, dann mit anderen Zahlen und Einheiten, am Schluss Textaufgaben, bei denen das Kind den Rechenweg selbst finden muss. Pro Aufgabe `id` (`a1`, `a2` …), `question` mit allen nötigen Angaben (die Aufgabe steht ohne Figur da), `kind` (meist `number`), `answer` ohne Einheit, `tolerance` (bei gerundeten Ergebnissen, sonst null), `unit` («°», «cm», «cm²») oder null, `hint` oder null und `solution_path` mit den Rechnungen in TeX.
- Grafik: Interaktive Konstruktionen (eine Gerade drehen, einen Punkt verschieben und sehen, was gleich bleibt) gehören in eine Grafik, Muster `sliders` oder `steps` bevorzugt. Die Figur im Text zeigt die Situation fest, die Grafik zum Ausprobieren.
