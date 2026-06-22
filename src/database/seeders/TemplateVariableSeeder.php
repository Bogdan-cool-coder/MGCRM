<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Contracts\Models\TemplateVariable;
use Illuminate\Database\Seeder;

/**
 * TemplateVariableSeeder — seeds the base catalogue of custom template variables.
 * Idempotent: updateOrCreate by key.
 */
class TemplateVariableSeeder extends Seeder
{
    public function run(): void
    {
        $variables = [
            [
                'key' => 'training_hours',
                'label' => 'Количество часов обучения',
                'help_text' => 'Общее количество чел/часов обучения по договору',
                'var_type' => 'number',
                'options' => [],
                'default_value' => '15',
                'required' => false,
                'group' => 'Обучение',
                'sort_order' => 10,
                'product_codes' => [],
                'country_codes' => [],
                'is_active' => true,
            ],
            [
                'key' => 'payment_date',
                'label' => 'Дата оплаты',
                'help_text' => 'Предполагаемая дата первого платежа (ДД.ММ.ГГГГ)',
                'var_type' => 'date',
                'options' => [],
                'default_value' => null,
                'required' => false,
                'group' => 'Платёж',
                'sort_order' => 20,
                'product_codes' => [],
                'country_codes' => [],
                'is_active' => true,
            ],
            [
                'key' => 'start_date',
                'label' => 'Дата начала внедрения',
                'help_text' => 'Дата старта работ по договору (ДД.ММ.ГГГГ)',
                'var_type' => 'date',
                'options' => [],
                'default_value' => null,
                'required' => false,
                'group' => 'Сроки',
                'sort_order' => 30,
                'product_codes' => [],
                'country_codes' => [],
                'is_active' => true,
            ],
            [
                'key' => 'city',
                'label' => 'Город нахождения Сублицензиата',
                'help_text' => 'Город, в котором расположена компания клиента',
                'var_type' => 'text',
                'options' => [],
                'default_value' => null,
                'required' => false,
                'group' => 'Реквизиты',
                'sort_order' => 40,
                'product_codes' => [],
                'country_codes' => [],
                'is_active' => true,
            ],
            [
                'key' => 'custom_clause',
                'label' => 'Дополнительное условие',
                'help_text' => 'Произвольный текст дополнительного условия для включения в договор',
                'var_type' => 'textarea',
                'options' => [],
                'default_value' => null,
                'required' => false,
                'group' => 'Прочее',
                'sort_order' => 100,
                'product_codes' => [],
                'country_codes' => [],
                'is_active' => true,
            ],

            // ---- Расторжение (ДС о расторжении) ----

            [
                'key' => 'original_contract_number',
                'label' => 'Номер расторгаемого договора',
                'help_text' => 'Номер исходного договора, который расторгается (заполняется автоматически)',
                'var_type' => 'text',
                'options' => [],
                'default_value' => null,
                'required' => true,
                'group' => 'Расторжение',
                'sort_order' => 110,
                'product_codes' => [],
                'country_codes' => [],
                'is_active' => true,
            ],
            [
                'key' => 'original_contract_date',
                'label' => 'Дата расторгаемого договора',
                'help_text' => 'Дата подписания исходного договора (ДД.ММ.ГГГГ, заполняется автоматически)',
                'var_type' => 'date',
                'options' => [],
                'default_value' => null,
                'required' => true,
                'group' => 'Расторжение',
                'sort_order' => 120,
                'product_codes' => [],
                'country_codes' => [],
                'is_active' => true,
            ],
            [
                'key' => 'termination_date',
                'label' => 'Дата расторжения',
                'help_text' => 'Дата вступления в силу расторжения договора (ДД.ММ.ГГГГ)',
                'var_type' => 'date',
                'options' => [],
                'default_value' => null,
                'required' => true,
                'group' => 'Расторжение',
                'sort_order' => 130,
                'product_codes' => [],
                'country_codes' => [],
                'is_active' => true,
            ],
            [
                'key' => 'termination_reason',
                'label' => 'Основание расторжения',
                'help_text' => 'Причина расторжения договора (свободный текст)',
                'var_type' => 'textarea',
                'options' => [],
                'default_value' => null,
                'required' => true,
                'group' => 'Расторжение',
                'sort_order' => 140,
                'product_codes' => [],
                'country_codes' => [],
                'is_active' => true,
            ],
            [
                'key' => 'termination_signatory',
                'label' => 'Подписант ДС',
                'help_text' => 'ФИО подписанта дополнительного соглашения (необязательно)',
                'var_type' => 'text',
                'options' => [],
                'default_value' => null,
                'required' => false,
                'group' => 'Расторжение',
                'sort_order' => 150,
                'product_codes' => [],
                'country_codes' => [],
                'is_active' => true,
            ],
        ];

        foreach ($variables as $data) {
            TemplateVariable::updateOrCreate(
                ['key' => $data['key']],
                $data,
            );
        }
    }
}
