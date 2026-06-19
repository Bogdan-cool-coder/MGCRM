<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Crm\Enums\RelationType;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ContactRelation — directional (but bidirectionally visible) link between two contacts.
 *
 * Storage rule: always min(contact_id, related_contact_id) → contact_id,
 * max → related_contact_id. Normalisation enforced by ContactRelationService::attach().
 * Query rule: WHERE contact_id=X OR related_contact_id=X to surface the link for both sides.
 */
class ContactRelation extends Model
{
    protected $table = 'crm_contact_relations';

    protected $fillable = [
        'contact_id',
        'related_contact_id',
        'relation_type',
        'note',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'relation_type' => RelationType::class,
        ];
    }

    // ---- Relations ----

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function relatedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'related_contact_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
