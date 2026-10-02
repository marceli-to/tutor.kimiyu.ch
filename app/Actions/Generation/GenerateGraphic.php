<?php

namespace App\Actions\Generation;

use App\Lessons\Ai\ModelException;
use App\Lessons\Ai\Prompts;
use App\Lessons\HeroValidator;
use App\Models\Lesson;

class GenerateGraphic
{
    public function __construct(private CallModel $callModel) {}

    /**
     * Die Grafik an Position $position. Scheitert sie, fehlt nur diese Grafik, mit einem Hinweis.
     * Beim Neu-Erstellen ($keepExisting) bleibt die bisherige Grafik, wenn die neue scheitert.
     * Ohne Plan (abgewählt, kein Muster passt oder der Wunsch passt nicht zum Stoff) passiert nichts.
     *
     * @return bool whether a new graphic was stored
     */
    public function handle(Lesson $lesson, int $position, bool $keepExisting = false): bool
    {
        $graphic = $lesson->graphic($position);

        if ($graphic?->plan === null) {
            return false;
        }

        $fail = function (string $message) use ($graphic, $keepExisting) {
            $old = $keepExisting ? $graphic->graphic : null;

            $graphic->update([
                'graphic' => $old,
                'error' => $old ? $message.' Die bisherige Grafik bleibt.' : $message,
            ]);
        };

        try {
            $hero = $this->callModel->handle($lesson, Prompts::hero($lesson, $graphic))->data;
            $errors = HeroValidator::errors($hero);

            if ($errors !== []) {
                $hero = $this->callModel->handle($lesson, Prompts::heroRepair($graphic, $hero, $errors))->data;
                $errors = HeroValidator::errors($hero);
            }
        } catch (ModelException $e) {
            $fail($e->getMessage());

            return false;
        }

        if ($errors !== []) {
            $fail('Die Grafik war fehlerhaft: '.implode(' ', $errors));

            return false;
        }

        $graphic->update([
            'graphic' => [
                'pattern' => $hero['pattern'],
                'description' => $hero['description'],
                'css' => $hero['css'],
                'markup' => $hero['markup'],
                'script' => $hero['script'],
            ],
            'error' => null,
            // Eine neu erstellte Grafik ist wieder sichtbar (am Ende des letzten Abschnitts, ohne Baustein)
            'hidden' => false,
        ]);

        return true;
    }
}
