<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Contracts\Models\MessageTemplate;
use App\Domain\Contracts\Models\MessageTemplateBinding;
use Illuminate\Database\Seeder;

/**
 * MessageTemplateSeeder — три демо-шаблона с биндингами.
 * Идемпотентен: повторный запуск не дублирует записи (insert-missing по title).
 */
class MessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Уведомление о готовом договоре — без биндинга (универсальный)
        $template1 = MessageTemplate::firstOrCreate(
            ['title' => 'Уведомление о готовом договоре'],
            [
                'subject' => 'Договор №{{document.number}} готов к подписанию',
                'body' => 'Здравствуйте, {{contact.full_name}}! Договор №{{document.number}} с {{company.name}} готов к подписанию.',
                'description' => 'Универсальный шаблон: без привязки к каналу/стадии. Используется напрямую по id.',
                'is_active' => true,
            ],
        );

        // No bindings → universal template

        // 2. Приветствие нового лида — биндинг: channel_kind='tg'
        $template2 = MessageTemplate::firstOrCreate(
            ['title' => 'Приветствие нового лида'],
            [
                'subject' => null,
                'body' => 'Добро пожаловать! Ваш менеджер {{user.full_name}} свяжется с вами по сделке «{{deal.name}}».',
                'description' => 'Отправляется через Telegram при появлении нового лида.',
                'is_active' => true,
            ],
        );

        MessageTemplateBinding::firstOrCreate([
            'message_template_id' => $template2->id,
            'channel_kind' => 'tg',
            'pipeline_id' => null,
            'pipeline_stage_id' => null,
            'activity_type' => null,
            'automation_slot' => null,
        ]);

        // 3. Напоминание о встрече — биндинг: activity_type='meeting'
        $template3 = MessageTemplate::firstOrCreate(
            ['title' => 'Напоминание о встрече'],
            [
                'subject' => 'Напоминание о встрече {{date.tomorrow}}',
                'body' => 'Напоминаем о встрече {{date.tomorrow}}. Сделка: {{deal.name}}, клиент: {{company.name}}.',
                'description' => 'Отправляется как напоминание перед встречей (activity_type=meeting).',
                'is_active' => true,
            ],
        );

        MessageTemplateBinding::firstOrCreate([
            'message_template_id' => $template3->id,
            'channel_kind' => null,
            'pipeline_id' => null,
            'pipeline_stage_id' => null,
            'activity_type' => 'meeting',
            'automation_slot' => null,
        ]);
    }
}
