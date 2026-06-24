<?php

declare(strict_types=1);

namespace App\Domain\Migration\Enums;

/**
 * What the AMO ETL does with an AMO "Продукт" enum option when it appears on an
 * imported lead. Temporary migration bounded-context (dropped at M12).
 *
 *   - Map:   attach the linked catalog product/plan as a deal_products line.
 *   - Skip:  drop the option (no deal_products row).
 *   - Other: route to a catch-all / generic product (treated as skip until a
 *            catch-all product is curated — never silently drops the budget).
 */
enum AmoProductMappingAction: string
{
    case Map = 'map';
    case Skip = 'skip';
    case Other = 'other';
}
