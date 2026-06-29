<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Domain\Inbox\Enums\RoutingStatus;
use App\Domain\Sales\Models\Deal;
use Database\Factories\Inbox\InboundMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InboundMessage — audit log of an incoming message + its routing result.
 * Model: fillable, casts, relations only. Routing logic is in
 * InboundRoutingService. created_at/updated_at are not used (received_at only).
 */
class InboundMessage extends Model
{
    /** @use HasFactory<InboundMessageFactory> */
    use HasFactory;

    protected static function newFactory(): InboundMessageFactory
    {
        return InboundMessageFactory::new();
    }

    protected $table = 'inbound_messages';

    public $timestamps = false;

    protected $fillable = [
        'channel_id',
        'external_id',
        'from_identifier',
        'from_name',
        'subject',
        'body',
        'raw_payload',
        'target_deal_id',
        'target_deal_created',
        'routing_status',
        'read_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'target_deal_created' => 'boolean',
            'routing_status' => RoutingStatus::class,
            'read_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    // ---- Relations ----

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function targetDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'target_deal_id');
    }
}
