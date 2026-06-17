<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Services;

/**
 * FormSubmissionValidator — pure class (no DB, fully unit-testable). Mirrors the
 * honeypot / strict-validation / stable-external_id / message-build helpers from
 * examples/contracts inbox.py. All limits/whitelists come from config('inbox.*').
 *
 * The methods are deliberately stateless; FormService / PublicFormController
 * call them around the (DB-touching) routing step.
 */
class FormSubmissionValidator
{
    /** Simple anti-garbage regexes (not RFC-strict). */
    private const EMAIL_RE = '/^[^@\s]+@[^@\s]+\.[^@\s]+$/';

    private const PHONE_RE = '/^[+\d][\d\s()\-]{4,30}$/';

    /**
     * True when the honeypot field is filled — a real user never sees/fills it,
     * so a non-empty value means a bot. Caller responds with a silent OK.
     *
     * @param  array<string, mixed>  $submission
     */
    public function isHoneypotFilled(array $submission): bool
    {
        $field = (string) config('inbox.honeypot_field', 'website');
        $value = $submission[$field] ?? null;

        return is_string($value) && trim($value) !== '';
    }

    /**
     * Strict validation of a submission against the declared form.fields.
     *
     * Rules (mirror inbox.py validate_form_submission):
     *   - body must be an object with ≤ max_submission_fields keys;
     *   - only declared keys + the honeypot are allowed (unknown key → error,
     *     so garbage never reaches Company / raw_payload);
     *   - required fields must be present and non-blank;
     *   - type email/phone validated by regex; values capped at max_field_value_len.
     *
     * @param  list<array<string, mixed>>  $fieldsSchema
     * @param  array<string, mixed>  $submission
     * @return array{ok: bool, error: string|null}
     */
    public function validate(array $fieldsSchema, array $submission): array
    {
        $honeypot = (string) config('inbox.honeypot_field', 'website');
        $maxFields = (int) config('inbox.max_submission_fields', 50);
        $maxLen = (int) config('inbox.max_field_value_len', 2000);

        if (count($submission) > $maxFields) {
            return $this->error('Too many fields in the form.');
        }

        // Index declared fields by name.
        $declared = [];
        foreach ($fieldsSchema as $field) {
            if (is_array($field) && ! empty($field['name'])) {
                $declared[(string) $field['name']] = $field;
            }
        }

        // Reject unknown keys (honeypot excepted).
        foreach (array_keys($submission) as $key) {
            if ($key === $honeypot) {
                continue;
            }
            if (! array_key_exists((string) $key, $declared)) {
                return $this->error("Unknown field '{$key}'.");
            }
        }

        foreach ($declared as $name => $field) {
            $value = $submission[$name] ?? null;
            $isEmpty = $value === null || (is_string($value) && trim($value) === '');
            $label = (string) ($field['label'] ?? $name);

            if (! empty($field['required']) && $isEmpty) {
                return $this->error("Field '{$label}' is required.");
            }
            if ($isEmpty) {
                continue;
            }
            if (is_string($value) && mb_strlen($value) > $maxLen) {
                return $this->error("Field '{$label}' is too long.");
            }

            $type = $field['type'] ?? null;
            if ($type === 'email' && is_string($value) && preg_match(self::EMAIL_RE, trim($value)) !== 1) {
                return $this->error("Field '{$label}': invalid email.");
            }
            if ($type === 'phone' && is_string($value) && preg_match(self::PHONE_RE, trim($value)) !== 1) {
                return $this->error("Field '{$label}': invalid phone.");
            }
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Stable external_id for a submission (double-click / refresh dedup).
     *
     * Key = sha256(slug | normalized email|phone | time-window)[:32], prefixed
     * with "form:". Within form_dedup_window_seconds the same contact + slug
     * yields the same id → the routing dedup does not create a second Deal.
     * Returns null when there is no email/phone (nothing stable to key on).
     *
     * @param  array<string, mixed>  $submission
     */
    public function externalId(string $slug, array $submission, float $now): ?string
    {
        $email = isset($submission['email']) && is_string($submission['email']) ? $submission['email'] : null;
        $phone = isset($submission['phone']) && is_string($submission['phone']) ? $submission['phone'] : null;

        $value = $this->dedupValue($email, $phone);
        if ($value === null) {
            return null;
        }

        $windowSize = (int) config('inbox.form_dedup_window_seconds', 21600);
        $window = (int) floor($now / $windowSize);
        $digest = substr(hash('sha256', "{$slug}|{$value}|{$window}"), 0, 32);

        return "form:{$digest}";
    }

    /**
     * Extract the standard message fields (from_name / from_identifier / subject
     * / body) from a raw submission. The honeypot field is excluded from body.
     *
     * @param  array<string, mixed>  $submission
     * @return array{from_name: string|null, from_identifier: string|null, subject: string, body: string|null}
     */
    public function buildMessageFields(string $formName, array $submission): array
    {
        $honeypot = (string) config('inbox.honeypot_field', 'website');

        $name = $submission['name'] ?? $submission['full_name'] ?? null;
        $email = isset($submission['email']) && is_string($submission['email']) ? $submission['email'] : null;
        $phone = isset($submission['phone']) && is_string($submission['phone']) ? $submission['phone'] : null;

        // from_identifier: email > phone > null.
        $identifier = null;
        if ($email !== null && str_contains($email, '@')) {
            $identifier = $email;
        } elseif ($phone !== null && trim($phone) !== '') {
            $identifier = trim($phone);
        }

        $bodyLines = [];
        foreach ($submission as $key => $value) {
            if ($key === $honeypot || $value === null || $value === '') {
                continue;
            }
            $bodyLines[] = $key.': '.(is_scalar($value) ? (string) $value : json_encode($value));
        }

        return [
            'from_name' => $name !== null ? mb_substr(trim((string) $name), 0, 255) : null,
            'from_identifier' => $identifier !== null ? mb_substr($identifier, 0, 255) : null,
            'subject' => mb_substr("Форма: {$formName}", 0, 255),
            'body' => $bodyLines !== [] ? implode("\n", $bodyLines) : null,
        ];
    }

    /**
     * Email (priority) or normalized phone — the dedup value, or null. Shared by
     * externalId() so its key matches CompanyService::findForDedup semantics.
     */
    private function dedupValue(?string $email, ?string $phone): ?string
    {
        if ($email !== null && trim($email) !== '') {
            return mb_strtolower(trim($email));
        }
        $digits = $phone !== null ? (preg_replace('/[^0-9]/', '', $phone) ?? '') : '';

        return $digits !== '' ? $digits : null;
    }

    /**
     * @return array{ok: false, error: string}
     */
    private function error(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}
