<?php

declare(strict_types=1);

namespace App\Services\MacroData;

use App\Contracts\DocumentObjectDataResolver;
use App\Models\Company;
use App\Models\MacroData\EstateSells;
use Illuminate\Support\Collection;

/**
 * Real MacroData implementation of DocumentObjectDataResolver.
 *
 * Fetches the estate object (EstateSells) with all related entities and returns
 * a canonical grouped field map ready for placeholder substitution.
 *
 * KEY LAYOUT (canonical ${group.field} format):
 *
 * estate.*
 *   estate.area             — total area (decimal raw)
 *   estate.area_bti         — BTI area (decimal raw)
 *   estate.area_inside      — inner area (decimal raw)
 *   estate.area_terrace     — terrace area from BTI (estate_areaBti_terrace, decimal raw)
 *   estate.price            — current price (decimal raw)
 *   estate.price_m2         — price per m² (decimal raw)
 *   estate.price_action     — promo/discounted price (decimal raw)
 *   estate.floor            — floor number (int as string)
 *   estate.rooms            — room count (int as string)
 *   estate.number           — flat / unit number (geo_flatnum)
 *   estate.restoration_name — finishing type name
 *   estate.restoration_price — finishing surcharge (decimal raw)
 *   estate.house_name       — building name (public_house_name)
 *   estate.complex_name     — residential complex name (geo_complex_name)
 *   estate.address          — building address assembled from geo_* columns
 *
 * CONFIRMED MISSING from estate_sells schema (not implemented):
 *   estate.cadastral        — no cadastral column in estate_sells (any name)
 *   estate.description      — no description column in estate_sells
 *
 * deal.*  (best-effort — absent for free units)
 *   deal.number             — agreement number
 *   deal.date               — deal_date as 'Y-m-d'
 *   deal.date_start         — deal_date_start as 'Y-m-d'
 *   deal.sum                — deal_sum (decimal raw)
 *   deal.price              — deal_price (decimal raw)
 *   deal.area               — deal_area (decimal raw)
 *   deal.price_m2           — derived: deal_sum / deal_area (decimal raw)
 *   deal.sum_addons         — deal_sum_addons (decimal raw)
 *
 * buyer.*  (best-effort — from EstateDeals→contactsBuy→Contacts)
 *   buyer.full_name         — assembled: name_last + name_first + name_middle
 *   buyer.dob               — contacts_buy_dob as 'Y-m-d'
 *   buyer.phone             — contacts_buy_phones (first value)
 *   buyer.email             — contacts_buy_emails (first value)
 *   buyer.inn               — fl_inn (individual INN; fallback comm_inn)
 *   buyer.snils             — snils
 *   buyer.address_reg       — passport_address (registration address)
 *
 * CONFIRMED MISSING from contacts schema (not implemented):
 *   buyer.passport_series / passport_number / passport_issued_by / passport_date
 *   — no such columns exist; contacts has passport_bithplace + passport_address only.
 *
 * finances.* (best-effort — from EstateDeals→finances hasMany)
 *   finances.first_payment_sum    — summa of earliest payment (date_added min)
 *   finances.first_payment_date   — date_added of earliest payment
 *   finances.last_payment_date    — date_added of latest payment
 *   finances.balance              — deal_sum - finances_income (from estate_deals)
 *   finances.count                — total number of payment records
 *   finances.total_paid           — finances_income (from estate_deals denorm field)
 *
 * All values are raw: numerics as string (no formatting), dates as 'Y-m-d',
 * strings as-is. Formatting (words / roubles / date_words) is done by the
 * backend rendering engine, NOT here.
 *
 * Null DB values → empty string ''. Missing optional groups → keys absent.
 */
class DocumentObjectDataService implements DocumentObjectDataResolver
{
    public function __construct(
        protected ConnectionService $connectionService,
    ) {}

    /**
     * @inheritDoc
     *
     * @return array<string, mixed>  Flat map of canonical "group.field" keys.
     */
    public function resolve(Company $company, int $estateSellId): array
    {
        try {
            $this->connectionService->connect($company);
        } catch (\Throwable) {
            return [];
        }

        try {
            /** @var EstateSells|null $sell */
            $sell = EstateSells::with([
                'estateHouses.geoCityComplex',
                'estateRestoration',
                'estateDeals.contactsBuy',
                'estateDeals.finances',
            ])->find($estateSellId);
        } catch (\Throwable) {
            return [];
        }

        if ($sell === null) {
            return [];
        }

        $house   = $sell->estateHouses;
        $complex = $house?->geoCityComplex;
        $restore = $sell->estateRestoration;
        $deal    = $sell->estateDeals;

        $data = [];

        // -----------------------------------------------------------------
        // estate.*
        // -----------------------------------------------------------------
        $data['estate.area']              = $this->numericRaw($sell->estate_area);
        $data['estate.area_bti']          = $this->numericRaw($sell->estate_areaBti);
        $data['estate.area_inside']       = $this->numericRaw($sell->estate_area_inside);
        $data['estate.area_terrace']      = $this->numericRaw($sell->estate_areaBti_terrace);
        $data['estate.price']             = $this->numericRaw($sell->estate_price);
        $data['estate.price_m2']          = $this->numericRaw($sell->estate_price_m2);
        $data['estate.price_action']      = $this->numericRaw($sell->estate_price_action);
        $data['estate.floor']             = (string) ($sell->estate_floor ?? '');
        $data['estate.rooms']             = (string) ($sell->estate_rooms ?? '');
        $data['estate.number']            = (string) ($sell->geo_flatnum ?? '');
        $data['estate.restoration_name']  = (string) ($restore?->name ?? '');
        $data['estate.restoration_price'] = $this->numericRaw($sell->estate_restoration_price);
        $data['estate.house_name']        = (string) ($house?->public_house_name ?? '');
        $data['estate.complex_name']      = (string) ($complex?->geo_complex_name ?? '');
        $data['estate.address']           = $this->buildAddress($house);

        // -----------------------------------------------------------------
        // deal.*  (best-effort)
        // -----------------------------------------------------------------
        if ($deal !== null) {
            $data['deal.number']    = (string) ($deal->agreement_number ?? '');
            $data['deal.date']      = $this->dateRaw($deal->deal_date);
            $data['deal.date_start'] = $this->dateRaw($deal->deal_date_start);
            $data['deal.sum']       = $this->numericRaw($deal->deal_sum);
            $data['deal.price']     = $this->numericRaw($deal->deal_price);
            $data['deal.area']      = $this->numericRaw($deal->deal_area);
            $data['deal.price_m2']  = $this->derivedPriceM2($deal->deal_sum, $deal->deal_area);
            $data['deal.sum_addons'] = $this->numericRaw($deal->deal_sum_addons);
        }

        // -----------------------------------------------------------------
        // buyer.*  (best-effort, from deal→contactsBuy)
        // -----------------------------------------------------------------
        $contact = $deal?->contactsBuy;
        if ($contact !== null) {
            $data['buyer.full_name']   = $this->buildFullName($contact);
            $data['buyer.dob']         = $this->dateRaw($contact->contacts_buy_dob);
            $data['buyer.phone']       = $this->firstValue((string) ($contact->contacts_buy_phones ?? ''));
            $data['buyer.email']       = $this->firstValue((string) ($contact->contacts_buy_emails ?? ''));
            $data['buyer.inn']         = (string) ($contact->fl_inn ?? $contact->comm_inn ?? '');
            $data['buyer.snils']       = (string) ($contact->snils ?? '');
            $data['buyer.address_reg'] = (string) ($contact->passport_address ?? '');
        }

        // -----------------------------------------------------------------
        // finances.*  (best-effort, from deal→finances hasMany)
        // -----------------------------------------------------------------
        if ($deal !== null) {
            /** @var Collection $finances */
            $finances = $deal->finances ?? collect();

            if ($finances->isNotEmpty()) {
                $sorted    = $finances->sortBy('date_added');
                $firstPay  = $sorted->first();
                $lastPay   = $sorted->last();

                $data['finances.first_payment_sum']  = $this->numericRaw($firstPay?->summa);
                $data['finances.first_payment_date'] = $this->dateTimeRaw($firstPay?->date_added);
                $data['finances.last_payment_date']  = $this->dateTimeRaw($lastPay?->date_added);
            } else {
                $data['finances.first_payment_sum']  = '';
                $data['finances.first_payment_date'] = '';
                $data['finances.last_payment_date']  = '';
            }

            // balance and total_paid come from the denorm columns on estate_deals
            $totalPaid             = (float) ($deal->finances_income ?? 0);
            $dealSum               = (float) ($deal->deal_sum ?? 0);
            $balance               = $dealSum - $totalPaid;

            $data['finances.total_paid'] = $this->numericRaw($totalPaid ?: null);
            $data['finances.balance']    = $this->numericRaw($balance ?: null);
            $data['finances.count']      = (string) $finances->count();
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Raw-value helpers (no formatting — engine does that)
    // -------------------------------------------------------------------------

    /**
     * Return the numeric value as a plain string with up to 4 decimal places,
     * trailing zeros stripped. Returns '' for null / zero.
     */
    protected function numericRaw(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $f = (float) $value;

        if ($f == 0.0) {
            return '0';
        }

        return rtrim(rtrim(number_format($f, 4, '.', ''), '0'), '.');
    }

    /**
     * Convert a date value (Carbon, string, or null) to 'Y-m-d'. Returns ''.
     */
    protected function dateRaw(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        // String from DB (already 'Y-m-d' or MySQL datetime)
        $str = (string) $value;

        return $str !== '' ? substr($str, 0, 10) : '';
    }

    /**
     * Convert a datetime value (Carbon, string 'Y-m-d H:i:s', or null) to 'Y-m-d'.
     * Used for finances.date_added which is a datetime column.
     */
    protected function dateTimeRaw(mixed $value): string
    {
        return $this->dateRaw($value);
    }

    /**
     * Derive price per m² from sum and area. Returns '' if either is null/zero.
     */
    protected function derivedPriceM2(mixed $sum, mixed $area): string
    {
        $s = (float) ($sum ?? 0);
        $a = (float) ($area ?? 0);

        if ($s == 0.0 || $a == 0.0) {
            return '';
        }

        return $this->numericRaw($s / $a);
    }

    /**
     * Assemble the address string from estate_houses geo_* columns.
     *
     * estate_houses has: geo_city_name, geo_street_name, geo_house, geo_korpus, geo_building.
     * There is no single 'address' column — we compose it.
     */
    protected function buildAddress(mixed $house): string
    {
        if ($house === null) {
            return '';
        }

        $parts = array_filter([
            $house->geo_city_name    ?? null,
            $house->geo_street_name  ?? null,
            $house->geo_house        ?? null,
            $house->geo_korpus       ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        return implode(', ', $parts);
    }

    /**
     * Assemble full name from contact name parts.
     *
     * Contacts has: contacts_buy_name (full display), name_last, name_first, name_middle.
     * Prefer the split parts when available; fall back to contacts_buy_name.
     */
    protected function buildFullName(mixed $contact): string
    {
        $last   = trim((string) ($contact->name_last   ?? ''));
        $first  = trim((string) ($contact->name_first  ?? ''));
        $middle = trim((string) ($contact->name_middle ?? ''));

        if ($last !== '' || $first !== '') {
            return trim("$last $first $middle");
        }

        return trim((string) ($contact->contacts_buy_name ?? ''));
    }

    /**
     * Return the first non-empty value from a comma-separated or newline-separated list.
     * Used for phone and email fields that may store multiple values.
     */
    protected function firstValue(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        // Split by common separators: comma, semicolon, newline
        $parts = preg_split('/[,;\n]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($parts)) {
            return '';
        }

        return trim($parts[0]);
    }
}
