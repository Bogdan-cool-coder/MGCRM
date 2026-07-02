<?php

declare(strict_types=1);

namespace App\Domain\Crm\Events;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Support\BroadcastsCompanyChannels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a company is edited (Phase 7a realtime contract). Drives a live
 * company-card patch + live list-row patch. Broadcasts to the company entity
 * channel + the department contacts channel (the NEW department if it changed).
 */
class CompanyUpdated implements ShouldBroadcast
{
    use BroadcastsCompanyChannels;
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Company $company,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return $this->companyChannels($this->company);
    }

    public function broadcastAs(): string
    {
        return 'company.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->companyPayload($this->company);
    }
}
