<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Models\ContactChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContactChannel
 */
class ContactChannelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_type' => $this->channel_type->value,
            'value' => $this->value,
            'label' => $this->label,
            'is_primary_for_channel' => $this->is_primary_for_channel,
        ];
    }
}
