<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

/**
 * Entity types that can have saved list views.
 * Matches the entity_type column in crm_saved_views.
 */
enum SavedViewEntity: string
{
    case Contact = 'contact';
    case Company = 'company';
}
