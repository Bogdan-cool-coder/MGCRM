<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\CompanyBranding;
use App\Models\DocumentTemplate;
use App\Models\Promotion;
use App\Services\Documents\GotenbergClient;
use App\Services\Documents\HtmlDocumentService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Unit-ish tests for the render building blocks (no DB, no live Gotenberg):
 *   - HtmlDocumentService substitutes {{tokens}}, drops unknown ones, wraps a
 *     fragment in a document shell, leaves a full document untouched.
 *   - GotenbergClient posts multipart to the right endpoint and returns bytes.
 */
class HtmlDocumentServiceTest extends TestCase
{
    /** @test */
    public function test_substitutes_known_tokens_and_drops_unknown(): void
    {
        $template = new DocumentTemplate;
        $template->config = ['html' => '<p>${estate.complex_name} / ${missing} / ${estate.area|format}</p>'];

        $html = (new HtmlDocumentService)->buildHtml($template, [
            'estate.complex_name' => 'ЖК Радуга',
            'estate.area' => 65,
        ]);

        $this->assertStringContainsString('ЖК Радуга', $html);
        $this->assertStringContainsString('65', $html);
        $this->assertStringNotContainsString('${missing}', $html);
        $this->assertStringNotContainsString('${estate.complex_name}', $html);
    }

    /** @test */
    public function test_substitutes_double_brace_tokens_for_ai_configs(): void
    {
        // AI-generated configs use {{...}}; the engine handles both syntaxes.
        $template = new DocumentTemplate;
        $template->config = ['html' => '<p>{{estate.complex_name}}</p>'];

        $html = (new HtmlDocumentService)->buildHtml($template, [
            'estate.complex_name' => 'ЖК Радуга',
        ]);

        $this->assertStringContainsString('ЖК Радуга', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    /** @test */
    public function test_wraps_fragment_in_document_shell(): void
    {
        $template = new DocumentTemplate;
        $template->config = ['html' => '<p>hi</p>', 'css' => '.x{color:red}'];

        $html = (new HtmlDocumentService)->buildHtml($template, []);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('.x{color:red}', $html);
    }

    /** @test */
    public function test_leaves_full_document_untouched(): void
    {
        $template = new DocumentTemplate;
        $template->config = ['html' => '<html><body><p>{{name}}</p></body></html>'];

        $html = (new HtmlDocumentService)->buildHtml($template, ['name' => 'X']);

        // Only one <html> root — no double-wrapping.
        $this->assertSame(1, substr_count($html, '<html'));
        $this->assertStringContainsString('<p>X</p>', $html);
    }

    /** @test */
    public function test_defaults_applied_when_no_branding(): void
    {
        $template = new DocumentTemplate;
        $template->config = ['html' => '<p>hi</p>'];

        // No branding argument → default palette injected as CSS variables.
        $html = (new HtmlDocumentService)->buildHtml($template, []);

        $this->assertStringContainsString(':root', $html);
        $this->assertStringContainsString('--brand-primary: '.CompanyBranding::DEFAULT_COLORS['primary'], $html);
        $this->assertStringContainsString('--brand-bg: '.CompanyBranding::DEFAULT_COLORS['bg'], $html);
    }

    /** @test */
    public function test_branding_injects_palette_fonts_and_logo(): void
    {
        $template = new DocumentTemplate;
        $template->config = ['html' => '<header><img src="{{logo}}"></header><p>{{brand_header}}</p>'];

        $branding = new CompanyBranding;
        $branding->logo_path = 'branding/1/logo.png';
        $branding->colors = ['primary' => '#abcdef', 'bg' => '#101010'];
        $branding->fonts = ['heading' => 'Roboto', 'body' => 'Arial'];
        // setTranslation populates the translatable jsonb shape.
        $branding->setTranslation('header', 'ru', 'Привет');
        $branding->setTranslation('header', 'en', 'Hello');

        $html = (new HtmlDocumentService)->buildHtml($template, [], $branding, 'ru');

        // Palette + fonts as CSS variables.
        $this->assertStringContainsString('--brand-primary: #abcdef', $html);
        $this->assertStringContainsString('--brand-bg: #101010', $html);
        $this->assertStringContainsString('--brand-font-heading: Roboto', $html);
        // Logo URL resolved through the public disk.
        $this->assertStringContainsString('branding/1/logo.png', $html);
        // Localized header text substituted.
        $this->assertStringContainsString('Привет', $html);
    }

    /** @test */
    public function test_branding_header_falls_back_when_locale_missing(): void
    {
        $template = new DocumentTemplate;
        $template->config = ['html' => '<p>{{brand_footer}}</p>'];

        $branding = new CompanyBranding;
        $branding->setTranslation('footer', 'ru', 'Реквизиты');

        // Request en, only ru exists → falls back to ru.
        $html = (new HtmlDocumentService)->buildHtml($template, [], $branding, 'en');

        $this->assertStringContainsString('Реквизиты', $html);
    }

    /** @test */
    public function test_branding_css_values_are_sanitized(): void
    {
        $template = new DocumentTemplate;
        $template->config = ['html' => '<p>x</p>'];

        $branding = new CompanyBranding;
        // Malicious value trying to break out of the declaration / inject markup.
        $branding->colors = ['primary' => 'red; } body{display:none} <script>'];

        $html = (new HtmlDocumentService)->buildHtml($template, [], $branding);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('body{display:none}', $html);
    }

    /** @test */
    public function test_caller_data_overrides_branding_logo(): void
    {
        $template = new DocumentTemplate;
        $template->config = ['html' => '<img src="{{logo}}">'];

        $branding = new CompanyBranding;
        $branding->logo_path = 'branding/1/brand-logo.png';

        // Explicit caller-supplied logo wins over the branding logo.
        $html = (new HtmlDocumentService)->buildHtml($template, ['logo' => 'http://x/custom.png'], $branding);

        $this->assertStringContainsString('http://x/custom.png', $html);
        $this->assertStringNotContainsString('brand-logo.png', $html);
    }

    /** @test */
    public function test_percent_discount_injects_price_block(): void
    {
        $template = new DocumentTemplate;
        $template->config = [
            'html' => '<p>${discount.label}: -${discount.percent}%, '
                .'итого ${discount.price_discounted} (${discount.price_discounted|format})</p>',
        ];

        $promotion = new Promotion;
        $promotion->discount_type = Promotion::TYPE_PERCENT;
        $promotion->setTranslation('name', 'ru', 'Весна');

        // base 10 000 000, 10% off → 9 000 000.
        $html = (new HtmlDocumentService)->buildHtml(
            $template,
            ['estate.price' => 10000000],
            null,
            'ru',
            $promotion,
            10.0,
        );

        $this->assertStringContainsString('Весна', $html);
        $this->assertStringContainsString('-10%', $html);
        $this->assertStringContainsString('9000000', $html);
        $this->assertStringContainsString('9 000 000', $html);
    }

    /** @test */
    public function test_absolute_discount_injects_price_block(): void
    {
        $template = new DocumentTemplate;
        $template->config = [
            'html' => '<p>-${discount.amount} → ${discount.price_discounted}</p>',
        ];

        $promotion = new Promotion;
        $promotion->discount_type = Promotion::TYPE_ABSOLUTE;
        $promotion->setTranslation('name', 'ru', 'Скидка');

        // base 5 000 000, minus 250 000 → 4 750 000.
        $html = (new HtmlDocumentService)->buildHtml(
            $template,
            ['estate.price' => 5000000],
            null,
            'ru',
            $promotion,
            250000.0,
        );

        $this->assertStringContainsString('-250000', $html);
        $this->assertStringContainsString('4750000', $html);
    }

    /** @test */
    public function test_no_promotion_leaves_price_unchanged(): void
    {
        $template = new DocumentTemplate;
        $template->config = [
            'html' => '<p>amount=${discount.amount} price=${discount.price_discounted} '
                .'label=[${discount.label}]</p>',
        ];

        // No promotion → amount 0, discounted == base, label empty, never fails.
        $html = (new HtmlDocumentService)->buildHtml(
            $template,
            ['estate.price' => 3000000],
        );

        $this->assertStringContainsString('amount=0', $html);
        $this->assertStringContainsString('price=3000000', $html);
        $this->assertStringContainsString('label=[]', $html);
    }

    /** @test */
    public function test_absolute_discount_never_goes_negative(): void
    {
        $template = new DocumentTemplate;
        $template->config = ['html' => '<p>${discount.price_discounted}</p>'];

        $promotion = new Promotion;
        $promotion->discount_type = Promotion::TYPE_ABSOLUTE;

        // Discount larger than the base price → clamps to 0, not negative.
        $html = (new HtmlDocumentService)->buildHtml(
            $template,
            ['estate.price' => 1000],
            null,
            'ru',
            $promotion,
            5000.0,
        );

        $this->assertStringContainsString('<p>0</p>', $html);
    }

    /** @test */
    public function test_renders_html_from_uploaded_source_path(): void
    {
        // When source_path is set, the HTML is loaded from the documents disk and
        // substituted — the config.html body is not used.
        \Illuminate\Support\Facades\Storage::fake('documents');
        \Illuminate\Support\Facades\Storage::disk('documents')->put(
            'document-templates/5/template.html',
            '<p>Источник: ${estate.complex_name}</p>',
        );

        $template = new DocumentTemplate;
        $template->source_path = 'document-templates/5/template.html';
        $template->config = ['html' => '<p>config fallback ${estate.complex_name}</p>'];

        $html = (new HtmlDocumentService)->buildHtml($template, [
            'estate.complex_name' => 'ЖК Радуга',
        ]);

        $this->assertStringContainsString('Источник: ЖК Радуга', $html);
        $this->assertStringNotContainsString('config fallback', $html);
    }

    /** @test */
    public function test_gotenberg_html_to_pdf_posts_multipart_and_returns_bytes(): void
    {
        Http::fake([
            '*/forms/chromium/convert/html' => Http::response('%PDF-1.4 bytes', 200),
        ]);

        $client = new GotenbergClient('http://gotenberg:3000', 30);
        $pdf = $client->htmlToPdf('<html><body>hi</body></html>');

        $this->assertStringContainsString('%PDF', $pdf);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/forms/chromium/convert/html')
                && $request->isMultipart();
        });
    }

    /** @test */
    public function test_gotenberg_throws_on_failure(): void
    {
        Http::fake([
            '*/forms/chromium/convert/html' => Http::response('boom', 500),
        ]);

        $this->expectException(\RuntimeException::class);

        (new GotenbergClient('http://gotenberg:3000', 30))->htmlToPdf('<html></html>');
    }
}
