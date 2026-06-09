<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds the system HTML commercial-proposal (КП) templates. Idempotent upsert
 * keyed by name->ru + is_system=true (same pattern as ReportSeeder /
 * WidgetSeeder). System templates are owned by the Vizion system company.
 */
class DocumentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('name', 'Vizion')->where('is_system', true)->firstOrFail();

        $templates = [
            [
                'name' => ['ru' => 'Коммерческое предложение', 'en' => 'Commercial Proposal'],
                'description' => [
                    'ru' => 'Брендируемое КП по объекту недвижимости (PDF)',
                    'en' => 'Branded commercial proposal for a property unit (PDF)',
                ],
                'type' => 'html',
                'is_system' => true,
                'is_published' => true,
                'sort_order' => 10,
                'company_id' => $company->id,
                'user_id' => null,
                'config' => [
                    // Brandable HTML body with canonical ${group.field|filter}
                    // placeholders. The estate.* / deal.* keys come from
                    // DocumentObjectDataService; discount.* and common.today are
                    // injected at render time (DocumentDataAssembler); brand_header
                    // / brand_footer / req_* come from the company branding profile.
                    // Filters: format (3 500 000), words (три миллиона …), date /
                    // date_words. Missing keys collapse to empty (never leak raw
                    // markup) — see DocumentFieldEngine.
                    'html' => implode("\n", [
                        '<div class="kp">',
                        '  <header class="kp-header">',
                        '    <img class="kp-logo" src="${branding.logo_url}" alt="logo">',
                        '    <div class="kp-brand">',
                        '      <h1 class="kp-title">Коммерческое предложение</h1>',
                        '      <p class="kp-subtitle">${brand_header}</p>',
                        '    </div>',
                        '  </header>',
                        '  <p class="kp-date">Дата: ${common.today|date_words}</p>',
                        '  <section class="kp-object">',
                        '    <table class="kp-fields">',
                        '      <tr><td>ЖК</td><td>${estate.complex_name}</td></tr>',
                        '      <tr><td>Дом</td><td>${estate.house_name}</td></tr>',
                        '      <tr><td>Адрес</td><td>${estate.address}</td></tr>',
                        '      <tr><td>Номер помещения</td><td>${estate.number}</td></tr>',
                        '      <tr><td>Этаж</td><td>${estate.floor}</td></tr>',
                        '      <tr><td>Комнат</td><td>${estate.rooms}</td></tr>',
                        '      <tr><td>Отделка</td><td>${estate.restoration_name}</td></tr>',
                        '      <tr><td>Площадь</td><td>${estate.area|format} м²</td></tr>',
                        '      <tr><td>Цена за м²</td><td>${estate.price_m2|format} ₽</td></tr>',
                        '      <tr class="kp-price"><td>Цена</td><td>${estate.price|format} ₽<br><span class="kp-words">(${estate.price|words})</span></td></tr>',
                        '    </table>',
                        '  </section>',
                        '  <section class="kp-discount" data-discount="${discount.percent}">',
                        '    <h2 class="kp-discount-title">Специальное предложение</h2>',
                        '    <table class="kp-fields">',
                        '      <tr><td>Акция</td><td>${discount.label}</td></tr>',
                        '      <tr><td>Скидка</td><td>${discount.percent|format}%</td></tr>',
                        '      <tr><td>Сумма скидки</td><td>${discount.amount|format} ₽</td></tr>',
                        '      <tr class="kp-total"><td>Цена со скидкой</td><td>${discount.price_discounted|format} ₽<br><span class="kp-words">(${discount.price_discounted|words})</span></td></tr>',
                        '    </table>',
                        '  </section>',
                        '  <footer class="kp-footer">${brand_footer}</footer>',
                        '</div>',
                    ]),
                    'css' => implode("\n", [
                        '.kp-header { display: flex; align-items: center; gap: 16px; border-bottom: 2px solid var(--brand-primary, #ddd); padding-bottom: 12px; }',
                        '.kp-logo { max-height: 64px; }',
                        '.kp-title { font-size: 22px; margin: 0; color: var(--brand-primary, #111); }',
                        '.kp-subtitle { margin: 4px 0 0; color: #666; font-size: 13px; }',
                        '.kp-date { color: #666; font-size: 13px; margin-top: 12px; }',
                        '.kp-fields { width: 100%; border-collapse: collapse; margin-top: 16px; }',
                        '.kp-fields td { padding: 8px 12px; border-bottom: 1px solid #eee; }',
                        '.kp-fields td:first-child { color: #666; width: 40%; }',
                        '.kp-price td { font-weight: 600; }',
                        '.kp-words { color: #888; font-size: 12px; font-style: italic; }',
                        '.kp-discount { margin-top: 24px; }',
                        '.kp-discount-title { font-size: 16px; color: var(--brand-accent, #b45309); }',
                        '.kp-discount[data-discount=""] { display: none; }',
                        '.kp-discount[data-discount="0"] { display: none; }',
                        '.kp-total td { font-weight: 700; border-top: 2px solid var(--brand-primary, #ddd); }',
                        '.kp-footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid #eee; color: #666; font-size: 12px; }',
                    ]),
                    'fields' => [
                        'estate.complex_name',
                        'estate.house_name',
                        'estate.address',
                        'estate.number',
                        'estate.floor',
                        'estate.rooms',
                        'estate.restoration_name',
                        'estate.area',
                        'estate.price',
                        'estate.price_m2',
                    ],
                ],
            ],
        ];

        $createdCount = 0;
        $updatedCount = 0;

        foreach ($templates as $templateData) {
            $nameRu = $templateData['name']['ru'];

            $template = DocumentTemplate::where('is_system', true)
                ->where('name->ru', $nameRu)
                ->first();

            if (! $template) {
                DocumentTemplate::create($templateData);
                $createdCount++;
            } else {
                $template->update($templateData);
                $updatedCount++;
            }
        }

        $this->command->info("Document templates: {$createdCount} created, {$updatedCount} updated.");
    }
}
