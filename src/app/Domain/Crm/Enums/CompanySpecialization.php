<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

enum CompanySpecialization: string
{
    case RealEstateAgency = 'real_estate_agency';
    case Developer = 'developer';
    case Builder = 'builder';
    case Contractor = 'contractor';
    case Supplier = 'supplier';
    case Partner = 'partner';

    public function label(): string
    {
        return match ($this) {
            self::RealEstateAgency => 'Агентство недвижимости',
            self::Developer => 'Девелопер',
            self::Builder => 'Застройщик',
            self::Contractor => 'Подрядчик',
            self::Supplier => 'Поставщик',
            self::Partner => 'Партнёр',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
