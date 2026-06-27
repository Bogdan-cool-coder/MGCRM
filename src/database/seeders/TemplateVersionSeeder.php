<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Contracts\Enums\AiCheckStatus;
use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Models\TemplateVersion;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * TemplateVersionSeeder — seeds a minimal valid .docx TemplateVersion for every
 * docx-kind template (master_skeleton, termination_agreement) so generation works
 * without a manual upload.
 *
 * Idempotent: if a template already has current_version_id set, it is skipped.
 * Safe to re-run.
 *
 * DURABILITY: The seeder first tries to copy a committed binary asset from
 * resources/templates/<code>_seed.docx (checked into the repository). This
 * means re-seeding after a Docker volume wipe still works without Gotenberg or
 * any runtime generation. If the committed asset is missing (e.g. local dev
 * checkout without the file), it falls back to generating a minimal docx
 * programmatically via PHPWord.
 *
 * The generated docx contains all placeholder variables the ContractGenerationService
 * and ContractContextBuilder expect. Unknown variables are silently skipped by
 * TemplateProcessor.setValue(), so adding extras here is safe.
 */
class TemplateVersionSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = User::where('email', 'admin@mgcrm.test')->value('id') ?? 1;

        $docxTemplates = Template::query()
            ->where('kind', 'docx')
            ->get();

        foreach ($docxTemplates as $template) {
            // Skip if already has a version.
            if ($template->current_version_id !== null) {
                continue;
            }

            $diskPath = $this->seedDocxVersion($template, $adminId);

            $this->command->info("TemplateVersionSeeder: seeded {$template->code} → {$diskPath}");
        }
    }

    private function seedDocxVersion(Template $template, int $adminId): string
    {
        $diskPath = "templates/{$template->code}/seed_v1.docx";

        // 1. Try durable committed asset first (survives volume wipes).
        $assetPath = base_path("resources/templates/{$template->code}_seed.docx");
        if (is_file($assetPath)) {
            Storage::disk('documents')->put($diskPath, (string) file_get_contents($assetPath));
            $this->command?->line("  Using committed asset: {$assetPath}");
        } else {
            // 2. Fallback: generate programmatically (no committed asset found).
            $this->command?->warn("  Committed asset not found at {$assetPath}, generating on the fly.");
            $docxContent = $this->buildDocxContent($template->code);

            $tmpPath = sys_get_temp_dir().'/mgcrm_seed_'.$template->code.'_'.uniqid().'.docx';
            $writer = IOFactory::createWriter($docxContent, 'Word2007');
            $writer->save($tmpPath);

            Storage::disk('documents')->put($diskPath, (string) file_get_contents($tmpPath));
            @unlink($tmpPath);
        }

        // Latest version number.
        $lastVersion = TemplateVersion::query()
            ->where('template_id', $template->id)
            ->max('version_number') ?? 0;

        $version = TemplateVersion::create([
            'template_id' => $template->id,
            'version_number' => $lastVersion + 1,
            'docx_path' => $diskPath,
            'ai_remarks' => [],
            'ai_overridden' => false,
            'ai_check_status' => AiCheckStatus::Checked,
            'ai_checked_at' => now(),
            'pdf_ok' => true,
            'created_by_user_id' => $adminId,
            'created_at' => now(),
        ]);

        $template->update(['current_version_id' => $version->id]);

        return $diskPath;
    }

    /**
     * Build a minimal but valid PhpWord document with common contract placeholders.
     * All variables used by ContractContextBuilder/ContractGenerationService are included.
     * PHPWord TemplateProcessor silently skips missing variables, so extras are harmless.
     *
     * This fallback is only used when the committed asset under resources/templates/ is
     * missing. In normal operation the committed asset is used — see seedDocxVersion().
     */
    private function buildDocxContent(string $code): PhpWord
    {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Times New Roman');
        $phpWord->setDefaultFontSize(12);

        $section = $phpWord->addSection();

        if ($code === 'termination_agreement') {
            $this->addTerminationContent($section);
        } else {
            $this->addMasterSkeletonContent($section);
        }

        return $phpWord;
    }

    private function addMasterSkeletonContent(Section $section): void
    {
        $section->addText('ДОГОВОР СУБЛИЦЕНЗИИ ${contract.number}');
        $section->addText('г. ${contract.city} ${contract.date_day} ${contract.date_month} ${contract.date_year} г.');
        $section->addText('');
        $section->addText('ЛИЦЕНЗИАР: ${licensor.name_full}');
        $section->addText('ИНН/БИН: ${licensor.tax_id}');
        $section->addText('Директор: ${licensor.director_genitive}');
        $section->addText('Адрес: ${licensor.address}');
        $section->addText('Банк: ${licensor.bank}');
        $section->addText('Счёт: ${licensor.account}');
        $section->addText('');
        $section->addText('ЛИЦЕНЗИАТ: ${sublicensee.name}');
        $section->addText('ИНН/БИН: ${sublicensee.tax_id}');
        $section->addText('Директор: ${sublicensee.director_genitive}');
        $section->addText('Адрес: ${sublicensee.address}');
        $section->addText('');
        $section->addText('Территория: ${license.territory}');
        $section->addText('Валюта: ${contract.currency}');
        $section->addText('Сумма: ${pricing.total}');
        $section->addText('Сумма прописью: ${total_in_words}');
        $section->addText('');
        // Items table placeholder (cloneRow uses 'item_name' as anchor).
        $table = $section->addTable();
        $row = $table->addRow();
        $row->addCell(3000)->addText('${item_name}');
        $row->addCell(1000)->addText('${item_qty}');
        $row->addCell(2000)->addText('${item_price}');
        $row->addCell(2000)->addText('${item_total}');
        $section->addText('');
        $section->addText('Лицензиар: ___________________');
        $section->addText('Лицензиат: ___________________');
    }

    private function addTerminationContent(Section $section): void
    {
        $section->addText('СОГЛАШЕНИЕ О РАСТОРЖЕНИИ ДОГОВОРА');
        $section->addText('г. ${contract.city} ${contract.date_day} ${contract.date_month} ${contract.date_year} г.');
        $section->addText('');
        $section->addText('ЛИЦЕНЗИАР: ${licensor.name_full}');
        $section->addText('Директор: ${licensor.director_genitive}');
        $section->addText('');
        $section->addText('ЛИЦЕНЗИАТ: ${sublicensee.name}');
        $section->addText('Директор: ${sublicensee.director_genitive}');
        $section->addText('');
        $section->addText('Договор: ${custom.original_contract_number}');
        $section->addText('Дата расторжения: ${custom.termination_date}');
        $section->addText('Основание: ${custom.termination_reason}');
        $section->addText('Задолженность: ${custom.outstanding_amount}');
        $section->addText('Сроки: ${custom.settlement_terms}');
        $section->addText('');
        $section->addText('Лицензиар: ___________________');
        $section->addText('Лицензиат: ___________________');
    }
}
