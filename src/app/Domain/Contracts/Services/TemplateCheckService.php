<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

use App\Domain\Contracts\Models\TemplateVariable;
use App\Domain\Contracts\Models\TemplateVersion;
use App\Services\AI\AiRetryService;
use App\Services\Documents\GotenbergClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Row;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use RuntimeException;

/**
 * TemplateCheckService — AI-powered docx template review (S2.3).
 *
 * Algorithm:
 *   1. Extract text from docx via PHPWord IOFactory.
 *   2. Load active TemplateVariable keys for the prompt.
 *   3. Call Prism (Anthropic) via AiRetryService with cascade 'document_template'.
 *   4. Parse strict {"remarks": [...]} JSON from the response.
 *   5. Test-convert via Gotenberg → set pdf_ok flag.
 *   6. Return ['remarks' => array, 'pdf_ok' => bool].
 *
 * All Throwable are caught by the caller (CheckTemplateJob).
 * This service does NOT save to the DB — the job does.
 */
class TemplateCheckService
{
    /**
     * Maximum text length fed to the AI prompt.
     * PHPWord templates typically 30-50 pages ≈ 30-80K chars; hard-cap at 80K
     * to avoid context-overflow; a truncation warning remark is prepended.
     */
    private const MAX_TEXT_LENGTH = 80_000;

    public function __construct(
        private readonly AiRetryService $aiRetry,
        private readonly GotenbergClient $gotenberg,
    ) {}

    /**
     * Run the full AI check pipeline for a template version.
     *
     * @return array{remarks: array<int, array<string, string>>, pdf_ok: bool}
     */
    public function check(TemplateVersion $version): array
    {
        $absPath = Storage::disk('documents')->path($version->docx_path ?? '');

        // Step 1: Extract text
        $phpWord = IOFactory::load($absPath);
        $text = $this->extractText($phpWord);

        // Step 2: Known variable keys
        $knownVars = TemplateVariable::query()
            ->where('is_active', true)
            ->pluck('key')
            ->toArray();

        // Step 3: Build and send Prism request
        $systemPrompt = file_get_contents(base_path('TEMPLATE_CHECK_PROMPT.md'));
        if ($systemPrompt === false) {
            throw new RuntimeException('TEMPLATE_CHECK_PROMPT.md not found at '.base_path('TEMPLATE_CHECK_PROMPT.md'));
        }

        $userMessage = new UserMessage(implode("\n\n", [
            '## Текст шаблона:',
            $text,
            '## Известные переменные ({{ ... }}):',
            implode(', ', $knownVars),
        ]));

        $response = $this->aiRetry->executeWithRetry(
            chatType: 'document_template',
            systemPrompt: $systemPrompt,
            messages: [$userMessage],
            tools: [],
        );

        // Step 4: Parse JSON from AI response
        $remarks = $this->parseAiResponse($response->text);

        // Step 5: Test-convert via Gotenberg
        $pdfOk = false;
        try {
            $pdfBytes = $this->gotenberg->officeToPdf($absPath);
            if (strlen($pdfBytes) > 1000) {
                $pdfOk = true;
            } else {
                $remarks[] = [
                    'type' => 'conversion_error',
                    'severity' => 'error',
                    'text' => 'Gotenberg вернул слишком маленький PDF (< 1KB) — возможно повреждённый docx',
                ];
            }
        } catch (\Throwable $e) {
            // Catches both RuntimeException (bad response) and
            // Illuminate\Http\Client\ConnectionException (network error).
            $remarks[] = [
                'type' => 'conversion_error',
                'severity' => 'error',
                'text' => 'Ошибка конвертации в PDF: '.$e->getMessage(),
            ];
        }

        return ['remarks' => $remarks, 'pdf_ok' => $pdfOk];
    }

    /**
     * Recursively extract all text from a PhpWord document.
     *
     * Iterates Sections → Elements (TextRun, Text, Table, etc.).
     * Truncates at MAX_TEXT_LENGTH and prepends a warning remark placeholder.
     *
     * @throws RuntimeException when the document contains no text.
     */
    private function extractText(PhpWord $phpWord): string
    {
        $parts = [];

        foreach ($phpWord->getSections() as $section) {
            $parts[] = $this->extractFromContainer($section);
        }

        $text = implode("\n", array_filter($parts, static fn (string $p): bool => $p !== ''));

        if (trim($text) === '') {
            throw new RuntimeException('Empty template document');
        }

        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_TEXT_LENGTH)
                ."\n\n[TRUNCATED: document exceeds 80K characters]";
        }

        return $text;
    }

    /**
     * Extract text from any PHPWord container (Section, Cell, etc.).
     */
    private function extractFromContainer(AbstractContainer $container): string
    {
        $parts = [];

        foreach ($container->getElements() as $element) {
            if ($element instanceof TextRun) {
                $run = '';
                foreach ($element->getElements() as $child) {
                    if ($child instanceof Text) {
                        $run .= $child->getText();
                    }
                }
                if ($run !== '') {
                    $parts[] = $run;
                }
            } elseif ($element instanceof Text) {
                $t = $element->getText();
                if ($t !== '') {
                    $parts[] = $t;
                }
            } elseif ($element instanceof Table) {
                foreach ($element->getRows() as $row) {
                    if ($row instanceof Row) {
                        foreach ($row->getCells() as $cell) {
                            if ($cell instanceof Cell) {
                                $cellText = $this->extractFromContainer($cell);
                                if ($cellText !== '') {
                                    $parts[] = $cellText;
                                }
                            }
                        }
                    }
                }
            } elseif ($element instanceof AbstractContainer) {
                $nested = $this->extractFromContainer($element);
                if ($nested !== '') {
                    $parts[] = $nested;
                }
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Parse the {"remarks": [...]} JSON from an AI response.
     *
     * Tries to unwrap a ```json ... ``` code block first; falls back to bare JSON.
     * On parse failure: logs the raw text and returns a single parse_error remark.
     *
     * @return array<int, array<string, string>>
     */
    private function parseAiResponse(string $raw): array
    {
        // Strip ```json / ``` wrapper if present
        $json = preg_replace('/^```json\s*/m', '', $raw) ?? $raw;
        $json = preg_replace('/\s*```$/m', '', $json) ?? $json;
        $json = trim($json);

        try {
            /** @var array{remarks?: array<int, array<string, string>>}|null $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('TemplateCheckService: AI returned invalid JSON', [
                'raw' => mb_substr($raw, 0, 2000),
                'error' => $e->getMessage(),
            ]);

            return [[
                'type' => 'parse_error',
                'severity' => 'error',
                'text' => 'AI вернул невалидный JSON. Перепроверьте шаблон вручную.',
            ]];
        }

        return $data['remarks'] ?? [];
    }
}
