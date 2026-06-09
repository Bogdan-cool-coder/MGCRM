<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\CompanyBranding;
use App\Models\Promotion;
use Illuminate\Support\Carbon;

/**
 * Assembles the render-time values the placeholder engine substitutes but the
 * MacroData resolver does NOT produce:
 *
 *   common.today               — now() as 'Y-m-d' (RAW; the date filters format it)
 *   discount.label             — localized promotion name
 *   discount.percent           — effective discount % (RAW number)
 *   discount.amount            — money taken off the base price (RAW number)
 *   discount.price_discounted  — base estate.price minus the discount (RAW, >= 0)
 *   brand_header / brand_footer — localized branding header/footer text
 *   req_<key>                  — flattened branding requisites (scalar entries)
 *
 * All values are RAW (numerics as numeric strings, the date as 'Y-m-d') so the
 * DocumentFieldEngine filters (words / rouble / format / date / date_words) apply
 * uniformly — exactly as they do to the resolver's estate.* / deal.* keys. The
 * branding palette / logo are NOT tokens (they are applied as CSS / <img> in the
 * HTML shell); only branding TEXT surfaces here.
 *
 * Shared by HtmlDocumentService (preview + html generation) and GenerateDocumentJob
 * (docx generation) so the discount maths and token names have one definition.
 */
class DocumentDataAssembler
{
    /**
     * Build the canonical render-only value map (discount.* + common.today +
     * branding tokens), merged on top of the resolved object data so it always
     * reflects the computed values rather than a stray same-named field.
     *
     * @param  array<string, mixed>  $objectData  Canonical resolver output (estate.*, deal.*, ...).
     * @return array<string, mixed> $objectData augmented with the render-only keys.
     */
    public function assemble(
        array $objectData,
        ?CompanyBranding $branding,
        ?Promotion $promotion,
        float $discount,
        string $locale = 'ru',
    ): array {
        $injected = array_merge(
            ['common.today' => Carbon::now()->format('Y-m-d')],
            $this->discountValues($objectData, $promotion, $discount, $locale),
            $this->brandingValues($branding, $locale),
        );

        // Injected render-only values win over any same-named object field.
        return array_merge($objectData, $injected);
    }

    /**
     * Compute the discount.* canonical values from the base estate.price and the
     * selected promotion / discount. No promotion (or a non-positive discount) →
     * zeros + the base price, never a failure.
     *
     * @param  array<string, mixed>  $objectData
     * @return array<string, mixed>
     */
    public function discountValues(array $objectData, ?Promotion $promotion, float $discount, string $locale): array
    {
        $base = $this->numericValue($objectData['estate.price'] ?? null);

        if ($promotion === null || $discount <= 0.0) {
            return [
                'discount.label' => '',
                'discount.percent' => '0',
                'discount.amount' => '0',
                'discount.price_discounted' => $this->numericString($base),
            ];
        }

        if ($promotion->discount_type === Promotion::TYPE_PERCENT) {
            $percent = $discount;
            $amount = round($base * ($discount / 100.0), 2);
        } else {
            $amount = round($discount, 2);
            $percent = $base > 0.0 ? round($amount / $base * 100.0, 2) : 0.0;
        }

        $discounted = max(0.0, round($base - $amount, 2));

        return [
            'discount.label' => $this->promotionLabel($promotion, $locale),
            'discount.percent' => $this->numericString((float) $percent),
            'discount.amount' => $this->numericString($amount),
            'discount.price_discounted' => $this->numericString($discounted),
        ];
    }

    /**
     * Branding text tokens: brand_header / brand_footer + req_<key>. Returns an
     * empty map for a branding-less company.
     *
     * @return array<string, mixed>
     */
    public function brandingValues(?CompanyBranding $branding, string $locale): array
    {
        if ($branding === null) {
            return [];
        }

        $values = [];

        $header = $this->localizedText($branding, 'header', $locale);
        if ($header !== null) {
            $values['brand_header'] = $header;
        }

        $footer = $this->localizedText($branding, 'footer', $locale);
        if ($footer !== null) {
            $values['brand_footer'] = $footer;
        }

        if (is_array($branding->requisites)) {
            foreach ($branding->requisites as $key => $value) {
                if (is_scalar($value)) {
                    $safeKey = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $key);
                    if ($safeKey !== '' && $safeKey !== null) {
                        $values["req_{$safeKey}"] = (string) $value;
                    }
                }
            }
        }

        return $values;
    }

    /**
     * Coerce a possibly-formatted / null value to a float (tolerates spaces and a
     * comma decimal separator).
     */
    private function numericValue(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value) && $value !== '') {
            $clean = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $value);

            return is_numeric($clean) ? (float) $clean : 0.0;
        }

        return 0.0;
    }

    /**
     * RAW numeric string: whole numbers without a decimal part, otherwise up to
     * two places with trailing zeros stripped — matching the resolver's RAW
     * convention so the engine's `format` / `words` filters behave identically on
     * computed discount values and resolved object values.
     */
    private function numericString(float $value): string
    {
        if (abs($value - round($value)) < 0.0000001) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function promotionLabel(Promotion $promotion, string $locale): string
    {
        return $this->localizedText($promotion, 'name', $locale) ?? '';
    }

    /**
     * Resolve a translatable field to a single string for the locale, falling
     * back to ru then any available translation. Null when unset/empty.
     */
    private function localizedText(object $model, string $field, string $locale): ?string
    {
        $raw = $model->getTranslations($field);

        if (! is_array($raw) || $raw === []) {
            return null;
        }

        foreach ([$locale, 'ru', 'en'] as $candidate) {
            if (isset($raw[$candidate]) && is_string($raw[$candidate]) && $raw[$candidate] !== '') {
                return $raw[$candidate];
            }
        }

        $first = reset($raw);

        return is_string($first) && $first !== '' ? $first : null;
    }
}
