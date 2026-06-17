<?php

declare(strict_types=1);

namespace App\Console\Commands\Onboarding;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * PublishCertificateTemplateCommand — create a demo certificate template on
 * disk 'documents' at onboarding/templates/certificate.docx.
 *
 * This command is meant to be run once on first deploy (or when the template
 * needs to be reset to the demo version). HR can replace it by uploading a
 * production-grade design.
 *
 * Usage:
 *   php artisan onboarding:publish-certificate-template
 */
class PublishCertificateTemplateCommand extends Command
{
    protected $signature = 'onboarding:publish-certificate-template
                            {--force : Overwrite existing template}';

    protected $description = 'Publish the demo certificate DOCX template to the documents disk';

    public function handle(): int
    {
        $diskPath = 'onboarding/templates/certificate.docx';
        $disk = Storage::disk('documents');

        if ($disk->exists($diskPath) && ! $this->option('force')) {
            $this->info("Template already exists at '{$diskPath}'. Use --force to overwrite.");

            return self::SUCCESS;
        }

        // Build a simple demo template using PHPWord.
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();

        $section->addText('СЕРТИФИКАТ', ['bold' => true, 'size' => 24]);
        $section->addTextBreak(1);
        $section->addText('Номер: ${certificate_number}', ['bold' => true]);
        $section->addTextBreak(1);
        $section->addText('Настоящим подтверждается, что');
        $section->addTextBreak(1);
        $section->addText('${learner_name}', ['bold' => true, 'size' => 16]);
        $section->addTextBreak(1);
        $section->addText('успешно завершил(а) курс:');
        $section->addTextBreak(1);
        $section->addText('«${course_title}»', ['italic' => true, 'size' => 14]);
        $section->addTextBreak(1);
        $section->addText('${course_description}');
        $section->addTextBreak(2);
        $section->addText('Дата выдачи: ${issued_date}');
        $section->addTextBreak(2);
        $section->addText('MACRO Global', ['bold' => true]);

        $tempPath = sys_get_temp_dir().'/mgcrm_cert_template_'.uniqid().'.docx';

        try {
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($tempPath);

            $disk->put($diskPath, (string) file_get_contents($tempPath));

            $this->info("Certificate template published to '{$diskPath}'.");
        } finally {
            @unlink($tempPath);
        }

        return self::SUCCESS;
    }
}
