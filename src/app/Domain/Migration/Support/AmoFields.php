<?php

declare(strict_types=1);

namespace App\Domain\Migration\Support;

/**
 * AmoFields — the AMO custom-field ids the transformers read, named in one place.
 *
 * Temporary migration bounded-context (dropped at M12). These ids were pulled
 * read-only from the source account (CF audit, build plan §11). Keeping them as
 * named constants avoids magic numbers spread across the transformers and makes
 * the lead/company field contract greppable.
 */
final class AmoFields
{
    // ---- Lead fields ----
    /** «Вечная лицензия» / box / on-premise (checkbox). */
    public const LEAD_PERPETUAL_LICENSE = 709732;

    /** Planned / actual contract sign date (date). */
    public const LEAD_SIGN_DATE = 584603;

    /** Planned / actual payment date (date). */
    public const LEAD_PAYMENT_DATE = 585395;

    /** Category S1/S2/M1/M2/L1/L2/L3 (select) — raw into extra_fields.amo_category. */
    public const LEAD_CATEGORY = 748860;

    /** Country (select, ~113 options) — normalised to ISO via country_map. */
    public const LEAD_COUNTRY = 711078;

    /** Tax id (text, noisy). */
    public const LEAD_TAX_ID = 709194;

    // ---- Company fields ----
    /** Display name fallback (text) when _embedded company name is absent. */
    public const COMPANY_NAME = 748652;

    /** Legal name (text). */
    public const COMPANY_LEGAL_NAME = 711074;

    /** Specialization (multiselect) — first mappable via specialization_map. */
    public const COMPANY_SPECIALIZATION = 709546;

    /** Acquisition channel (select) — via channel_map. */
    public const COMPANY_CHANNEL = 708366;

    // AMO well-known terminal statuses (account-wide, both funnels).
    public const STATUS_WON = 142;

    public const STATUS_LOST = 143;
}
