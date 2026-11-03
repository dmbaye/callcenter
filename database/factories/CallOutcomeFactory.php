<?php

namespace Database\Factories;

use App\Models\CallOutcome;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallOutcome>
 */
class CallOutcomeFactory extends Factory
{
    protected $model = CallOutcome::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'caller' => fake()->numerify('77#######'),
            'direction' => fake()->randomElement(['inbound', 'outbound']),
            'started_at' => fake()->dateTimeThisMonth(),
            'ended_at' => null,
            'duration' => fake()->numberBetween(20, 900),
            'outcome' => fake()->randomElement(CallOutcome::OUTCOMES),
            'notes' => null,
        ];
    }
}
