<?php

namespace Database\Seeders;

use App\Models\Child;
use App\Models\Lesson;
use App\Models\User;
use Database\Factories\LessonFactory;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo-Konto mit einem Kind und den beiden Referenz-Lernseiten.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $child = Child::factory()->for($user)->create(['name' => 'Mia']);

        foreach (LessonFactory::FIXTURES as $fixture) {
            Lesson::factory()->for($child)->fromFixture($fixture)->create();
        }
    }
}
