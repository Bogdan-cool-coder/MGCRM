<?php

declare(strict_types=1);

namespace Tests\Unit\Onboarding;

use App\Domain\Onboarding\Enums\LessonKind;
use PHPUnit\Framework\TestCase;
use ValueError;

class LessonKindEnumTest extends TestCase
{
    public function test_all_four_kinds_exist(): void
    {
        $cases = LessonKind::cases();
        $values = array_map(fn (LessonKind $k) => $k->value, $cases);

        $this->assertContains('text', $values);
        $this->assertContains('video', $values);
        $this->assertContains('pdf', $values);
        $this->assertContains('quiz', $values);
        $this->assertCount(4, $cases);
    }

    public function test_kind_from_string(): void
    {
        $this->assertSame(LessonKind::Text, LessonKind::from('text'));
        $this->assertSame(LessonKind::Video, LessonKind::from('video'));
        $this->assertSame(LessonKind::Pdf, LessonKind::from('pdf'));
        $this->assertSame(LessonKind::Quiz, LessonKind::from('quiz'));
    }

    public function test_invalid_kind_throws(): void
    {
        $this->expectException(ValueError::class);

        LessonKind::from('unknown');
    }
}
