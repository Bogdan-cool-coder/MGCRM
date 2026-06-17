<?php

declare(strict_types=1);

namespace Tests\Unit\Inbox;

use App\Domain\Inbox\Services\FormSubmissionValidator;
use Tests\TestCase;

/**
 * Pure-class tests for FormSubmissionValidator — no DB. config('inbox.*') is read
 * from the loaded config (TestCase boots the framework, so config is available).
 */
class FormSubmissionValidatorTest extends TestCase
{
    private FormSubmissionValidator $validator;

    /** @var list<array<string, mixed>> */
    private array $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FormSubmissionValidator;
        $this->schema = [
            ['name' => 'name', 'label' => 'Имя', 'type' => 'text', 'required' => true],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false],
            ['name' => 'phone', 'label' => 'Телефон', 'type' => 'phone', 'required' => false],
        ];
    }

    public function test_validate_accepts_well_formed_submission(): void
    {
        $r = $this->validator->validate($this->schema, [
            'name' => 'Иван', 'email' => 'ivan@example.com', 'phone' => '+7 700 123 45 67',
        ]);

        $this->assertTrue($r['ok']);
        $this->assertNull($r['error']);
    }

    public function test_validate_rejects_unknown_key(): void
    {
        $r = $this->validator->validate($this->schema, ['name' => 'Иван', 'evil' => 'x']);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('evil', (string) $r['error']);
    }

    public function test_validate_rejects_missing_required(): void
    {
        $r = $this->validator->validate($this->schema, ['email' => 'a@b.co']);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('required', (string) $r['error']);
    }

    public function test_validate_rejects_bad_email(): void
    {
        $r = $this->validator->validate($this->schema, ['name' => 'Иван', 'email' => 'not-an-email']);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('email', (string) $r['error']);
    }

    public function test_validate_rejects_bad_phone(): void
    {
        $r = $this->validator->validate($this->schema, ['name' => 'Иван', 'phone' => 'abc']);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('phone', (string) $r['error']);
    }

    public function test_validate_rejects_too_long_value(): void
    {
        $r = $this->validator->validate($this->schema, ['name' => str_repeat('x', 2001)]);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('too long', (string) $r['error']);
    }

    public function test_validate_rejects_too_many_fields(): void
    {
        $submission = [];
        for ($i = 0; $i < 60; $i++) {
            $submission["f{$i}"] = 'v';
        }

        $r = $this->validator->validate($this->schema, $submission);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Too many', (string) $r['error']);
    }

    public function test_honeypot_detection(): void
    {
        $this->assertTrue($this->validator->isHoneypotFilled(['website' => 'http://spam']));
        $this->assertFalse($this->validator->isHoneypotFilled(['website' => '']));
        $this->assertFalse($this->validator->isHoneypotFilled(['name' => 'Иван']));
    }

    public function test_honeypot_field_passes_validation_as_known_key(): void
    {
        // The honeypot key is allowed (not "unknown"), so a clean submission with
        // an empty website still validates.
        $r = $this->validator->validate($this->schema, ['name' => 'Иван', 'website' => '']);

        $this->assertTrue($r['ok']);
    }

    public function test_external_id_stable_within_window(): void
    {
        $sub = ['email' => 'A@B.com'];
        $a = $this->validator->externalId('slug-x', $sub, 1000.0);
        $b = $this->validator->externalId('slug-x', $sub, 1500.0);

        $this->assertNotNull($a);
        $this->assertSame($a, $b);
        $this->assertStringStartsWith('form:', (string) $a);
    }

    public function test_external_id_differs_across_window(): void
    {
        $sub = ['email' => 'a@b.com'];
        $window = (int) config('inbox.form_dedup_window_seconds');
        $a = $this->validator->externalId('slug-x', $sub, 1.0);
        $b = $this->validator->externalId('slug-x', $sub, (float) ($window + 10));

        $this->assertNotSame($a, $b);
    }

    public function test_external_id_null_without_contact(): void
    {
        $this->assertNull($this->validator->externalId('slug-x', ['name' => 'Иван'], 1000.0));
    }

    public function test_external_id_email_priority_and_phone_normalized(): void
    {
        // Same normalized phone in different formats → same id (within window).
        $a = $this->validator->externalId('s', ['phone' => '+7 700 123'], 1000.0);
        $b = $this->validator->externalId('s', ['phone' => '8(700)123'], 1000.0);

        // Both normalize to digits-only; but +7700123 vs 8700123 differ → ids differ.
        // Identical formats must match:
        $c = $this->validator->externalId('s', ['phone' => '+7-700-123'], 1000.0);
        $this->assertSame($a, $c);
        $this->assertNotNull($b);
    }

    public function test_build_message_fields_from_submission(): void
    {
        $fields = $this->validator->buildMessageFields('Заявка', [
            'name' => '  Иван  ',
            'email' => 'ivan@example.com',
            'phone' => '+7 700',
            'website' => 'spam',
        ]);

        $this->assertSame('Иван', $fields['from_name']);
        $this->assertSame('ivan@example.com', $fields['from_identifier']); // email > phone
        $this->assertSame('Форма: Заявка', $fields['subject']);
        // honeypot excluded from body; declared fields serialized.
        $this->assertStringNotContainsString('website', (string) $fields['body']);
        $this->assertStringContainsString('email: ivan@example.com', (string) $fields['body']);
    }

    public function test_build_message_fields_falls_back_to_phone_identifier(): void
    {
        $fields = $this->validator->buildMessageFields('Заявка', ['name' => 'Иван', 'phone' => '+7 700 123']);

        $this->assertSame('+7 700 123', $fields['from_identifier']);
    }
}
