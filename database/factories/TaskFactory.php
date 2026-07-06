<?php

namespace Database\Factories;

use App\Modules\CRM\Models\Task;
use App\Shared\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic data only (DP-005). All FKs (assignee/creator/campaign) are
 * nullable per canonical shape; default state is an unanchored open task.
 *
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'status' => TaskStatus::Open,
            'assignee_user_id' => null,
            'due_at' => null,
            'creator_id' => null,
            'campaign_id' => null,
        ];
    }
}
