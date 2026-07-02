<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Domain\Migration\Support\AmoEnumLabelResolver;
use Tests\TestCase;

/**
 * Pure-function tests for AmoEnumLabelResolver — no DB, no AMO. Guards the core
 * invariant: an AMO custom_field_value_changed block never renders as JSON.
 */
class AmoEnumLabelResolverTest extends TestCase
{
    private AmoEnumLabelResolver $labels;

    protected function setUp(): void
    {
        parent::setUp();

        $this->labels = new AmoEnumLabelResolver;
    }

    public function test_unwraps_custom_field_value_object_to_text(): void
    {
        $block = [['custom_field_value' => [
            'field_id' => 711078, 'field_type' => 4, 'enum_id' => 1188488, 'text' => 'г. Москва',
        ]]];

        $this->assertSame('г. Москва', $this->labels->value($block));
        $this->assertSame(711078, $this->labels->fieldId($block));
    }

    public function test_unwraps_custom_field_value_list_and_joins_multi_values(): void
    {
        $block = [['custom_field_value' => [
            ['field_id' => 709546, 'enum_id' => 1138196, 'text' => 'Застройщик'],
            ['field_id' => 709546, 'enum_id' => 1138208, 'text' => 'Производители'],
        ]]];

        $this->assertSame('Застройщик, Производители', $this->labels->value($block));
    }

    public function test_reads_already_flat_text_shape(): void
    {
        $this->assertSame('Привет', $this->labels->value([['text' => 'Привет']]));
        $this->assertSame('2000', $this->labels->value([['value' => 2000]]));
    }

    public function test_returns_null_for_unreadable_or_empty(): void
    {
        $this->assertNull($this->labels->value(null));
        $this->assertNull($this->labels->value([]));
        $this->assertNull($this->labels->value([['custom_field_value' => ['field_id' => 5, 'enum_id' => 9]]]));
    }

    public function test_field_name_uses_map_then_generic_fallback(): void
    {
        $this->assertSame('Регион', $this->labels->fieldName(711078));
        $this->assertSame('Поле', $this->labels->fieldName(999999));
        $this->assertSame('Поле', $this->labels->fieldName(null));
    }

    public function test_describe_change_produces_arrow_line(): void
    {
        $before = [['custom_field_value' => ['field_id' => 711078, 'text' => 'г. Санкт-Петербург']]];
        $after = [['custom_field_value' => ['field_id' => 711078, 'text' => 'г. Москва']]];

        $line = $this->labels->describeChange(711078, $before, $after);

        $this->assertSame('«Регион»: г. Санкт-Петербург → г. Москва', $line);
    }

    public function test_describe_change_falls_back_to_placeholder_never_json(): void
    {
        // Unreadable both sides → human placeholder, no JSON.
        $line = $this->labels->describeChange(711078, null, [['custom_field_value' => ['field_id' => 711078, 'enum_id' => 9]]]);

        $this->assertSame('«Регион»: значение изменено', $line);
        $this->assertStringNotContainsString('{', $line);
    }

    public function test_bool_value_renders_yes_no(): void
    {
        $this->assertSame('Да', $this->labels->value([['custom_field_value' => ['field_id' => 709732, 'value' => true]]]));
    }
}
