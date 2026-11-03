<?php

namespace Database\Factories;

use App\Models\CallQueue;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CallQueue>
 */
class CallQueueFactory extends Factory
{
    protected $model = CallQueue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'team_id' => Team::factory(),
            'name' => $name,
            'asterisk_name' => fn (array $attributes) => Team::query()->findOrFail($attributes['team_id'])->asterisk_key.'_'.Str::slug($name),
        ];
    }
}
