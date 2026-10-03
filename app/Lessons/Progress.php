<?php

namespace App\Lessons;

use App\Models\Attempt;
use App\Models\Child;
use App\Models\Lesson;
use Illuminate\Support\Collection;

/**
 * Progress: per item (quiz question, sorting term, gap, exercise, sentence with a mistake) a status from the latest answers.
 *
 * - mastered: the last two answers were correct
 * - almost: the last answer was correct, the one before wrong or only one attempt (in the quiz it may have been a guess)
 * - practice: the last answer was wrong
 * - open: never answered
 */
class Progress
{
	public const STATUSES = ['mastered', 'almost', 'practice', 'open'];

	// Modules whose answers are checked and counted; flashcards are only flipped
	public const MODULES = ['quiz', 'sorting', 'cloze', 'exercises', 'find_the_mistake'];

	/**
	 * All items of a lesson that the progress tracks.
	 *
	 * @return list<array{module: string, id: string, text: string}>
	 */
	public static function items(Lesson $lesson): array
	{
		$module = $lesson->content['modules'] ?? [];
		$items = [];

		foreach ($module['quiz'] ?? [] as $question) {
			$items[] = ['module' => 'quiz', 'id' => $question['id'], 'text' => $question['question']];
		}

		foreach ($module['sorting']['terms'] ?? [] as $term) {
			$items[] = ['module' => 'sorting', 'id' => $term['id'], 'text' => $term['text']];
		}

		foreach ($module['cloze']['segments'] ?? [] as $segment) {
			if (isset($segment['answers'])) {
				$items[] = ['module' => 'cloze', 'id' => $segment['id'], 'text' => 'Lücke: '.$segment['answers'][0]];
			}
		}

		foreach ($module['exercises']['entries'] ?? [] as $entry) {
			$items[] = ['module' => 'exercises', 'id' => $entry['id'], 'text' => $entry['question']];
		}

		foreach ($module['find_the_mistake']['entries'] ?? [] as $entry) {
			$items[] = ['module' => 'find_the_mistake', 'id' => $entry['id'], 'text' => $entry['sentence']];
		}

		return $items;
	}

	/**
	 * Checks an answer against the content. Correctness never comes from the browser.
	 * Only a gap can be «almost» right (accents); the progress counts that as wrong.
	 *
	 * @return AnswerResult|null null if the item doesn't exist (any more)
	 */
	public static function check(Lesson $lesson, string $module, string $itemId, mixed $answer): ?AnswerResult
	{
		$content = $lesson->content['modules'] ?? [];

		$item = match ($module) {
			'quiz' => self::find($content['quiz'] ?? [], $itemId),
			'sorting' => self::find($content['sorting']['terms'] ?? [], $itemId),
			'cloze' => self::find($content['cloze']['segments'] ?? [], $itemId),
			'exercises' => self::find($content['exercises']['entries'] ?? [], $itemId),
			'find_the_mistake' => self::find($content['find_the_mistake']['entries'] ?? [], $itemId),
			default => null,
		};

		if ($item === null) {
			return null;
		}

		if ($module === 'exercises') {
			return is_string($answer) || is_int($answer) || is_float($answer) ? ExerciseAnswer::check($item, (string) $answer) : AnswerResult::Wrong;
		}

		if ($module === 'find_the_mistake') {
			return is_array($answer) ? MistakeAnswer::check($item, $answer) : AnswerResult::Wrong;
		}

		if ($module === 'cloze') {
			$caseSensitive = ($content['cloze']['case_sensitive'] ?? null) === true;

			return is_string($answer) ? ClozeParser::check($answer, $item['answers'], $caseSensitive) : AnswerResult::Wrong;
		}

		$correct = $module === 'quiz'
			? is_int($answer) && $answer === $item['answer']
			: $answer === $item['category'];

		return $correct ? AnswerResult::Correct : AnswerResult::Wrong;
	}

	/**
	 * Status of all items of a lesson for a child.
	 *
	 * @return list<array{module: string, id: string, text: string, status: string}>
	 */
	public static function forLesson(Child $child, Lesson $lesson): array
	{
		return self::withStatus(self::items($lesson), self::attempts($child, [$lesson->id])[$lesson->id] ?? []);
	}

	/**
	 * Summary per lesson: count per status and the items not yet mastered.
	 *
	 * @param  Collection<int, Lesson>  $lessons
	 * @return array<int, array{counts: array<string, int>, total: int, open: list<array{module: string, id: string, text: string, status: string}>}>
	 */
	public static function summaries(Child $child, Collection $lessons): array
	{
		$attempts = self::attempts($child, array_values($lessons->map(fn (Lesson $lesson) => $lesson->id)->all()));
		$summaries = [];

		foreach ($lessons as $lesson) {
			$items = self::withStatus(self::items($lesson), $attempts[$lesson->id] ?? []);
			$counts = array_fill_keys(self::STATUSES, 0);

			foreach ($items as $item) {
				$counts[$item['status']]++;
			}

			// First what needs practice, then what is almost mastered
			$open = array_values(array_filter($items, fn ($item) => in_array($item['status'], ['practice', 'almost'], true)));
			usort($open, fn ($a, $b) => ($a['status'] === 'practice' ? 0 : 1) <=> ($b['status'] === 'practice' ? 0 : 1));

			$summaries[$lesson->id] = [
				'counts' => $counts,
				'total' => count($items),
				'open' => $open,
			];
		}

		return $summaries;
	}

	/**
	 * @param  list<array{module: string, id: string, text: string}>  $items
	 * @param  list<Attempt>  $attempts  answers of a lesson, newest first
	 * @return list<array{module: string, id: string, text: string, status: string}>
	 */
	private static function withStatus(array $items, array $attempts): array
	{
		$byItem = [];
		foreach ($attempts as $attempt) {
			$byItem[$attempt->module.':'.$attempt->item_id][] = $attempt->correct;
		}

		return array_map(function (array $item) use ($byItem) {
			$last = array_slice($byItem[$item['module'].':'.$item['id']] ?? [], 0, 2);

			$status = match (true) {
				$last === [] => 'open',
				! $last[0] => 'practice',
				count($last) === 2 && $last[1] => 'mastered',
				default => 'almost',
			};

			return [...$item, 'status' => $status];
		}, $items);
	}

	/**
	 * @param  list<int>  $lessonIds
	 * @return array<int, list<Attempt>> answers per lesson, newest first
	 */
	private static function attempts(Child $child, array $lessonIds): array
	{
		$grouped = [];

		$query = $child->attempts()
			->whereIn('lesson_id', $lessonIds)
			->orderByDesc('created_at')
			->orderByDesc('id');

		foreach ($query->get() as $attempt) {
			$grouped[$attempt->lesson_id][] = $attempt;
		}

		return $grouped;
	}

	/**
	 * @param  array<int, mixed>  $list
	 * @return array<string, mixed>|null
	 */
	private static function find(array $list, string $id): ?array
	{
		foreach ($list as $entry) {
			if (is_array($entry) && ($entry['id'] ?? null) === $id) {
				return $entry;
			}
		}

		return null;
	}
}
