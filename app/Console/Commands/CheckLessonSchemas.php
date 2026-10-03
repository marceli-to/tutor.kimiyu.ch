<?php

namespace App\Console\Commands;

use App\Actions\Generation\CheckSchemas;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lessons:check-schemas')]
#[Description('Checks with the real API that every structured-output schema compiles (costs less than a cent)')]
class CheckLessonSchemas extends Command
{
	public function handle(CheckSchemas $checkSchemas): int
	{
		$results = $checkSchemas->handle();

		$this->table(['Schema', 'Model', 'Bytes', 'Result'], array_map(fn (array $result) => [
			$result['name'],
			$result['model'],
			$result['bytes'],
			match ($result['status']) {
				'ok' => 'OK',
				'too_large' => 'TOO LARGE',
				'error' => 'error: '.mb_substr((string) $result['error'], 0, 160),
			},
		], $results));

		return collect($results)->every(fn (array $result) => $result['status'] === 'ok') ? self::SUCCESS : self::FAILURE;
	}
}
