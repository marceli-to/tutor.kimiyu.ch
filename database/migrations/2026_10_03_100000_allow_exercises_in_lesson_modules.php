<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
	/**
	 * Exercises («Übungen») became a module the parents choose. Until now they always came with
	 * math and geometry, so every stored choice allows them; «Wie letztes Mal» stays the same.
	 */
	public function up(): void
	{
		$this->map(fn (array $modules) => in_array('exercises', $modules, true) ? $modules : [...$modules, 'exercises']);
	}

	public function down(): void
	{
		$this->map(fn (array $modules) => array_values(array_diff($modules, ['exercises'])));
	}

	/**
	 * @param  callable(array<mixed>): array<mixed>  $map  gets the decoded list as stored
	 */
	private function map(callable $map): void
	{
		DB::table('lessons')->whereNotNull('modules')->select(['id', 'modules'])->orderBy('id')->chunkById(200, function ($rows) use ($map) {
			foreach ($rows as $row) {
				$modules = json_decode($row->modules, true);

				if (is_array($modules)) {
					$new = $map($modules);

					if ($new !== $modules) {
						DB::table('lessons')->where('id', $row->id)->update(['modules' => json_encode($new, JSON_THROW_ON_ERROR)]);
					}
				}
			}
		});
	}
};
