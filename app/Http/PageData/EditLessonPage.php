<?php

namespace App\Http\PageData;

use App\Lessons\ClozeParser;
use App\Lessons\GraphicBlocks;
use App\Lessons\Palettes;
use App\Models\Lesson;
use App\Models\LessonGraphic;
use Illuminate\Support\Str;

/**
 * Props for lessons/Edit: the content with blocks for unplaced graphics, the cloze as markup, labels and palettes.
 */
class EditLessonPage
{
    public function __construct(private Lesson $lesson) {}

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        $cloze = $this->lesson->content['modules']['cloze'] ?? null;

        return [
            'lesson' => [
                'id' => $this->lesson->id,
                'status' => $this->lesson->status->value,
                'childName' => $this->lesson->child->name,
                'content' => GraphicBlocks::withUnplaced($this->lesson, $this->lesson->content),
            ],
            'showOrigin' => ! $this->lesson->isFromTopic(),
            'clozeMarkup' => $cloze ? ClozeParser::toMarkup($cloze['segments']) : null,
            'graphicLabels' => $this->graphicLabels(),
            'palettes' => collect(Palettes::all())
                ->map(fn (array $palette, string $key) => ['value' => $key, 'label' => $palette['label'], 'accent' => $palette['light']['accent']])
                ->values(),
        ];
    }

    /**
     * Kurzer Text pro Grafik für die Bearbeiten-Ansicht: Beschreibung der fertigen Grafik,
     * sonst die Idee aus dem Plan, sonst der Wunsch der Eltern.
     *
     * @return array<int, string>
     */
    private function graphicLabels(): array
    {
        return $this->lesson->graphics
            ->mapWithKeys(fn (LessonGraphic $graphic) => [$graphic->position => Str::limit(
                (string) ($graphic->graphic['description'] ?? $graphic->plan['idea'] ?? $graphic->request ?? ''),
                120,
                '…',
            )])
            ->all();
    }
}
