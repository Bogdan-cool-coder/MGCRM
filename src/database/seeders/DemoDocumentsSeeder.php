<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentItem;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Seeder;

/**
 * DemoDocumentsSeeder — idempotent seed of demo documents for S2.2 smoke-test.
 *
 * Creates 3 demo documents:
 *   1. Draft — MacroCRM / KZ / Almaty — author=admin
 *   2. Submitted (with 1 revision) — MacroSales / UZ / Tashkent — author=manager
 *   3. Draft with 2 items — MacroERP / KZ / Astana — author=admin
 *
 * Idempotent: skipped if documents already exist (by title).
 */
class DemoDocumentsSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@mgcrm.test')->first();

        if ($admin === null) {
            $this->command->warn('DemoDocumentsSeeder: admin@mgcrm.test not found, skipping.');

            return;
        }

        $manager = User::query()->where('role', 'manager')->first() ?? $admin;

        $this->seedDraft($admin);
        $this->seedSubmitted($manager);
        $this->seedDraftWithItems($admin);
    }

    private function seedDraft(User $author): void
    {
        if (Document::query()->where('title', '[DEMO] MacroCRM KZ Алматы')->exists()) {
            return;
        }

        Document::create([
            'kind' => 'contract',
            'title' => '[DEMO] MacroCRM KZ Алматы',
            'product_code' => 'macrocrm',
            'country_code' => 'kz',
            'city' => 'Алматы',
            'status' => ContractStatus::Draft->value,
            'author_user_id' => $author->id,
            'currency' => 'KZT',
            'context' => [
                'sublicensee' => ['name' => 'ТОО "ДемоКомпания"', 'bin' => '123456789012'],
                'license' => ['product' => 'MacroCRM', 'seats' => 10],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => ['manager_name' => 'Иванов Иван'],
            ],
            'subtotal' => 0,
            'discount_pct' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'extra_fields' => [],
        ]);
    }

    private function seedSubmitted(User $author): void
    {
        if (Document::query()->where('title', '[DEMO] MacroSales UZ Ташкент')->exists()) {
            return;
        }

        // NOTE: keep as Draft — a submitted doc must have docx_path set (guard in
        // ApprovalService::submit). Creating a fake submitted row with docx_path=null
        // poisons smoke tests and manual QA (generate→submit flow breaks on this doc).
        // Demo reviewers can generate + submit this doc manually to see the full flow.
        Document::create([
            'kind' => 'contract',
            'title' => '[DEMO] MacroSales UZ Ташкент',
            'product_code' => 'macrosales',
            'country_code' => 'uz',
            'city' => 'Ташкент',
            'status' => ContractStatus::Draft->value,
            'author_user_id' => $author->id,
            'currency' => 'UZS',
            'context' => [
                'sublicensee' => ['name' => 'ООО "ДемоУз"'],
                'license' => ['product' => 'MacroSales', 'seats' => 5],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => [],
            ],
            'subtotal' => 150000000, // 150 000 000 UZS tiyin
            'discount_pct' => 10.00,
            'discount_amount' => 15000000,
            'total' => 135000000,
            'extra_fields' => [],
        ]);
    }

    private function seedDraftWithItems(User $author): void
    {
        if (Document::query()->where('title', '[DEMO] MacroERP KZ Астана')->exists()) {
            return;
        }

        $doc = Document::create([
            'kind' => 'contract',
            'title' => '[DEMO] MacroERP KZ Астана',
            'product_code' => 'macroerp',
            'country_code' => 'kz',
            'city' => 'Астана',
            'status' => ContractStatus::Draft->value,
            'author_user_id' => $author->id,
            'currency' => 'KZT',
            'context' => [
                'sublicensee' => ['name' => 'АО "ДемоЕРП"'],
                'license' => [],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => [],
            ],
            'subtotal' => 0,
            'discount_pct' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'extra_fields' => [],
        ]);

        // Try to attach a product line if catalog products exist.
        $product = Product::query()->where('is_active', true)->first();
        if ($product !== null) {
            $unitPrice = 500000; // 5000 KZT in tiyn
            $qty = 2.0;
            $lineTotal = (int) round($qty * $unitPrice);

            DocumentItem::create([
                'document_id' => $doc->id,
                'product_id' => $product->id,
                'plan_id' => null,
                'name_snapshot' => $product->name,
                'currency' => 'KZT',
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'sort_order' => 0,
            ]);

            $subtotal = $lineTotal;
            $doc->update([
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'total' => $subtotal,
            ]);
        }
    }
}
