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

    // ---- Company contact fields (multitext/url/textarea on the COMPANY object) ----
    // Confirmed on live data: these live on the AMO company entity itself (not the
    // lead). Phone/email are AMO multitext (multi-value with enum_code subtype);
    // website/address are single url/textarea fields.
    /** Phone (multitext, multi-value). Primary denormalised onto company.phone. */
    public const COMPANY_PHONE = 2709;

    /** Email (multitext, multi-value). Primary denormalised onto company.email. */
    public const COMPANY_EMAIL = 2711;

    /** Website (url, single). */
    public const COMPANY_WEBSITE = 2713;

    /** Address (textarea, single). */
    public const COMPANY_ADDRESS = 2717;

    // ---- Contact fields ----
    /** Job title / position (select). Primary source. */
    public const CONTACT_POSITION_SELECT = 583865;

    /** Job title / position (text). Fallback when the select is empty. */
    public const CONTACT_POSITION_TEXT = 2707;

    // AMO well-known terminal statuses (account-wide, both funnels).
    public const STATUS_WON = 142;

    public const STATUS_LOST = 143;
}
