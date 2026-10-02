Mathematik: Die Seite erklärt ein Verfahren und warum es funktioniert. Das Kind soll danach selbst rechnen können.

- Keine Experimente: Es gibt kein Feld `try_it`.
- Bausteine: `paragraph`, `formula`, `facts`, `box`, `graphic` und `worked_solution`. Spalten (`columns`) gibt es hier nicht; zwei Verfahren vergleichst du in einer `box` oder in `facts`.
- Lernmodule: `quiz`, `flashcards` (z. B. vorne die Formel, hinten was sie bedeutet), `cloze` und `exercises`. Ein Sortierspiel gibt es hier nicht.
- Formeln schreibst du in TeX zwischen Dollarzeichen: `$\frac{3}{4}$`, `$2.50 \cdot 5 = 12.50$`; eine abgesetzte Formel zwischen `$$…$$`. Andere Begrenzer wie `\(…\)` oder `\[…\]` gibt es nicht. Jedes `$` wird geschlossen, geschweifte Klammern gehen auf. Ein Dollarzeichen als Währung gibt es nicht, Geld schreibst du in Franken («Fr. 7.50»). Einfache Zahlen im Fliesstext brauchen kein TeX.
- Zahlen im Schweizer Format: Dezimalkomma (im TeX `3{,}5`), Tausender mit «'» (im TeX `1\,250`); Geldbeträge wie üblich mit Punkt («Fr. 12.50»).
- Formeln und Regeln als Baustein `formula`, mit einem Satz in `addendum`, was sie bedeuten.
- Immer genau ein durchgerechnetes Beispiel als Baustein `worked_solution`: `task` (die Aufgabe in einem Satz), `steps` mit 2–8 Schritten und `result` (das Ergebnis als Antwortsatz). Pro Schritt `text` (was gerechnet wird, mit der Rechnung in TeX) und `reason` (warum dieser Schritt, ein Satz) oder null, wenn der Schritt selbsterklärend ist.
- Quizfragen mit konkreten Zahlen; die falschen Optionen entsprechen typischen Rechenfehlern (mal statt geteilt, falsche Einheit, vergessener Schritt).
- Das Lernmodul `exercises` (Aufgaben zum selbst Rechnen) ist immer dabei, die Anzahl steht in «Erlaubte Lernmodule». `instructions` ein Satz oder null. Pro Aufgabe in `entries`:
    - `id`: `a1`, `a2` …
    - `question`: die Aufgabe mit allen Angaben, die man zum Rechnen braucht.
    - `kind`: `number` (eine Zahl), `fraction` (ein Bruch; die App akzeptiert jeden gleichwertigen Bruch und die Dezimalzahl) oder `text` (ein Wort, nur wenn es keine Zahl ist).
    - `answer`: nur die Lösung, ohne Einheit («10.50», «66,7», «3/8»).
    - `tolerance`: erlaubte Abweichung bei gerundeten Ergebnissen (z. B. 0.05 bei «auf eine Stelle runden»), sonst null. Sag in der Aufgabe, wie gerundet wird.
    - `unit`: die Einheit, wie das Kind sie schreiben würde («km», «Fr.», «cm²»), oder null. Die Einheit darf das Kind weglassen.
    - `hint`: ein Tipp für den ersten Fehlversuch, ohne die Lösung zu verraten, oder null.
    - `solution_path`: der Lösungsweg in ein bis drei kurzen Sätzen, mit den Rechnungen in TeX.
- Grafik: Muster `calculator` bevorzugt (Werte eingeben, Rechenweg und Ergebnis sehen).
