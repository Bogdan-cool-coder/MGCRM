<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatMessage extends Model
{
    /**
     * Allowed values for the `status` column. Validated at the model layer
     * (no DB enum — matches the rest of the project).
     */
    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_DONE      = 'done';
    public const STATUS_ERROR     = 'error';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_DONE,
        self::STATUS_ERROR,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'chat_id',
        'user_id',
        'company_id',
        'role',
        'content',
        'metadata',
        'status',
        'job_id',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata'    => 'array',
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * Streaming event log for this message, ordered by sequence. Used by the
     * frontend long-poll/stream endpoint and by debugging tools.
     */
    public function events(): HasMany
    {
        return $this->hasMany(ChatMessageEvent::class)->orderBy('sequence');
    }

    /**
     * True if the message is still being produced (queued or running). The
     * controller uses this to reject concurrent send-message attempts on the
     * same chat.
     */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }
}
