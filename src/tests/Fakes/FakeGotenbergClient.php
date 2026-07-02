<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Support\Documents\GotenbergClient;

/**
 * In-memory stand-in for the real GotenbergClient used as the default test
 * binding (see Tests\TestCase::fakeGotenbergByDefault()).
 *
 * It performs NO network I/O — it just returns deterministic, valid PDF bytes
 * for any conversion call. This neutralizes PDF generation that fires as a
 * *side effect* of unrelated flows (e.g. GenerateCertificateJob dispatched
 * synchronously when a course/quiz/lesson is completed), so tests whose subject
 * is the lesson/quiz never punch through to the live http://gotenberg:3000.
 *
 * Tests that specifically exercise the REAL HTTP layer (Contracts
 * TemplateCheck / ContractGeneration) construct `new GotenbergClient` directly
 * and pair it with their own Http::fake([...]) — they do NOT use this fake.
 *
 * The stub PDF is intentionally >1KB so it passes downstream "is this a real
 * PDF?" size checks (TemplateCheckService treats a sub-1KB body as a corrupt
 * conversion).
 */
final class FakeGotenbergClient extends GotenbergClient
{
    /** Deterministic, valid-enough PDF body, padded above the 1KB sanity floor. */
    public const STUB_PDF = "%PDF-1.4\n% fake gotenberg stub for tests\n";

    public function officeToPdf(string $docxPath): string
    {
        return $this->stubPdf();
    }

    /**
     * @param  array<string, string>  $assets
     * @param  array<string, scalar>  $opts
     */
    public function htmlToPdf(string $html, array $assets = [], array $opts = []): string
    {
        return $this->stubPdf();
    }

    private function stubPdf(): string
    {
        return self::STUB_PDF.str_repeat('0', 1100)."\n%%EOF\n";
    }
}
