<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Contracts\Models\LicensorEntity;
use Illuminate\Database\Seeder;

/**
 * LicensorEntitySeeder — seeds KZ and UZ licensor entities from old product data.
 * Idempotent: updateOrCreate by country_code.
 */
class LicensorEntitySeeder extends Seeder
{
    public function run(): void
    {
        $entities = [
            'kz' => [
                'entity' => [
                    'country_code' => 'kz',
                    'is_default' => true,
                    'legal_form' => 'ТОО',
                    'full_legal_form' => 'Товарищество с ограниченной ответственностью',
                    'gender_ending_oe' => 'ое',
                    'name' => 'Проптехсервис Казахстан',
                    'director_position' => 'Директора',
                    'director_short' => 'Амангельдиев А.К.',
                    'director_genitive' => 'Амангельдиева Адильжана Канатұлы',
                    'acts_basis' => 'Устава',
                    'tax_id_label' => 'БИН',
                    'tax_id' => '221040026030',
                    'address' => 'Казахстан, город Астана, район Сарыарка, улица Тарас Шевченко, здание 4/1, н.п. 17, почтовый индекс Z11A3X9',
                    'bank' => 'АО "Банк ЦентрКредит"',
                    'bank_code_label' => 'БИК',
                    'bank_code' => 'KCJBKZKX',
                    'account' => 'KZ 398562203126111204',
                    'phone' => null,
                    'email' => 'info@proptechservice.kz',
                    'website' => 'proptechservice.kz',
                    'training_login' => null,
                ],
                'accounts' => [
                    [
                        'currency' => 'KZT',
                        'bank' => 'АО "Банк ЦентрКредит"',
                        'bank_code_label' => 'БИК',
                        'bank_code' => 'KCJBKZKX',
                        'account' => 'KZ 398562203126111204',
                        'swift' => null,
                        'is_primary' => true,
                        'note' => 'Основной счёт KZT',
                    ],
                    [
                        'currency' => 'USD',
                        'bank' => 'АО "Банк ЦентрКредит"',
                        'bank_code_label' => 'SWIFT',
                        'bank_code' => 'KCJBKZKX',
                        'account' => 'KZ 398562203126111204',
                        'swift' => 'KCJBKZKX',
                        'is_primary' => true,
                        'note' => 'USD transfers via Банк ЦентрКредит',
                    ],
                ],
            ],

            'uz' => [
                'entity' => [
                    'country_code' => 'uz',
                    'is_default' => true,
                    'legal_form' => 'ООО',
                    'full_legal_form' => 'Общество с ограниченной ответственностью',
                    'gender_ending_oe' => 'ое',
                    'name' => 'Construction software solutions',
                    'director_position' => 'Директора',
                    'director_short' => 'Рогов И.А.',
                    'director_genitive' => 'Рогова Ильи Андреевича',
                    'acts_basis' => 'Устава',
                    'tax_id_label' => 'ИНН',
                    'tax_id' => '309572231',
                    'address' => 'Республика Узбекистан, г. Ташкент, Яшнабадский район, МСГ Хосиятли, ул. Жаркурган, 20/1А',
                    'bank' => 'Мирабадский филиал ЧАКБ "Ориент Финанс"',
                    'bank_code_label' => 'Код банка',
                    'bank_code' => '01167',
                    'account' => '20208000105530436001',
                    'phone' => '+998 91 787 67 79',
                    'email' => 'css@uzmacro.app',
                    'website' => 'uzmacro.app',
                    'training_login' => null,
                ],
                'accounts' => [
                    [
                        'currency' => 'UZS',
                        'bank' => 'Мирабадский филиал ЧАКБ "Ориент Финанс"',
                        'bank_code_label' => 'Код банка',
                        'bank_code' => '01167',
                        'account' => '20208000105530436001',
                        'swift' => null,
                        'is_primary' => true,
                        'note' => 'Основной счёт UZS',
                    ],
                    [
                        'currency' => 'USD',
                        'bank' => 'Мирабадский филиал ЧАКБ "Ориент Финанс"',
                        'bank_code_label' => 'SWIFT',
                        'bank_code' => '01167',
                        'account' => '20208000105530436001',
                        'swift' => 'ORINFRUM',
                        'is_primary' => true,
                        'note' => 'USD transfers via Ориент Финанс',
                    ],
                ],
            ],
        ];

        foreach ($entities as $data) {
            $entity = LicensorEntity::updateOrCreate(
                ['country_code' => $data['entity']['country_code']],
                $data['entity'],
            );

            foreach ($data['accounts'] as $accountData) {
                // Insert-missing: only create if no existing primary for this currency.
                $existing = $entity->bankAccounts()
                    ->where('currency', $accountData['currency'])
                    ->where('is_primary', true)
                    ->first();

                if ($existing === null) {
                    $entity->bankAccounts()->create($accountData);
                }
            }
        }
    }
}
