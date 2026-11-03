<?php

namespace Database\Factories;

use App\Models\CallQueue;
use App\Models\QueueHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueueHour>
 */
class QueueHourFactory extends Factory
{
    protected $model = QueueHour::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'call_queue_id' => CallQueue::factory(),
            'weekday' => fake()->numberBetween(0, 6),
            'opens_at' => '08:00:00',
            'closes_at' => '16:00:00',
        ];
    }
}
