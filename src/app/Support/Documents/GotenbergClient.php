<?php

declare(strict_types=1);

namespace App\Support\Documents;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client over the Gotenberg REST API.
 *
 * Adapted 1-for-1 from examples/vizion — config reads from config/contracts.php
 * (gotenberg_url) instead of config/documents.php as in Vizion.
 *
 * LibreOffice endpoint: POST /forms/libreoffice/convert
 *   — accepts an office file (docx, odt, …), returns raw PDF bytes.
 *
 * Used in S2.3 for test-conversion (ai_check pdf_ok flag) and in S2.4 for
 * actual template rendering.
 */
class GotenbergClient
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?int $timeout = null,
    ) {}

    private function url(string $path): string
    {
        $base = $this->baseUrl ?? (string) config('contracts.gotenberg_url', 'http://gotenberg:3000');

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }

    private function timeoutSeconds(): int
    {
        return $this->timeout ?? 120;
    }

    /**
     * Convert an office document (docx, odt, …) on disk to PDF via LibreOffice.
     *
     * @param  string  $docxPath  Absolute filesystem path to the office file.
     * @return string Raw PDF bytes.
     *
     * @throws RuntimeException when the file is missing or Gotenberg fails.
     */
    public function officeToPdf(string $docxPath): string
    {
        if (! is_file($docxPath)) {
            throw new RuntimeException("Office file not found for conversion: {$docxPath}");
        }

        $contents = file_get_contents($docxPath);
        if ($contents === false) {
            throw new RuntimeException("Unable to read office file: {$docxPath}");
        }

        $filename = basename($docxPath);

        $response = Http::timeout($this->timeoutSeconds())
            ->attach('files', $contents, $filename)
            ->post($this->url('/forms/libreoffice/convert'));

        return $this->ensurePdf($response, 'office->PDF');
    }

    /**
     * Convert an HTML document (plus optional assets) to PDF via Chromium.
     *
     * @param  string  $html  Full HTML document.
     * @param  array<string, string>  $assets  filename => raw contents.
     * @param  array<string, scalar>  $opts  Extra Gotenberg form fields.
     * @return string Raw PDF bytes.
     *
     * @throws RuntimeException on a non-2xx Gotenberg response.
     */
    public function htmlToPdf(string $html, array $assets = [], array $opts = []): string
    {
        $request = Http::timeout($this->timeoutSeconds())
            ->attach('index.html', $html, 'index.html');

        foreach ($assets as $filename => $contents) {
            $request = $request->attach($filename, $contents, $filename);
        }

        $response = $request->post(
            $this->url('/forms/chromium/convert/html'),
            $this->stringifyOpts($opts),
        );

        return $this->ensurePdf($response, 'HTML->PDF');
    }

    /** @param  array<string, scalar>  $opts */
    private function stringifyOpts(array $opts): array
    {
        $out = [];
        foreach ($opts as $key => $value) {
            $out[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $out;
    }

    /** @throws RuntimeException on a failed response. */
    private function ensurePdf(Response $response, string $context): string
    {
        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Gotenberg %s failed (HTTP %d): %s',
                $context,
                $response->status(),
                mb_substr($response->body(), 0, 500),
            ));
        }

        return $response->body();
    }
}
