<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Crm\Enums\ContactStatus;
use App\Domain\Iam\Models\User;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Contact (physical person / lead contact).
 * All business logic lives in ContactService. Model: fillable, casts, relations only.
 */
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory, SoftDeletes;

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }

    protected $table = 'crm_contacts';

    protected $fillable = [
        'full_name',
        'position',
        'phone',
        'email',
        'tg_username',
        'notes',
        'source',
        'status',
        'tags',
        'extra_fields',
        'owner_id',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContactStatus::class,
            'tags' => 'array',
            'extra_fields' => 'array',
            'last_activity_at' => 'datetime',
        ];
    }

    // ---- Relations ----

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * M2M: companies this contact is linked to.
     * Pivot extras: position, position_id, employment_status, is_primary.
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(
            Company::class,
            'crm_contact_company_links',
            'contact_id',
            'company_id',
        )->withPivot(['position', 'position_id', 'employment_status', 'is_primary'])
            ->withTimestamps();
    }

    /** Communication channels (phone, email, tg, wa, etc.). */
    public function channels(): HasMany
    {
        return $this->hasMany(ContactChannel::class)->orderBy('channel_type');
    }

    /** Raw link models (for is_primary reassign, etc.). */
    public function companyLinks(): HasMany
    {
        return $this->hasMany(ContactCompanyLink::class);
    }

    /** The primary company link (if any). */
    public function primaryCompanyLink(): HasMany
    {
        return $this->hasMany(ContactCompanyLink::class)->where('is_primary', true);
    }

    /**
     * Contact-to-contact relations where this contact is the "left" side (contact_id).
     */
    public function contactRelations(): HasMany
    {
        return $this->hasMany(ContactRelation::class, 'contact_id');
    }

    /**
     * Contact-to-contact relations where this contact is the "right" side (related_contact_id).
     */
    public function relatedContactRelations(): HasMany
    {
        return $this->hasMany(ContactRelation::class, 'related_contact_id');
    }
}
