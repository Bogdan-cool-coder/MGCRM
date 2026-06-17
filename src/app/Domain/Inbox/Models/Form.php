<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Domain\Iam\Models\User;
use Database\Factories\Inbox\FormFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Form — public web form with a unique slug. Business logic lives in
 * FormService; submission validation is in FormSubmissionValidator.
 * Model: fillable, casts, relations only.
 */
class Form extends Model
{
    /** @use HasFactory<FormFactory> */
    use HasFactory;

    protected static function newFactory(): FormFactory
    {
        return FormFactory::new();
    }

    protected $table = 'forms';

    protected $fillable = [
        'name',
        'public_slug',
        'fields',
        'channel_id',
        'thank_you_text',
        'is_active',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'is_active' => 'boolean',
        ];
    }

    // ---- Relations ----

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
