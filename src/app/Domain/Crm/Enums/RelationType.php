<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

/**
 * RelationType — types of contact-to-contact relationships.
 *
 * Values are latin slugs (machine-readable). Labels are in the frontend
 * i18n files (crm.contact.relations.type.*). PO decision: 7 values.
 */
enum RelationType: string
{
    case Partner = 'partner';
    case Referrer = 'referrer';
    case Colleague = 'colleague';
    case Friend = 'friend';
    case Investor = 'investor';
    case Mentor = 'mentor';
    case Other = 'other';
}
