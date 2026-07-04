<?php

declare(strict_types=1);

namespace Database\Factories\Inbox;

use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Models\InboxDraft;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxDraft>
 */
class InboxDraftFactory extends Factory
{
    protected $model = InboxDraft::class;

    public function definition(): array
    {
        return [
            'user_id' => fn () => User::factory(),
            'related_message_id' => null,
            'subject' => $this->faker->sentence(4),
            'body' => $this->faker->paragraph(),
        ];
    }
}
