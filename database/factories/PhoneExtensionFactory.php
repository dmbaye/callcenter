<?php

namespace Database\Factories;

use App\Models\PhoneExtension;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhoneExtension>
 */
class PhoneExtensionFactory extends Factory
{
    protected $model = PhoneExtension::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'extension' => (string) fake()->unique()->numberBetween(7000, 7999),
            'secret' => 'secret-'.fake()->bothify('########'),
            'label' => null,
            'is_primary' => true,
        ];
    }
}
