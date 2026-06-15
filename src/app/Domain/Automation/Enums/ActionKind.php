<?php

declare(strict_types=1);

namespace App\Domain\Automation\Enums;

/**
 * Automation action kinds (PipelineAutomation.action_kind).
 *
 * MVP set (8 actions). Handlers are introduced in P1; this enum only enumerates
 * the supported action vocabulary for the engine and validation layer.
 *
 * Network actions (tg_notify / webhook / email) perform blocking outbound IO and
 * MUST NOT run inline on the web request — they are dispatched to a queue job in
 * a later phase. isNetwork() lets the dispatcher classify them without magic
 * strings.
 */
enum ActionKind: string
{
    case TgNotify = 'tg_notify';
    case CreateTask = 'create_task';
    case SetField = 'set_field';
    case GenerateDocument = 'generate_document';
    case ChangeOwner = 'change_owner';
    case ChangeStage = 'change_stage';
    case Webhook = 'webhook';
    case Email = 'email';

    /**
     * Network actions perform blocking outbound IO and are deferred to a queue
     * job when fired from the inline (web-request) path.
     */
    public function isNetwork(): bool
    {
        return match ($this) {
            self::TgNotify, self::Webhook, self::Email => true,
            default => false,
        };
    }
}
