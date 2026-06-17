<?php

declare(strict_types=1);

namespace App\Domain\Notification\Telegram;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Approval;
use App\Domain\Contracts\Models\Document;

/**
 * AuthorVerdictText (S2.9) — RU HTML body of the verdict DM sent to a document
 * author when their contract is fully approved, rejected, or sent for rework.
 *
 * Only the final statuses are addressed here; the intermediate "approved but
 * quorum not yet reached" (InReview) status is filtered out by the listener
 * before this builder is called.
 */
final class AuthorVerdictText
{
    public static function build(Document $document, Approval $approval, ContractStatus $status): string
    {
        $title = self::escape((string) ($document->title ?? '—'));
        $number = $document->number !== null && $document->number !== ''
            ? self::escape((string) $document->number)
            : '(номер не присвоен)';
        $url = self::documentUrl((int) $document->id);
        $comment = self::escape((string) ($approval->comment ?? ''));

        return match ($status) {
            ContractStatus::Approved => implode("\n", [
                '🎉 <b>Договор согласован</b>',
                "«{$title}» № {$number} полностью прошёл согласование.",
                "Открыть карточку → {$url}",
            ]),
            ContractStatus::Rejected => implode("\n", [
                '❌ <b>Договор отклонён</b>',
                "«{$title}» № {$number}.",
                "Причина: {$comment}",
                "Открыть карточку → {$url}",
            ]),
            ContractStatus::NeedsRework => implode("\n", [
                '🔁 <b>Договор на доработку</b>',
                "«{$title}» № {$number}.",
                "Комментарий: {$comment}",
                "Открыть карточку → {$url}",
            ]),
            default => '',
        };
    }

    private static function documentUrl(int $documentId): string
    {
        $base = rtrim((string) config('crm.telegram.web_base_url'), '/');

        return $base.'/documents/'.$documentId;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
