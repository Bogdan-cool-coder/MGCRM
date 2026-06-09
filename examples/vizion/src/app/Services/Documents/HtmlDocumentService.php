<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\CompanyBranding;
use App\Models\DocumentTemplate;
use App\Models\Promotion;
use Illuminate\Support\Facades\Storage;

/**
 * Assembles the final HTML document from a template + the resolved object data,
 * ready to hand to GotenbergClient::htmlToPdf() (or to return as a live preview).
 *
 * HTML source resolution:
 *   1. template.source_path set → load the HTML from the documents disk (an
 *      uploaded HTML source, the docx-upload mirror for html templates).
 *   2. otherwise → template.config['html'] (the AI-generated / seeded body).
 *
 * Substitution is delegated to DocumentFieldEngine, which understands BOTH
 * ${name|filter} (uploaded / seeded templates) and {{name|filter}} (AI configs).
 * The data map is built by DocumentDataAssembler (object data + discount.* +
 * common.today + branding text tokens) so html and docx share one definition.
 *
 * Branding palette / fonts / logo are applied as CSS variables / a wrapped
 * document shell — not as substituted tokens (the logo can still be referenced by
 * the author via ${branding.logo_url} / {{logo}} when present in the data map).
 */
class HtmlDocumentService
{
    public function __construct(
        private readonly DocumentFieldEngine $engine = new DocumentFieldEngine,
        private readonly DocumentDataAssembler $assembler = new DocumentDataAssembler,
    ) {}

    /**
     * Build the HTML string for a template.
     *
     * @param  DocumentTemplate  $template  The html-type template.
     * @param  array<string, mixed>  $data  Canonical resolver output
     *                                      (estate.* / deal.* / ...), plus
     *                                      any caller-supplied values.
     * @param  CompanyBranding|null  $branding  Per-company brand profile (null →
     *                                          defaults; never fails on absence).
     * @param  string  $locale  Locale for translatable text.
     * @param  Promotion|null  $promotion  Selected discount campaign or null.
     * @param  float  $discount  Applied discount value.
     */
    public function buildHtml(
        DocumentTemplate $template,
        array $data,
        ?CompanyBranding $branding = null,
        string $locale = 'ru',
        ?Promotion $promotion = null,
        float $discount = 0.0,
    ): string {
        $config = $template->config ?? [];

        $body = $this->resolveSourceHtml($template, $config);

        // Allow the author to reference the logo URL as a token in addition to the
        // CSS / <img> treatment below. {{logo}} is the legacy token name; the
        // canonical branding.logo_url is also exposed.
        $logoUrl = $this->logoUrl($branding);

        // Build the canonical substitution map: object data + discount.* +
        // common.today + branding text tokens. Caller data already merged into
        // $data wins where it overlaps the resolver keys; the assembler's
        // injected render-only keys win over those in turn.
        $contextData = $this->assembler->assemble($data, $branding, $promotion, $discount, $locale);

        if ($logoUrl !== null) {
            // Only set when absent so an explicit caller-supplied logo wins.
            $contextData['logo'] ??= $logoUrl;
            $contextData['branding.logo_url'] ??= $logoUrl;
        }

        $html = $this->engine->renderHtml($body, $contextData);

        // Wrap a fragment in a minimal document shell (branding CSS vars + author
        // css). A full <html> document is left untouched.
        if (! $this->isFullDocument($html)) {
            $css = is_string($config['css'] ?? null) ? $config['css'] : '';
            $html = $this->wrapDocument($html, $css, $branding);
        }

        return $html;
    }

    /**
     * Resolve the HTML body: an uploaded source_path on the documents disk wins;
     * otherwise the config['html'] string. Missing both → empty fragment (the
     * shell still renders, no raw markup leaks).
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveSourceHtml(DocumentTemplate $template, array $config): string
    {
        $sourcePath = $template->source_path;

        if (is_string($sourcePath) && $sourcePath !== '') {
            $disk = Storage::disk(config('documents.disk'));
            if ($disk->exists($sourcePath)) {
                $loaded = $disk->get($sourcePath);
                if (is_string($loaded)) {
                    return $loaded;
                }
            }
        }

        return is_string($config['html'] ?? null) ? $config['html'] : '';
    }

    /**
     * Public logo URL from the branding profile (null when unset).
     */
    private function logoUrl(?CompanyBranding $branding): ?string
    {
        if ($branding === null || $branding->logo_path === null) {
            return null;
        }

        return Storage::disk('public')->url($branding->logo_path);
    }

    /**
     * Build the :root CSS-variable block + font-family rules from the company
     * palette/fonts (falling back to CompanyBranding defaults).
     */
    private function brandingCss(?CompanyBranding $branding): string
    {
        $colors = is_array($branding?->colors) ? $branding->colors : [];
        $colors = array_merge(CompanyBranding::DEFAULT_COLORS, $colors);

        $fonts = is_array($branding?->fonts) ? $branding->fonts : [];
        $fonts = array_merge(CompanyBranding::DEFAULT_FONTS, $fonts);

        $vars = [];
        foreach ($colors as $key => $value) {
            if (is_string($value) && $value !== '') {
                $safeKey = preg_replace('/[^a-z0-9_-]/i', '', (string) $key);
                $safeVal = $this->sanitizeCssValue((string) $value);
                if ($safeKey !== '' && $safeVal !== '') {
                    $vars[] = "  --brand-{$safeKey}: {$safeVal};";
                }
            }
        }

        $headingFont = $this->sanitizeCssValue((string) ($fonts['heading'] ?? ''));
        $bodyFont = $this->sanitizeCssValue((string) ($fonts['body'] ?? ''));
        if ($headingFont !== '') {
            $vars[] = "  --brand-font-heading: {$headingFont};";
        }
        if ($bodyFont !== '') {
            $vars[] = "  --brand-font-body: {$bodyFont};";
        }

        $rootVars = implode("\n", $vars);

        $bodyRule = $bodyFont !== '' ? 'body { font-family: var(--brand-font-body); }' : '';
        $headingRule = $headingFont !== ''
            ? 'h1, h2, h3, h4 { font-family: var(--brand-font-heading); }'
            : '';

        return <<<CSS
            :root {
            {$rootVars}
            }
            {$bodyRule}
            {$headingRule}
            CSS;
    }

    /**
     * Strip characters that could break out of a CSS declaration. Branding values
     * flow into a <style> block, so they must never carry markup / extra rules.
     */
    private function sanitizeCssValue(string $value): string
    {
        return trim((string) preg_replace('/[;{}<>\r\n]/', '', $value));
    }

    private function isFullDocument(string $html): bool
    {
        return (bool) preg_match('/<html[\s>]/i', $html);
    }

    private function wrapDocument(string $bodyHtml, string $css, ?CompanyBranding $branding = null): string
    {
        $brandCss = $this->brandingCss($branding);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="ru">
            <head>
            <meta charset="utf-8">
            <style>
            @page { size: A4; margin: 16mm; }
            body { font-family: 'DejaVu Sans', Arial, sans-serif; color: var(--brand-text, #222); background: var(--brand-bg, #fff); }
            .page-break { page-break-after: always; }
            {$brandCss}
            {$css}
            </style>
            </head>
            <body>
            {$bodyHtml}
            </body>
            </html>
            HTML;
    }
}
