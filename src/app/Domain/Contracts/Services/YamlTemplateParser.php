<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

use App\Domain\Contracts\Models\LicensorEntity;
use App\Domain\Contracts\Models\Template;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * YamlTemplateParser — merges three YAML layers into a context array for PHPWord.
 *
 * Three layers:
 *   1. product_{code}.yaml   — copyright chain, modules table, brief/tech fields
 *   2. country_{code}.yaml   — legal references, VAT, currency, licensor fallback
 *   3. LicensorEntity from DB — overrides country.licensor if present
 *
 * S2.4 will add sublicensee, license and contract keys to the returned context.
 */
class YamlTemplateParser
{
    /**
     * Parse raw YAML string into an array.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException on invalid YAML
     */
    public function parse(string $yamlContent): array
    {
        try {
            $result = Yaml::parse($yamlContent);
        } catch (ParseException $e) {
            throw new RuntimeException('Invalid YAML content: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($result)) {
            throw new RuntimeException('YAML content must parse to an associative array, got: '.gettype($result));
        }

        return $result;
    }

    /**
     * Build the rendering context by merging all three layers.
     *
     * @param  array<string, mixed>  $custom  Custom variable overrides (Contract.context['custom'])
     * @return array{product: array<string,mixed>, country: array<string,mixed>, licensor: array<string,mixed>|null, custom: array<string,mixed>}
     */
    public function buildContext(string $productCode, string $countryCode, array $custom = []): array
    {
        $product = $this->getProductLayer($productCode);
        $country = $this->getCountryLayer($countryCode);

        // Resolve licensor: DB entity takes priority over YAML fallback.
        $licensorEntity = LicensorEntity::query()
            ->forCountry($countryCode)
            ->first();

        if ($licensorEntity !== null) {
            $licensor = $licensorEntity->toArray();
        } elseif (isset($country['licensor']) && is_array($country['licensor'])) {
            // Legacy fallback: use the licensor block from country YAML.
            $licensor = $country['licensor'];
        } else {
            $licensor = null;
        }

        return [
            'product' => $product,
            'country' => $country,
            'licensor' => $licensor,
            'custom' => $custom,
            // S2.4 will add: 'sublicensee', 'license', 'contract'
        ];
    }

    /**
     * Load and parse a product_*.yaml from the Template table.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException if template not found or content is empty
     */
    public function getProductLayer(string $code): array
    {
        $template = Template::where('code', "product_{$code}")->first();

        if ($template === null) {
            throw new RuntimeException("Product template not found for code: product_{$code}");
        }

        if (empty($template->content)) {
            return [];
        }

        return $this->parse($template->content);
    }

    /**
     * Load and parse a country_*.yaml from the Template table.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException if template not found
     */
    public function getCountryLayer(string $code): array
    {
        $template = Template::where('code', "country_{$code}")->first();

        if ($template === null) {
            throw new RuntimeException("Country template not found for code: country_{$code}");
        }

        if (empty($template->content)) {
            return [];
        }

        return $this->parse($template->content);
    }
}
