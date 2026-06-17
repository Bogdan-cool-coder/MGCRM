<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Activity\Models\MeetingReportOption;
use App\Domain\Activity\Models\MeetingReportQuestion;
use Illuminate\Database\Seeder;

/**
 * INSERT-MISSING idempotent seeder for the default GLOBAL meeting-report
 * questions (FTM constructor). Keyed by (text, pipeline_id NULL); re-running
 * does not duplicate. select-kind questions get their options synced too.
 * Registered in DatabaseSeeder (insert-missing, NOT truncate).
 */
class MeetingReportQuestionSeeder extends Seeder
{
    /**
     * Default global questions.
     *
     * @var list<array{text: string, kind: string, options?: list<string>}>
     */
    private const QUESTIONS = [
        [
            'text' => 'Присутствовал ли ЛПР на встрече?',
            'kind' => 'select',
            'options' => ['Да', 'Нет', 'Частично'],
        ],
        [
            'text' => 'Была ли показана презентация продукта?',
            'kind' => 'select',
            'options' => ['Да', 'Нет'],
        ],
        [
            'text' => 'Какой следующий шаг согласован?',
            'kind' => 'text',
        ],
        [
            'text' => 'Обсуждался ли бюджет?',
            'kind' => 'select',
            'options' => ['Да, бюджет есть', 'Да, бюджета нет', 'Нет, не обсуждали'],
        ],
        [
            'text' => 'Кто принимает решение о покупке?',
            'kind' => 'text',
        ],
        [
            'text' => 'Какие возражения прозвучали?',
            'kind' => 'text',
        ],
    ];

    public function run(): void
    {
        foreach (self::QUESTIONS as $index => $def) {
            $question = MeetingReportQuestion::firstOrCreate(
                ['text' => $def['text'], 'pipeline_id' => null],
                [
                    'kind' => $def['kind'],
                    'sort_order' => $index + 1,
                    'is_active' => true,
                ],
            );

            foreach ($def['options'] ?? [] as $optIndex => $optionText) {
                MeetingReportOption::firstOrCreate(
                    ['question_id' => $question->id, 'text' => $optionText],
                    ['sort_order' => $optIndex + 1],
                );
            }
        }
    }
}
