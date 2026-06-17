<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/**
 * NotificationCategory — coarse bucket for in-app notifications. Drives the
 * digest counters (grouped per category) and the flyout icon/colour. Stored as
 * a string column so adding a category never needs a migration.
 */
enum NotificationCategory: string
{
    case Task = 'task';            // an activity/task was assigned to the user
    case Approval = 'approval';    // the user was asked to approve a document
    case Deal = 'deal';            // deal lifecycle (stage moved, rotting, won/lost)
    case Mention = 'mention';      // the user was mentioned
    case System = 'system';        // generic system message

    /**
     * Default for an unknown / generic notification.
     */
    public static function default(): self
    {
        return self::System;
    }
}
