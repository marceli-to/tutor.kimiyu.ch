Deutsch: Die Seite übt Rechtschreibung, Grammatik, Zeichensetzung oder Textarbeit.

- Keine Experimente: Es gibt kein Feld `try_it`.
- Eine Regel als Baustein `box`: die Regel in einem Satz, dann Beispiele und Gegenbeispiele, je ein Absatz. Wo es eine Probe gibt (Ersatzprobe, Artikelprobe, Verlängern), zeigst du sie an einem Beispiel.
- Beispielsätze kurz und aus dem Alltag des Kindes.
- Formeln (`formula`) brauchst du hier nicht.
- Grafiken braucht es meistens nicht: Rechtschreib- und Grammatikregeln zeigt keine interaktive Grafik besser als eine Regel mit Beispielen.
- Lückentext für Rechtschreib- und Grammatikregeln. Bei Rechtschreibthemen (Gross- und Kleinschreibung, das/dass, Nomen erkennen) setzt du `case_sensitive` auf true: dann zählt Gross- und Kleinschreibung, und eine Lücke am Satzanfang hat die grossgeschriebene Lösung («Das»). Sonst `case_sensitive` null.
- Quizfragen mit typischen Fehlern als falschen Optionen.
- Das Lernmodul `find_the_mistake` (Fehler finden) passt zu Regeln wie das/dass, Kommas, Gross- und Kleinschreibung, Fall nach Präpositionen; die Anzahl steht in «Erlaubte Lernmodule». Wenn das Thema keine einzelnen falschen Wörter hat (z. B. Textsorten, Aufsatz), setzt du es auf null. `instructions` ein Satz oder null. Pro Satz in `entries`:
    - `id`: `f1`, `f2` …
    - `sentence`: ein Satz mit genau einem falschen Wort, sonst fehlerfrei.
    - `mistake_word`: die Position des falschen Worts, 0-basiert. Wörter sind durch Leerzeichen getrennt, Satzzeichen gehören zum Wort davor oder danach: in «Ich hoffe, das du kommst.» ist «das» das Wort 2.
    - `correction`: das Wort richtig geschrieben, genau so, wie es im Satz stehen muss (Gross- und Kleinschreibung zählt). Fehlt ein Komma, ist das Wort vor dem Komma falsch und die Korrektur hat das Komma («glaube,»). Satzzeichen, die schon am Wort hängen, darf das Kind weglassen.
    - `explanation`: warum, ein bis zwei Sätze, mit der Regel oder Probe.
