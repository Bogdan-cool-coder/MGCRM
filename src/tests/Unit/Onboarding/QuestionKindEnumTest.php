<?php

declare(strict_types=1);

namespace Tests\Unit\Onboarding;

use App\Domain\Onboarding\Enums\QuestionKind;
use PHPUnit\Framework\TestCase;
use ValueError;

class QuestionKindEnumTest extends TestCase
{
    public function test_single_choice_and_multiple_choice_exist(): void
    {
        $cases = QuestionKind::cases();
        $values = array_map(fn (QuestionKind $k) => $k->value, $cases);

        $this->assertContains('single_choice', $values);
        $this->assertContains('multiple_choice', $values);
        $this->assertCount(2, $cases);
    }

    public function test_kind_from_string(): void
    {
        $this->assertSame(QuestionKind::SingleChoice, QuestionKind::from('single_choice'));
        $this->assertSame(QuestionKind::MultipleChoice, QuestionKind::from('multiple_choice'));
    }

    public function test_invalid_kind_throws(): void
    {
        $this->expectException(ValueError::class);

        QuestionKind::from('checkbox');
    }
}
