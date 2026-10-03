<?php

namespace Database\Factories;

use App\Models\Child;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Child>
 */
class ChildFactory extends Factory
{
	/**
	 * @return array<string, mixed>
	 */
	public function definition(): array
	{
		return [
			'user_id' => User::factory(),
			'name' => fake()->firstName(),
			'level' => '2. Sek',
		];
	}
}
