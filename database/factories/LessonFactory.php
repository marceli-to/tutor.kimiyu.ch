<?php

namespace Database\Factories;

use App\Enums\LessonStatus;
use App\Lessons\ContentValidator;
use App\Models\Child;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\File;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
	public const FIXTURES = ['fotosynthese', 'oekosystem'];

	/**
	 * @return array<string, mixed>
	 */
	public function definition(): array
	{
		return [
			'child_id' => Child::factory(),
			'status' => LessonStatus::Draft,
			'subject' => 'Biologie',
			'level' => '2. Sek',
		];
	}

	/**
	 * Published page with content and graphic 1 from database/fixtures/lessons.
	 */
	public function fromFixture(string $name = 'fotosynthese'): static
	{
		$content = self::fixture($name);

		return $this->state(fn () => [
			'status' => LessonStatus::Published,
			'title' => $content['meta']['title'],
			'schema_version' => ContentValidator::SCHEMA_VERSION,
			'content' => $content,
			'published_at' => now(),
		])->afterCreating(function (Lesson $lesson) use ($name) {
			$graphic = self::fixture("$name.graphic");

			$lesson->graphics()->create([
				'position' => 1,
				'plan' => ['pattern' => $graphic['pattern'], 'idea' => $graphic['description']],
				'graphic' => $graphic,
			]);
		});
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function fixture(string $name): array
	{
		return json_decode(
			File::get(database_path("fixtures/lessons/$name.json")),
			true,
			flags: JSON_THROW_ON_ERROR,
		);
	}
}
