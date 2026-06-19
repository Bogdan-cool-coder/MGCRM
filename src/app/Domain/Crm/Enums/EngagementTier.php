<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

/**
 * EngagementTier — freshness tier for Contact / Company.
 *
 * Computed from last_activity_at vs. thresholds in config/crm.php:
 *   Fresh   — last_activity_at <= warm_days ago
 *   Cooling — warm_days < last_activity_at <= cold_days ago
 *   Cold    — last_activity_at > cold_days ago OR null
 *
 * Frontend renders the tier as a coloured chip (EngagementChip.vue).
 */
enum EngagementTier: string
{
    case Fresh = 'fresh';
    case Cooling = 'cooling';
    case Cold = 'cold';
}
