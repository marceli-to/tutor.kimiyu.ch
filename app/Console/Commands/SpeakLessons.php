<?php

namespace App\Console\Commands;

use App\Actions\Generation\SpeakLesson;
use App\Lessons\Speech\ElevenLabs;
use App\Models\Lesson;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lessons:speak {lesson? : ID of one lesson; without it, every language lesson}')]
#[Description('Has ElevenLabs read the foreign words of existing lessons aloud (only new words cost credits)')]
class SpeakLessons extends Command
{
	public function handle(SpeakLesson $speakLesson, ElevenLabs $elevenLabs): int
	{
		if (blank(config('speech.key'))) {
			$this->error('ELEVENLABS_API_KEY fehlt.');

			return self::FAILURE;
		}

		$lessons = Lesson::query()
			->whereNotNull('content')
			->when($this->argument('lesson'), fn ($query, $id) => $query->whereKey($id))
			->with('child')
			->get()
			->filter(fn (Lesson $lesson) => $lesson->speaksWithElevenLabs());

		$rows = [];
		$stopped = null;

		foreach ($lessons as $lesson) {
			$run = $speakLesson->handle($lesson);
			$rows[] = [$lesson->id, $lesson->displayTitle(), $run['created'], $run['reused'], $run['credits'], $run['stopped'] ?? ''];

			if (in_array($run['stopped'], ['quota', 'error'], true)) {
				$stopped = $run['stopped'];

				break;
			}
		}

		$this->table(['ID', 'Lernseite', 'Neu', 'Wiederverwendet', 'Credits', 'Abbruch'], $rows);

		$left = $elevenLabs->remainingCredits();
		if ($left !== null) {
			$this->info('Credits übrig diesen Monat: '.number_format($left, 0, '.', '’'));
		}

		if ($stopped !== null) {
			$this->error($stopped === 'quota' ? 'Credits aufgebraucht.' : 'ElevenLabs-Fehler, siehe Kosten-Seite oder Log.');

			return self::FAILURE;
		}

		return self::SUCCESS;
	}
}
