<?php

declare(strict_types=1);

namespace Tests\Unit\Log;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Log\Enums\LogAction;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Log\Models\EntityLog;
use App\Domain\Log\Services\EntityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntityLogServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EntityLogService
    {
        return app(EntityLogService::class);
    }

    public function test_record_writes_a_row_with_actor_action_and_meta(): void
    {
        $actor = User::factory()->create(['role' => Role::Manager]);

        $log = $this->service()->record(
            LogSubjectType::Deal,
            42,
            $actor,
            LogAction::StageChanged,
            ['from_stage_id' => 1, 'to_stage_id' => 2],
        );

        $this->assertDatabaseHas('entity_logs', [
            'id' => $log->id,
            'subject_type' => 'deal',
            'subject_id' => 42,
            'actor_id' => $actor->id,
            'action' => 'stage_changed',
        ]);

        $fresh = EntityLog::findOrFail($log->id);
        $this->assertSame(LogSubjectType::Deal, $fresh->subject_type);
        $this->assertSame(LogAction::StageChanged, $fresh->action);
        $this->assertSame(['from_stage_id' => 1, 'to_stage_id' => 2], $fresh->meta);
    }

    public function test_record_allows_null_actor_for_system_events(): void
    {
        $log = $this->service()->record(
            LogSubjectType::Company,
            7,
            null,
            LogAction::Created,
            ['source' => 'inbound'],
        );

        $this->assertNull($log->actor_id);
        $this->assertDatabaseHas('entity_logs', [
            'id' => $log->id,
            'subject_type' => 'company',
            'actor_id' => null,
            'action' => 'created',
        ]);
    }

    public function test_record_stamps_explicit_occurred_at(): void
    {
        $when = now()->subDays(3)->startOfSecond();

        $log = $this->service()->record(
            LogSubjectType::Contact,
            9,
            null,
            LogAction::DataChanged,
            [],
            $when,
        );

        $this->assertTrue($when->equalTo($log->refresh()->created_at));
    }

    public function test_for_subject_returns_newest_first_scoped_to_subject(): void
    {
        $deal = 100;
        $other = 200;

        EntityLog::factory()->create([
            'subject_type' => LogSubjectType::Deal->value,
            'subject_id' => $deal,
            'action' => LogAction::Created->value,
            'created_at' => now()->subDays(2),
        ]);
        EntityLog::factory()->create([
            'subject_type' => LogSubjectType::Deal->value,
            'subject_id' => $deal,
            'action' => LogAction::StageChanged->value,
            'created_at' => now(),
        ]);
        // A row on a different subject must not leak in.
        EntityLog::factory()->create([
            'subject_type' => LogSubjectType::Deal->value,
            'subject_id' => $other,
            'action' => LogAction::DataChanged->value,
            'created_at' => now(),
        ]);

        $page = $this->service()->forSubject(LogSubjectType::Deal, $deal);

        $this->assertSame(2, $page->total());
        $actions = $page->getCollection()->map(fn (EntityLog $l): string => $l->action->value)->all();
        $this->assertSame(['stage_changed', 'created'], $actions); // newest first
    }

    public function test_for_subject_paginates(): void
    {
        for ($i = 0; $i < 5; $i++) {
            EntityLog::factory()->create([
                'subject_type' => LogSubjectType::Company->value,
                'subject_id' => 5,
                'created_at' => now()->subMinutes($i),
            ]);
        }

        $page = $this->service()->forSubject(LogSubjectType::Company, 5, 2);

        $this->assertSame(5, $page->total());
        $this->assertSame(2, $page->perPage());
        $this->assertCount(2, $page->items());
    }
}
