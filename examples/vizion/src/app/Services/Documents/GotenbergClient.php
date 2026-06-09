<?php

declare(strict_types=1);

namespace App\Services\Documents;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client over the Gotenberg REST API.
 *
 * Gotenberg expects multipart/form-data uploads:
 *   - Chromium HTML->PDF  : POST /forms/chromium/convert/html  (index.html + assets)
 *   - LibreOffice ->PDF   : POST /forms/libreoffice/convert     (the office file)
 *
 * Both endpoints stream back the raw PDF bytes, which we return as a string for
 * the caller to persist (GenerateDocumentJob writes them to disk local).
 *
 * The base URL + timeout come from config/documents.php (env GOTENBERG_URL,
 * GOTENBERG_TIMEOUT) — never read env() directly here.
 */
class GotenbergClient
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?int $timeout = null,
    ) {
    }

    private function url(string $path): string
    {
        $base = $this->baseUrl ?? (string) config('documents.gotenberg_url');

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    private function timeoutSeconds(): int
    {
        return $this->timeout ?? (int) config('documents.gotenberg_timeout', 120);
    }

    /**
     * Convert an HTML document (plus optional assets — logo, fonts, css) to PDF.
     *
     * The HTML must be uploaded under the filename `index.html` (Gotenberg
     * requirement). Assets are uploaded under their own filenames and can be
     * referenced from the HTML by relative path.
     *
     * @param  string                $html    The full HTML document.
     * @param  array<string, string> $assets  filename => raw file contents.
     * @param  array<string, scalar> $opts    Extra Gotenberg form fields
     *                                         (e.g. ['marginTop' => '0.5',
     *                                         'printBackground' => 'true']).
     * @return string  Raw PDF bytes.
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

    /**
     * Convert an office document (docx, odt, ...) on disk to PDF via LibreOffice.
     *
     * @param  string  $docxPath  Absolute filesystem path to the office file.
     * @return string  Raw PDF bytes.
     *
     * @throws RuntimeException when the file is missing or Gotenberg fails.
     */
    public function officeToPdf(string $docxPath): string
    {
        if (!is_file($docxPath)) {
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
     * Gotenberg only accepts string-valued multipart fields.
     *
     * @param  array<string, scalar>  $opts
     * @return array<string, string>
     */
    private function stringifyOpts(array $opts): array
    {
        $out = [];
        foreach ($opts as $key => $value) {
            $out[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $out;
    }

    /**
     * @throws RuntimeException on a failed response.
     */
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
