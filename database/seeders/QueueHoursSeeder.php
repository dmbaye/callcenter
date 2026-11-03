<?php

namespace Database\Seeders;

use App\Models\QueueHour;
use App\Models\Team;
use Illuminate\Database\Seeder;

class QueueHoursSeeder extends Seeder
{
    /**
     * Default opening hours: Monday to Friday, 08:00 - 16:00.
     */
    public function run(): void
    {
        foreach (Team::query()->get() as $team) {
            foreach ($team->callQueues()->get() as $queue) {
                for ($weekday = 0; $weekday < 5; $weekday++) {
                    QueueHour::updateOrCreate(
                        ['call_queue_id' => $queue->id, 'weekday' => $weekday],
                        ['opens_at' => '08:00:00', 'closes_at' => '16:00:00'],
                    );
                }
            }
        }
    }
}
