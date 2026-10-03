<?php

use App\Lessons\LegacyKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	/**
	 * English keys and values in the stored lessons, graphics, attempts and cost log.
	 */
	public function up(): void
	{
		$this->translate(toGerman: false);

		Schema::table('lessons', function (Blueprint $table) {
			$table->string('purpose', 20)->default('new')->change();
		});
	}

	public function down(): void
	{
		Schema::table('lessons', function (Blueprint $table) {
			$table->string('purpose', 20)->default('neu')->change();
		});

		$this->translate(toGerman: true);
	}

	private function translate(bool $toGerman): void
	{
		$content = fn (?string $json) => $this->json($json, $toGerman ? LegacyKeys::contentToGerman(...) : LegacyKeys::contentToEnglish(...));
		$step = fn (?string $value) => $toGerman ? LegacyKeys::stepToGerman($value) : LegacyKeys::stepToEnglish($value);
		$module = fn (?string $value) => LegacyKeys::value($value, LegacyKeys::MODULES, $toGerman);

		$this->update('lessons', ['content', 'check_notes', 'purpose', 'scope', 'modules', 'step'], fn (array $row) => [
			'content' => $content($row['content']),
			'check_notes' => $content($row['check_notes']),
			'purpose' => LegacyKeys::value($row['purpose'], LegacyKeys::PURPOSES, $toGerman),
			'scope' => LegacyKeys::value($row['scope'], LegacyKeys::SCOPES, $toGerman),
			'modules' => $this->json($row['modules'], fn (array $list) => array_map($module, $list)),
			'step' => $step($row['step']),
		]);

		$this->update('lesson_graphics', ['pattern', 'plan', 'graphic'], fn (array $row) => [
			'pattern' => LegacyKeys::value($row['pattern'], LegacyKeys::PATTERNS, $toGerman),
			'plan' => $content($row['plan']),
			'graphic' => $content($row['graphic']),
		]);

		$this->update('attempts', ['module'], fn (array $row) => ['module' => $module($row['module'])]);

		$this->update('generations', ['step'], fn (array $row) => ['step' => $step($row['step'])]);
	}

	/**
	 * Updates the changed rows of a table in chunks. Soft-deleted rows are included.
	 *
	 * @param  list<string>  $columns
	 * @param  callable(array<string, mixed>): array<string, mixed>  $map
	 */
	private function update(string $table, array $columns, callable $map): void
	{
		DB::table($table)->select(['id', ...$columns])->orderBy('id')->chunkById(200, function ($rows) use ($table, $map) {
			foreach ($rows as $row) {
				$row = (array) $row;
				$changes = array_filter($map($row), fn ($value, $column) => $value !== $row[$column], ARRAY_FILTER_USE_BOTH);

				if ($changes !== []) {
					DB::table($table)->where('id', $row['id'])->update($changes);
				}
			}
		});
	}

	/**
	 * Decodes, maps and encodes a JSON column the way Eloquent's array cast writes it.
	 */
	private function json(?string $json, callable $map): ?string
	{
		if ($json === null) {
			return null;
		}

		$data = json_decode($json, true);

		return is_array($data) ? json_encode($map($data), JSON_THROW_ON_ERROR) : $json;
	}
};
