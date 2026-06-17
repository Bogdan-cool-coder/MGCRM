<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use App\Domain\Contracts\Models\LicensorBankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LicensorBankAccount
 */
class LicensorBankAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'licensor_id' => $this->licensor_id,
            'currency' => $this->currency,
            'bank' => $this->bank,
            'bank_code_label' => $this->bank_code_label,
            'bank_code' => $this->bank_code,
            'account' => $this->account,
            'swift' => $this->swift,
            'is_primary' => $this->is_primary,
            'note' => $this->note,
        ];
    }
}
