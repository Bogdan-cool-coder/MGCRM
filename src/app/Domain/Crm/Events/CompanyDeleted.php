<?php

declare(strict_types=1);

namespace App\Domain\Crm\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a company is deleted (Phase 7a realtime contract). Drives removal
 * of the row from every open company/contact list. Carries a SCALAR SNAPSHOT
 * (not the model) — after ->delete() the row is hidden by the SoftDeletes global
 * scope and cannot be re-hydrated on the queue worker.
 */
class CompanyDeleted implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public readonly int $companyId,
        public readonly ?int $departmentId = null,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('company.'.$this->companyId)];

        if ($this->departmentId !== null) {
            $channels[] = new PrivateChannel('dept.'.$this->departmentId.'.contacts');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'company.deleted';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->companyId,
            'department_id' => $this->departmentId,
        ];
    }
}
