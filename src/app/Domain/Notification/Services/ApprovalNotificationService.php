<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Contracts\Models\Document;
use App\Domain\Crm\Models\Country;
use App\Domain\Notification\Enums\ApprovalCallbackAction;
use App\Domain\Notification\Jobs\SendTelegramApprovalCardJob;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * ApprovalNotificationService (S2.9) — builds the approval-card text + inline
 * keyboard and dispatches the queued send to the approval group chat.
 *
 * The card is intentionally minimal: company, number, country, product, author
 * and a deep link into the system. No secrets, no internal user IDs, no token.
 * Stage header (➡️ Этап: …) is shown only when the document is past the first
 * stage (order > 1), matching the legacy behaviour.
 */
class ApprovalNotificationService
{
    /**
     * Dispatch the approval card for a document entering an approval stage.
     *
     * @param  array<string, mixed>  $stage  {order, name, user_ids[], min_required}
     */
    public function notifyStage(Document $document, array $stage, int $attempt): void
    {
        $chatId = (string) config('crm.telegram.approval_chat_id');

        if ($chatId === '') {
            // No approval chat configured — nothing to notify (idle, not fatal).
            return;
        }

        SendTelegramApprovalCardJob::dispatch(
            documentId: (int) $document->id,
            chatId: $chatId,
            text: $this->buildCard($document, $stage),
        );
    }

    /**
     * Build the HTML card text for a document + stage.
     *
     * @param  array<string, mixed>  $stage
     */
    public function buildCard(Document $document, array $stage): string
    {
        $company = $this->escape((string) ($document->title ?? '—'));
        $number = $document->number !== null && $document->number !== ''
            ? $this->escape((string) $document->number)
            : '(номер не присвоен)';
        $country = $this->escape($this->countryName((string) $document->country_code));
        $product = $this->escape($this->productName((string) $document->product_code));
        $author = $this->escape((string) ($document->author?->full_name ?? '—'));
        $url = $this->documentUrl((int) $document->id);

        $lines = [];

        // Stage header only past the first stage.
        if ((int) ($stage['order'] ?? 1) > 1) {
            $stageName = $this->escape((string) ($stage['name'] ?? ''));
            $lines[] = "➡️ Этап: {$stageName}";
            $lines[] = '';
        }

        $lines[] = "📄 Договор на согласование «{$company}»";
        $lines[] = "№ {$number}";
        $lines[] = '';
        $lines[] = "Страна: {$country}";
        $lines[] = "Продукт: {$product}";
        $lines[] = "Автор: {$author}";
        $lines[] = '';
        $lines[] = "Открыть в системе → {$url}";

        return implode("\n", $lines);
    }

    /**
     * Build the 3-button inline keyboard for a document.
     * callback_data carries only apv:{action}:{documentId} (no secrets — §И).
     */
    public function buildKeyboard(int $documentId): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(
                    '✅ Согласовать',
                    callback_data: 'apv:'.ApprovalCallbackAction::Approve->value.':'.$documentId,
                ),
                InlineKeyboardButton::make(
                    '❌ Отклонить',
                    callback_data: 'apv:'.ApprovalCallbackAction::Reject->value.':'.$documentId,
                ),
            )
            ->addRow(
                InlineKeyboardButton::make(
                    '🔁 На доработку',
                    callback_data: 'apv:'.ApprovalCallbackAction::Rework->value.':'.$documentId,
                ),
            );
    }

    // ---- Private helpers ----

    /** Human country name from the directory, with a fallback map and raw code. */
    private function countryName(string $code): string
    {
        if ($code === '') {
            return '—';
        }

        $name = Country::query()->where('code', strtolower($code))->value('name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return match (strtolower($code)) {
            'kz' => 'Казахстан',
            'uz' => 'Узбекистан',
            default => strtoupper($code),
        };
    }

    /** Human product name from the catalog, falling back to the raw code. */
    private function productName(string $code): string
    {
        if ($code === '') {
            return '—';
        }

        $name = Product::query()->where('code', $code)->value('name');

        return is_string($name) && $name !== '' ? $name : $code;
    }

    private function documentUrl(int $documentId): string
    {
        $base = rtrim((string) config('crm.telegram.web_base_url'), '/');

        return $base.'/documents/'.$documentId;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
