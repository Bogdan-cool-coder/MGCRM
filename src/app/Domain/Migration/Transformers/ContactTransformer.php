<?php

declare(strict_types=1);

namespace App\Domain\Migration\Transformers;

use App\Domain\Crm\Enums\ChannelType;
use App\Domain\Migration\Support\AmoFieldReader;
use App\Domain\Migration\Support\AmoFields;
use App\Domain\Migration\Support\AmoReferenceResolver;

/**
 * ContactTransformer — pure AMO contact → MGCRM Contact attrs + ContactChannel
 * rows. Temporary migration bounded-context (dropped at M12).
 *
 * AMO stores phones / emails as multi-value custom fields whose well-known
 * field_code is PHONE / EMAIL; each value carries an enum subtype (WORK / MOB /
 * PERSONAL …) in its enum_code. We fan those out into contact_channels and
 * denormalise the FIRST phone / email back onto the contact row (the columns the
 * list/dedup queries use).
 */
final class ContactTransformer
{
    public function __construct(
        private readonly AmoReferenceResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $amoContact  Raw AMO contact.
     * @return array{
     *     amo_id: int,
     *     contact: array<string, mixed>,
     *     channels: list<array<string, mixed>>,
     *     created_by_amo_id: ?int
     * }
     */
    public function transform(array $amoContact): array
    {
        $fields = AmoFieldReader::for($amoContact);

        $name = trim((string) ($amoContact['name'] ?? ''));
        if ($name === '') {
            $first = trim((string) ($amoContact['first_name'] ?? ''));
            $last = trim((string) ($amoContact['last_name'] ?? ''));
            $name = trim($first.' '.$last);
        }
        if ($name === '') {
            $name = 'Контакт (импорт)';
        }

        $phones = $this->channelValues($amoContact, 'PHONE');
        $emails = $this->channelValues($amoContact, 'EMAIL');

        $channels = [];
        foreach ($phones as $row) {
            $channels[] = [
                'channel_type' => ChannelType::Phone->value,
                'value' => $row['value'],
                'label' => $row['label'],
                'is_primary_for_channel' => $row['is_first'],
            ];
        }
        foreach ($emails as $row) {
            $channels[] = [
                'channel_type' => ChannelType::Email->value,
                'value' => $row['value'],
                'label' => $row['label'],
                'is_primary_for_channel' => $row['is_first'],
            ];
        }

        $channelEnum = $fields->enumId(AmoFields::COMPANY_CHANNEL);

        $contact = [
            'full_name' => $name,
            'phone' => $phones[0]['value'] ?? null,
            'email' => $emails[0]['value'] ?? null,
            'acquisition_channel_id' => $this->resolver->channelIdForEnum($channelEnum),
        ];

        return [
            'amo_id' => (int) ($amoContact['id'] ?? 0),
            'contact' => $contact,
            'channels' => $channels,
            'created_by_amo_id' => isset($amoContact['created_by']) ? (int) $amoContact['created_by'] : null,
        ];
    }

    /**
     * Extract the values of a multi-value contact field by its AMO field_code
     * (PHONE / EMAIL), each with its enum_code subtype as the channel label.
     *
     * @param  array<string, mixed>  $amoContact
     * @return list<array{value: string, label: ?string, is_first: bool}>
     */
    private function channelValues(array $amoContact, string $fieldCode): array
    {
        $out = [];
        $first = true;

        foreach ($amoContact['custom_fields_values'] ?? [] as $field) {
            if (! is_array($field) || ($field['field_code'] ?? null) !== $fieldCode) {
                continue;
            }

            foreach ($field['values'] ?? [] as $value) {
                $raw = trim((string) ($value['value'] ?? ''));

                if ($raw === '') {
                    continue;
                }

                $label = isset($value['enum_code']) ? (string) $value['enum_code'] : null;

                $out[] = ['value' => $raw, 'label' => $label, 'is_first' => $first];
                $first = false;
            }
        }

        return $out;
    }
}
