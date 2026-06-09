<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use App\Services\Documents\DocumentFieldEngine;
use Tests\TestCase;

/**
 * Unit tests for DocumentFieldEngine — the shared placeholder substitution
 * engine used by both the html and docx render paths.
 *
 * Covers: both placeholder syntaxes (${} and {{}}), every filter, words on a
 * money amount vs a plain number, date / date_words, empty-key collapse, filter
 * chains, and the unknown-filter pass-through.
 */
class DocumentFieldEngineTest extends TestCase
{
    private DocumentFieldEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new DocumentFieldEngine;
    }

    // -------------------------------------------------------------------------
    // Both syntaxes
    // -------------------------------------------------------------------------

    /** @test */
    public function test_renders_dollar_brace_syntax(): void
    {
        $html = $this->engine->renderHtml('ЖК: ${estate.complex_name}', [
            'estate.complex_name' => 'Звезда',
        ]);

        $this->assertSame('ЖК: Звезда', $html);
    }

    /** @test */
    public function test_renders_double_brace_syntax(): void
    {
        $html = $this->engine->renderHtml('ЖК: {{estate.complex_name}}', [
            'estate.complex_name' => 'Звезда',
        ]);

        $this->assertSame('ЖК: Звезда', $html);
    }

    /** @test */
    public function test_renders_both_syntaxes_in_one_string(): void
    {
        $html = $this->engine->renderHtml('${estate.number} / {{estate.floor}}', [
            'estate.number' => '42',
            'estate.floor' => '7',
        ]);

        $this->assertSame('42 / 7', $html);
    }

    /** @test */
    public function test_missing_key_collapses_to_empty_in_both_syntaxes(): void
    {
        $html = $this->engine->renderHtml('[${nope}][{{also.nope}}]', []);

        $this->assertSame('[][]', $html);
        $this->assertStringNotContainsString('${', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    // -------------------------------------------------------------------------
    // resolve() — name lookup + empty key
    // -------------------------------------------------------------------------

    /** @test */
    public function test_resolve_returns_value_for_known_key(): void
    {
        $this->assertSame('Звезда', $this->engine->resolve('estate.complex_name', [
            'estate.complex_name' => 'Звезда',
        ]));
    }

    /** @test */
    public function test_resolve_empty_for_missing_key(): void
    {
        $this->assertSame('', $this->engine->resolve('estate.price', []));
    }

    /** @test */
    public function test_resolve_empty_key_is_empty_string(): void
    {
        $this->assertSame('', $this->engine->resolve('', ['x' => 'y']));
    }

    /** @test */
    public function test_filter_on_empty_value_short_circuits_to_empty(): void
    {
        // An absent money field must NOT format to "ноль рублей" / "0".
        $this->assertSame('', $this->engine->resolve('estate.price|words', []));
        $this->assertSame('', $this->engine->resolve('estate.price|format', []));
        $this->assertSame('', $this->engine->resolve('deal.date|date', []));
    }

    // -------------------------------------------------------------------------
    // format filter
    // -------------------------------------------------------------------------

    /** @test */
    public function test_format_thousands_separates_whole_number(): void
    {
        $this->assertSame('3 500 000', $this->engine->resolve('estate.price|format', [
            'estate.price' => '3500000',
        ]));
    }

    /** @test */
    public function test_format_keeps_decimals_with_comma_separator(): void
    {
        $this->assertSame('65,4', $this->engine->resolve('estate.area|format', [
            'estate.area' => '65.4',
        ]));
    }

    /** @test */
    public function test_format_passes_through_non_numeric(): void
    {
        $this->assertSame('n/a', $this->engine->resolve('x|format', ['x' => 'n/a']));
    }

    // -------------------------------------------------------------------------
    // words filter — money vs plain number
    // -------------------------------------------------------------------------

    /** @test */
    public function test_words_on_money_key_spells_roubles_and_kopecks(): void
    {
        // estate.price is a money key (catalogue filters include words + rouble).
        $words = $this->engine->resolve('estate.price|words', [
            'estate.price' => '3500000',
        ]);

        $this->assertStringContainsString('три миллиона', $words);
        $this->assertStringContainsString('рублей', $words);
        $this->assertStringContainsString('копеек', $words);
    }

    /** @test */
    public function test_words_on_money_key_includes_kopecks_for_fractional(): void
    {
        $words = $this->engine->resolve('finances.balance|words', [
            'finances.balance' => '123.45',
        ]);

        $this->assertStringContainsString('сто двадцать три', $words);
        $this->assertStringContainsString('сорок пять', $words);
        $this->assertStringContainsString('копеек', $words);
    }

    /** @test */
    public function test_words_on_plain_number_key_is_cardinal_without_roubles(): void
    {
        // estate.floor is NOT a money key — plain cardinal, no "рублей".
        $words = $this->engine->resolve('estate.floor|words', [
            'estate.floor' => '7',
        ]);

        $this->assertSame('семь', $words);
        $this->assertStringNotContainsString('рубл', $words);
    }

    /** @test */
    public function test_words_passes_through_non_numeric(): void
    {
        $this->assertSame('квартира', $this->engine->resolve('x|words', ['x' => 'квартира']));
    }

    // -------------------------------------------------------------------------
    // rouble filter
    // -------------------------------------------------------------------------

    /** @test */
    public function test_rouble_appends_declined_currency_word(): void
    {
        $this->assertSame('3 500 000 рублей', $this->engine->resolve('estate.price|rouble', [
            'estate.price' => '3500000',
        ]));
        $this->assertSame('1 рубль', $this->engine->resolve('deal.sum|rouble', [
            'deal.sum' => '1',
        ]));
        $this->assertSame('3 рубля', $this->engine->resolve('deal.sum|rouble', [
            'deal.sum' => '3',
        ]));
    }

    /** @test */
    public function test_money_is_alias_of_rouble(): void
    {
        $this->assertSame(
            $this->engine->resolve('estate.price|rouble', ['estate.price' => '500000']),
            $this->engine->resolve('estate.price|money', ['estate.price' => '500000']),
        );
    }

    // -------------------------------------------------------------------------
    // date filters
    // -------------------------------------------------------------------------

    /** @test */
    public function test_date_filter_formats_dmy(): void
    {
        $this->assertSame('15.06.2024', $this->engine->resolve('deal.date|date', [
            'deal.date' => '2024-06-15',
        ]));
    }

    /** @test */
    public function test_date_words_filter_formats_russian_genitive_month(): void
    {
        $out = $this->engine->resolve('deal.date|date_words', [
            'deal.date' => '2024-06-15',
        ]);

        $this->assertSame('15 июня 2024 г.', $out);
    }

    /** @test */
    public function test_date_filters_pass_through_non_date(): void
    {
        $this->assertSame('soon', $this->engine->resolve('x|date', ['x' => 'soon']));
        $this->assertSame('soon', $this->engine->resolve('x|date_words', ['x' => 'soon']));
    }

    // -------------------------------------------------------------------------
    // string filters
    // -------------------------------------------------------------------------

    /** @test */
    public function test_ucfirst_uppercases_first_letter_multibyte(): void
    {
        $this->assertSame('Привет', $this->engine->resolve('x|ucfirst', ['x' => 'привет']));
    }

    /** @test */
    public function test_upper_uppercases_whole_string_multibyte(): void
    {
        $this->assertSame('ПРИВЕТ', $this->engine->resolve('x|upper', ['x' => 'привет']));
    }

    // -------------------------------------------------------------------------
    // filter chains + unknown filter
    // -------------------------------------------------------------------------

    /** @test */
    public function test_filter_chain_applies_left_to_right(): void
    {
        // format then upper — format produces the spaced number, upper no-ops on
        // digits but proves the chain runs in order without error.
        $this->assertSame('3 500 000', $this->engine->resolve('estate.price|format|upper', [
            'estate.price' => '3500000',
        ]));
    }

    /** @test */
    public function test_words_then_ucfirst_chain(): void
    {
        $out = $this->engine->resolve('estate.floor|words|ucfirst', [
            'estate.floor' => '7',
        ]);

        $this->assertSame('Семь', $out);
    }

    /** @test */
    public function test_unknown_filter_passes_value_through(): void
    {
        $this->assertSame('Звезда', $this->engine->resolve('x|bogusfilter', [
            'x' => 'Звезда',
        ]));
    }

    /** @test */
    public function test_renders_full_kp_fragment_without_leaking_markup(): void
    {
        $html = $this->engine->renderHtml(
            'ЖК ${estate.complex_name}, кв. ${estate.number}, '
            .'${estate.area|format} м², ${estate.price|format} ₽ (${estate.price|words}), '
            .'скидка ${discount.percent|format}% → ${discount.price_discounted|format} ₽, '
            .'дата ${common.today|date}',
            [
                'estate.complex_name' => 'Звезда',
                'estate.number' => '42',
                'estate.area' => '65.4',
                'estate.price' => '3500000',
                'discount.percent' => '5',
                'discount.price_discounted' => '3325000',
                'common.today' => '2024-06-15',
            ],
        );

        $this->assertStringContainsString('ЖК Звезда', $html);
        $this->assertStringContainsString('кв. 42', $html);
        $this->assertStringContainsString('65,4 м²', $html);
        $this->assertStringContainsString('3 500 000 ₽', $html);
        $this->assertStringContainsString('три миллиона', $html);
        $this->assertStringContainsString('скидка 5%', $html);
        $this->assertStringContainsString('3 325 000 ₽', $html);
        $this->assertStringContainsString('15.06.2024', $html);
        $this->assertStringNotContainsString('${', $html);
        $this->assertStringNotContainsString('{{', $html);
    }
}
