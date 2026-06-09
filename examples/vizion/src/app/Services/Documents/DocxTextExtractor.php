<?php

declare(strict_types=1);

namespace App\Services\Documents;

use RuntimeException;
use ZipArchive;

/**
 * Extracts the flat text of an uploaded .docx so the AI can read the prose
 * AROUND its ${placeholder} tokens and propose a sensible placeholder→field
 * mapping (M7 DocumentTool::propose_document_fields).
 *
 * A .docx is a ZIP whose `word/document.xml` holds the body markup. We unzip
 * that single entry and strip the XML tags to a flat string — deliberately a
 * lightweight reader, NOT a full PHPWord parse: we only need readable context,
 * not structure / styles. (DocxTemplateService still uses PHPWord's
 * TemplateProcessor for the actual fill; this is the read-only sibling.)
 *
 * Context-overflow defence: the docx can be huge, and injecting the whole body
 * into the GLM prompt risks tripping the 128K context limit (the same failure
 * mode DataProbeService guards against). So the public entrypoint is
 * extractContextAroundPlaceholders() — it returns only a windowed snippet
 * around each ${token} plus a capped global preview, never the whole document.
 */
class DocxTextExtractor
{
    /**
     * Characters of context kept on each side of a placeholder occurrence.
     */
    public const CONTEXT_RADIUS = 160;

    /**
     * Hard cap on the combined extracted-context string handed to the AI. Keeps
     * the injected block bounded regardless of how many placeholders a template
     * declares, so a pathological 500-token document can't blow the context.
     */
    public const MAX_TOTAL_CHARS = 6000;

    /**
     * Read the flat (tag-stripped) text of a .docx body.
     *
     * The ${token} placeholders survive tag-stripping as literal text because
     * Word stores them as run text, so the returned string still contains the
     * tokens inline — which is exactly what makes the context windows useful.
     *
     * @throws RuntimeException when the file is missing or not a readable docx.
     */
    public function extractText(string $docxPath): string
    {
        if (!is_file($docxPath)) {
            throw new RuntimeException("Docx template not found: {$docxPath}");
        }

        $zip = new ZipArchive();
        if ($zip->open($docxPath) !== true) {
            throw new RuntimeException("Could not open docx archive: {$docxPath}");
        }

        try {
            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }

        if ($xml === false) {
            throw new RuntimeException("docx archive has no word/document.xml: {$docxPath}");
        }

        return $this->stripXml($xml);
    }

    /**
     * Build a compact, AI-ready context block: for each placeholder, a short
     * snippet of the surrounding prose ("...договор № ${agreement} от ${date}...").
     * Tokens absent from the body (declared in the template but not in the run
     * text we read) get an empty context — still listed so the AI knows the full
     * placeholder set.
     *
     * The combined output is capped at MAX_TOTAL_CHARS. When the document text is
     * small the whole thing is returned as a single preview; otherwise we emit a
     * per-token window list.
     *
     * @param  array<int, string>  $placeholders  Token names WITHOUT the ${} wrapper.
     * @return array{preview: string, contexts: array<string, string>}
     */
    public function extractContextAroundPlaceholders(string $docxPath, array $placeholders): array
    {
        $text = $this->extractText($docxPath);

        // Small documents: hand back the whole (capped) body as a single preview;
        // a per-token window list would be redundant.
        if (mb_strlen($text) <= self::MAX_TOTAL_CHARS) {
            return [
                'preview'  => $text,
                'contexts' => [],
            ];
        }

        $contexts = [];
        $budget   = self::MAX_TOTAL_CHARS;

        foreach ($placeholders as $token) {
            if (!is_string($token) || $token === '') {
                continue;
            }
            if ($budget <= 0) {
                break;
            }

            $window = $this->windowAround($text, '${' . $token . '}');
            if ($window === null) {
                $contexts[$token] = '';
                continue;
            }

            $window = mb_substr($window, 0, $budget);
            $contexts[$token] = $window;
            $budget -= mb_strlen($window);
        }

        // A short head-of-document preview for general framing (what kind of
        // document is this), bounded by whatever budget the windows left.
        $previewLen = max(0, min($budget, 1200));

        return [
            'preview'  => $previewLen > 0 ? mb_substr($text, 0, $previewLen) : '',
            'contexts' => $contexts,
        ];
    }

    /**
     * Return the substring around the first occurrence of $needle, padded by
     * CONTEXT_RADIUS on each side and prefixed/suffixed with an ellipsis when
     * truncated. Null when the needle is absent.
     */
    private function windowAround(string $haystack, string $needle): ?string
    {
        $pos = mb_strpos($haystack, $needle);
        if ($pos === false) {
            return null;
        }

        $start = max(0, $pos - self::CONTEXT_RADIUS);
        $length = mb_strlen($needle) + self::CONTEXT_RADIUS * 2;

        $window = mb_substr($haystack, $start, $length);

        if ($start > 0) {
            $window = '…' . $window;
        }
        if ($start + $length < mb_strlen($haystack)) {
            $window .= '…';
        }

        return $window;
    }

    /**
     * Turn raw document.xml into flat readable text: paragraph / break tags
     * become spaces, everything else is dropped, then whitespace is collapsed.
     * Decodes XML entities so the ${...} tokens and prose read naturally.
     */
    private function stripXml(string $xml): string
    {
        // Paragraph + line-break + tab tags → whitespace so words don't fuse.
        $xml = preg_replace('/<\/w:p>|<w:br\s*\/?>|<w:tab\s*\/?>/u', ' ', $xml) ?? $xml;

        // Drop all remaining tags.
        $text = strip_tags($xml);

        // Decode entities (&amp; &lt; &#36; etc.) so $ and braces survive intact.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        // Collapse runs of whitespace into single spaces.
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
