<?php

declare(strict_types=1);

namespace App\Services\Documents;

use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;

/**
 * Fills an uploaded Word template (.docx) carrying ${placeholder} tokens with
 * resolved data, using phpoffice/phpword's TemplateProcessor.
 *
 * This is the Word counterpart to HtmlDocumentService: both now substitute
 * through the shared DocumentFieldEngine. A docx token is the exact text PHPWord
 * reports from getVariables() — which for ${estate.price|words} is the string
 * "estate.price|words", filters included. We resolve each token through the
 * engine (canonical-key lookup + filter chain) and setValue() the engine's output
 * against that exact token name. Dotted keys and pipe filters are valid PHPWord
 * variable names, so this needs no template rewriting.
 *
 * Placeholder resolution order (per token, inside the engine):
 *   1. The token's `name` part (before the first `|`) is looked up directly in
 *      $data (canonical key, the primary path).
 *   2. config.field_mapping[name] (when supplied) is an OPTIONAL legacy fallback:
 *      a token whose name is not a canonical key can be aliased to one. It is no
 *      longer the main mechanism — canonical tokens substitute directly.
 *   3. Unresolved → empty string (an unfilled placeholder never aborts the
 *      render; it collapses, mirroring HtmlDocumentService's missing-token
 *      behaviour).
 */
class DocxTemplateService
{
    public function __construct(
        private readonly DocumentFieldEngine $engine = new DocumentFieldEngine,
    ) {}

    /**
     * Fill a .docx template with $data and write the result to a temp file,
     * returning its absolute path.
     *
     * @param  string  $sourceDocxPath  Absolute path to the uploaded template.
     * @param  array<string, mixed>  $data  Flat field => value map.
     * @param  array<string, string>  $fieldMapping  placeholder => dataKey overrides.
     * @param  string|null  $targetPath  Where to save the filled docx;
     *                                   null → a tmp path under sys_get_temp_dir().
     * @return string Absolute path to the filled .docx.
     *
     * @throws RuntimeException when the source file is missing or unreadable.
     */
    public function fill(
        string $sourceDocxPath,
        array $data,
        array $fieldMapping = [],
        ?string $targetPath = null,
    ): string {
        if (! is_file($sourceDocxPath)) {
            throw new RuntimeException("Docx template not found: {$sourceDocxPath}");
        }

        $processor = $this->makeProcessor($sourceDocxPath);

        // Substitute every placeholder the template actually declares. Iterating
        // the template's own variables (rather than $data) keeps us from setting
        // tokens that do not exist and guarantees declared-but-unmapped tokens
        // collapse to an empty string instead of leaking raw ${...}.
        foreach ($processor->getVariables() as $token) {
            $processor->setValue($token, $this->resolveToken($token, $data, $fieldMapping));
        }

        $target = $targetPath ?? $this->tempPath();
        $processor->saveAs($target);

        return $target;
    }

    /**
     * List the ${...} placeholder tokens declared in a .docx (without the ${}
     * wrapper, e.g. "client_name"). Used by the placeholders endpoint (M6 UI
     * mapping) and the AI field-proposal flow (M7).
     *
     * @return array<int, string>
     *
     * @throws RuntimeException when the source file is missing or unreadable.
     */
    public function extractPlaceholders(string $docxPath): array
    {
        if (! is_file($docxPath)) {
            throw new RuntimeException("Docx template not found: {$docxPath}");
        }

        return array_values($this->makeProcessor($docxPath)->getVariables());
    }

    /**
     * Fill a table row template that repeats per item (PHPWord cloneRow). The
     * template must declare ${$rowKey} (and sibling per-row placeholders) inside a
     * single table row; cloneRow expands it to count($rows) rows. Each $rows entry
     * is a flat map of per-row-token => value.
     *
     * Returns the filled docx path, same contract as fill(). Provided for tabular
     * documents (e.g. a price list per object); the flat-setValue fill() remains
     * the primary path.
     *
     * @param  string  $rowKey  Placeholder anchoring the row (without ${}).
     * @param  array<int, array<string, mixed>>  $rows  Per-row value maps.
     * @param  array<string, mixed>  $data  Document-level (non-row) values.
     * @param  array<string, string>  $fieldMapping
     *
     * @throws RuntimeException when the source file is missing/unreadable.
     */
    public function fillTable(
        string $sourceDocxPath,
        string $rowKey,
        array $rows,
        array $data = [],
        array $fieldMapping = [],
        ?string $targetPath = null,
    ): string {
        if (! is_file($sourceDocxPath)) {
            throw new RuntimeException("Docx template not found: {$sourceDocxPath}");
        }

        $processor = $this->makeProcessor($sourceDocxPath);

        // Clone the anchored row once per data row, then set each cloned cell's
        // value. PHPWord suffixes cloned tokens with #<index> (1-based).
        $count = count($rows);
        if ($count > 0) {
            $processor->cloneRow($rowKey, $count);

            foreach (array_values($rows) as $i => $row) {
                $suffix = $i + 1;
                foreach ($row as $token => $value) {
                    $processor->setValue("{$token}#{$suffix}", $this->stringify($value));
                }
            }
        }

        // Document-level placeholders (anything outside the cloned row).
        foreach ($processor->getVariables() as $token) {
            $processor->setValue($token, $this->resolveToken($token, $data, $fieldMapping));
        }

        $target = $targetPath ?? $this->tempPath();
        $processor->saveAs($target);

        return $target;
    }

    /**
     * Resolve a docx placeholder token to its final string through the shared
     * engine.
     *
     * The token is the raw PHPWord variable name, e.g. "estate.price|words" or a
     * legacy bare "object_label". When the token's name part is not a canonical
     * key but a field_mapping alias exists, we rewrite the name to the mapped key
     * before handing it to the engine — preserving any filter chain on the token.
     * This keeps field_mapping as an optional fallback while the primary path is a
     * direct canonical-key substitution.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $fieldMapping
     */
    private function resolveToken(string $token, array $data, array $fieldMapping): string
    {
        // Split "name|filter|..." to inspect / rewrite the name part only.
        $pipe = strpos($token, '|');
        $name = $pipe === false ? $token : substr($token, 0, $pipe);
        $name = trim($name);
        $filters = $pipe === false ? '' : substr($token, $pipe); // includes leading '|'

        // Legacy alias fallback: if the token name is not a canonical data key but
        // is aliased via field_mapping, substitute the mapped key (keeping filters).
        if (! array_key_exists($name, $data) && isset($fieldMapping[$name])) {
            $expr = $fieldMapping[$name].$filters;
        } else {
            $expr = $token;
        }

        return $this->engine->resolve($expr, $data);
    }

    /**
     * Construct a TemplateProcessor for a source .docx. Extracted so tests can
     * exercise the substitution logic against a real fixture.
     */
    private function makeProcessor(string $sourceDocxPath): TemplateProcessor
    {
        return new TemplateProcessor($sourceDocxPath);
    }

    private function tempPath(): string
    {
        return tempnam(sys_get_temp_dir(), 'vizion_docx_').'.docx';
    }
}
