<?php

declare(strict_types=1);

namespace App\Services\Documents;

use Carbon\Carbon;
use morphos\Russian\CardinalNumeralGenerator;
use morphos\Russian\MoneySpeller;
use Throwable;

/**
 * Single placeholder-substitution engine for both document types.
 *
 * It understands BOTH placeholder syntaxes the system emits:
 *   - ${name|filter|filter}   — used by uploaded docx templates and the system
 *                               HTML-КП seeder (canonical syntax).
 *   - {{name|filter}}         — used by AI-generated HTML configs (M7/M8) and
 *                               legacy templates. Kept working for back-compat.
 *
 * A placeholder is `name` (a canonical "group.field" key, e.g. estate.price)
 * optionally followed by a pipe-separated filter chain. The value is looked up
 * verbatim in the supplied data map (RAW values: numerics as numeric strings,
 * dates as 'Y-m-d' / Carbon, plain strings as-is — see DocumentObjectDataService
 * and GenerateDocumentJob, which assemble the map). Filters then format it.
 *
 * Resolution rules (deliberately forgiving — a document render must never abort
 * on a missing field or a bad filter):
 *   - Missing / null key            → empty string (NOT a raw ${...} leak).
 *   - Empty value through a filter  → empty string (filters short-circuit on '').
 *   - Unknown filter name           → ignored (value passes through unchanged).
 *
 * FILTER CHAIN (left to right):
 *   words      — number → Russian words. For a money key: "N рублей M копеек"
 *                (via morphos MoneySpeller). For a plain number: cardinal words
 *                (via morphos CardinalNumeralGenerator).
 *   rouble     — append the currency word, declined to the amount
 *                ("3 рубля" / "5 рублей" / "1 рубль").
 *   money      — alias of rouble (catalog uses `rouble`; `money` accepted too).
 *   format     — thousands-separated number ("3 500 000"), trailing ".00" dropped.
 *   nformat    — alias of format.
 *   date       — format a date value as "DD.MM.YYYY".
 *   date_words — format a date value as "1 июня 2024 г." (RU genitive month).
 *   ucfirst    — uppercase the first letter (multibyte-safe).
 *   upper      — uppercase the whole string (multibyte-safe).
 *
 * The money / date key sets are derived from config('documents.field_catalog')
 * so the engine stays in lock-step with the catalogue: a key whose catalogue
 * filters include both `words` and `rouble` is money; a key whose filters include
 * `date`/`date_words` is a date. This keeps the "is this a money field?" decision
 * a single source of truth rather than a second hand-maintained list.
 */
class DocumentFieldEngine
{
    /**
     * Canonical keys treated as money by the `words` filter ("N рублей M копеек").
     * Lazily derived from the field catalogue on first use.
     *
     * @var array<string, true>|null
     */
    private ?array $moneyKeys = null;

    /**
     * Replace every ${...} and {{...}} placeholder in $html using the data map.
     *
     * @param  array<string, mixed>  $data  Flat canonical-key => raw-value map.
     */
    public function renderHtml(string $html, array $data): string
    {
        // ${ ... } first, then {{ ... }}. Both bodies are `name|filter|...` with
        // the name being a dotted canonical key (estate.price) or a flat token
        // (brand_header, req_ogrn). The body is captured loosely (anything but the
        // closing delimiter) so filters and dotted names both survive.
        $html = (string) preg_replace_callback(
            '/\$\{\s*([^}|]+(?:\|[^}]*)?)\s*\}/u',
            fn (array $m): string => $this->resolve(trim($m[1]), $data),
            $html,
        );

        $html = (string) preg_replace_callback(
            '/\{\{\s*([^}|]+(?:\|[^}]*)?)\s*\}\}/u',
            fn (array $m): string => $this->resolve(trim($m[1]), $data),
            $html,
        );

        return $html;
    }

    /**
     * Resolve a single placeholder body ("name|filter|filter") against $data.
     *
     * @param  array<string, mixed>  $data
     */
    public function resolve(string $expr, array $data): string
    {
        $parts = array_map('trim', explode('|', $expr));
        $name = array_shift($parts) ?? '';

        if ($name === '') {
            return '';
        }

        $raw = $data[$name] ?? null;

        // A missing / null value collapses to '' so the template never leaks the
        // raw placeholder and filters do not choke on null.
        $value = $this->stringifyRaw($raw);

        foreach ($parts as $filter) {
            if ($filter === '') {
                continue;
            }
            $value = $this->applyFilter($filter, $value, $name);
        }

        return $value;
    }

    // -------------------------------------------------------------------------
    // Filter application
    // -------------------------------------------------------------------------

    /**
     * Apply a single named filter to the current (string) value. The original
     * canonical key is passed through so `words` can decide money vs. plain.
     */
    private function applyFilter(string $filter, string $value, string $key): string
    {
        // An empty value short-circuits every filter — there is nothing to format
        // and we must not emit "ноль рублей" / "0" for an absent field.
        if ($value === '') {
            return '';
        }

        return match ($filter) {
            'words' => $this->filterWords($value, $key),
            'rouble', 'money' => $this->filterRouble($value),
            'format', 'nformat' => $this->filterFormat($value),
            'date' => $this->filterDate($value),
            'date_words' => $this->filterDateWords($value),
            'ucfirst' => $this->ucfirst($value),
            'upper' => mb_strtoupper($value, 'UTF-8'),
            // Unknown filter — pass the value through untouched rather than fail.
            default => $value,
        };
    }

    /**
     * `words` — money key → "N рублей M копеек"; plain number → cardinal words.
     * A non-numeric value passes through unchanged (already a string label).
     */
    private function filterWords(string $value, string $key): string
    {
        $number = $this->toFloat($value);
        if ($number === null) {
            return $value;
        }

        try {
            if ($this->isMoneyKey($key)) {
                return MoneySpeller::spell(
                    $number,
                    MoneySpeller::RUBLE,
                    MoneySpeller::NORMAL_FORMAT,
                );
            }

            // Plain cardinal: integers spell directly; a fractional part is read
            // as a separate "целых / сотых" tail would be over-engineering for the
            // catalogue (no plain-number field carries decimals) — spell the int.
            return CardinalNumeralGenerator::getCase(
                (int) round($number),
                \morphos\Russian\Cases::NOMINATIVE,
            );
        } catch (Throwable) {
            // morphos can throw on extreme magnitudes — fall back to the raw
            // value rather than abort the whole document render.
            return $value;
        }
    }

    /**
     * `rouble` — append the rouble word, declined to the integer part of the
     * amount ("1 рубль", "3 рубля", "5 рублей"). Used after a numeric value when
     * the author wants "3 500 000 рублей" rather than the full words form.
     */
    private function filterRouble(string $value): string
    {
        $number = $this->toFloat($value);
        if ($number === null) {
            return $value;
        }

        $formatted = $this->filterFormat($value);
        $word = $this->roubleWord((int) abs($number));

        return trim($formatted.' '.$word);
    }

    /**
     * `format` — thousands-separated number. Integers render with no decimals
     * ("3 500 000"); genuine decimals keep up to two places ("65.4" → "65,4").
     */
    private function filterFormat(string $value): string
    {
        $number = $this->toFloat($value);
        if ($number === null) {
            return $value;
        }

        // Whole number → no decimal part. Otherwise keep up to 2 places, drop
        // trailing zeros, and use a comma as the RU decimal separator.
        if ($this->isWhole($number)) {
            return number_format($number, 0, ',', ' ');
        }

        $formatted = number_format($number, 2, ',', ' ');

        // Strip a trailing ",N0"/",00" so 65.40 → "65,4" and 65.00 never reaches
        // here (isWhole catches it).
        $formatted = preg_replace('/(,\d*?)0+$/', '$1', $formatted) ?? $formatted;

        return rtrim($formatted, ',');
    }

    /**
     * `date` — "DD.MM.YYYY". Returns the original value when it is not a date.
     */
    private function filterDate(string $value): string
    {
        $date = $this->toDate($value);

        return $date?->format('d.m.Y') ?? $value;
    }

    /**
     * `date_words` — "1 июня 2024 г." (RU, genitive month name) via Carbon's
     * localized isoFormat. Returns the original value when it is not a date.
     */
    private function filterDateWords(string $value): string
    {
        $date = $this->toDate($value);

        if ($date === null) {
            return $value;
        }

        return $date->locale('ru')->isoFormat('D MMMM YYYY').' г.';
    }

    // -------------------------------------------------------------------------
    // Value coercion helpers
    // -------------------------------------------------------------------------

    /**
     * Stringify a raw data-map value for the start of the filter chain. Numerics
     * become their plain string form, Carbon/DateTime become 'Y-m-d', null/array
     * collapse to ''. Plain strings pass through.
     */
    private function stringifyRaw(mixed $raw): string
    {
        if ($raw === null) {
            return '';
        }

        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }

        if (is_bool($raw)) {
            return $raw ? '1' : '0';
        }

        if (is_scalar($raw)) {
            return (string) $raw;
        }

        return '';
    }

    /**
     * Parse a numeric value out of a string, tolerating thousands spaces and a
     * comma decimal separator. Null when the string is not a number.
     */
    private function toFloat(string $value): ?float
    {
        $clean = str_replace([' ', "\u{00A0}"], '', $value);
        $clean = str_replace(',', '.', $clean);

        return is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * Parse a date out of a string. Accepts 'Y-m-d', 'Y-m-d H:i:s' and anything
     * Carbon recognises. Null when unparseable (so non-date values pass through
     * the date filters unchanged).
     */
    private function toDate(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function isWhole(float $number): bool
    {
        return abs($number - round($number)) < 0.0000001;
    }

    /**
     * Multibyte-safe ucfirst.
     */
    private function ucfirst(string $value): string
    {
        $first = mb_substr($value, 0, 1, 'UTF-8');
        $rest = mb_substr($value, 1, null, 'UTF-8');

        return mb_strtoupper($first, 'UTF-8').$rest;
    }

    /**
     * Decline the rouble word to a quantity (1 рубль / 2 рубля / 5 рублей).
     */
    private function roubleWord(int $amount): string
    {
        $mod100 = $amount % 100;
        $mod10 = $amount % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return 'рублей';
        }
        if ($mod10 === 1) {
            return 'рубль';
        }
        if ($mod10 >= 2 && $mod10 <= 4) {
            return 'рубля';
        }

        return 'рублей';
    }

    /**
     * Is $key a money field (per the catalogue: filters include words + rouble)?
     */
    private function isMoneyKey(string $key): bool
    {
        return isset($this->moneyKeySet()[$key]);
    }

    /**
     * Money-key set derived once from config('documents.field_catalog'). A field
     * counts as money when its declared filters include both `words` and `rouble`
     * — the same definition the catalogue documents.
     *
     * @return array<string, true>
     */
    private function moneyKeySet(): array
    {
        if ($this->moneyKeys !== null) {
            return $this->moneyKeys;
        }

        $set = [];
        $catalog = (array) config('documents.field_catalog', []);

        foreach ($catalog as $fields) {
            if (! is_array($fields)) {
                continue;
            }
            foreach ($fields as $field) {
                if (! is_array($field) || ! isset($field['key'])) {
                    continue;
                }
                $filters = (array) ($field['filters'] ?? []);
                if (in_array('words', $filters, true) && in_array('rouble', $filters, true)) {
                    $set[(string) $field['key']] = true;
                }
            }
        }

        return $this->moneyKeys = $set;
    }
}
