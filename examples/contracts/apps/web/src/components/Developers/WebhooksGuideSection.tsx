"use client";

import Link from "next/link";

export function WebhooksGuideSection() {
  return (
    <div className="card rounded-2xl shadow-elev-1 border border-gray-100 dark:border-gray-800 p-6">
      <h2 className="text-h4 mb-3">Webhooks — исходящие уведомления</h2>
      <div className="space-y-3 text-sm text-gray-700 dark:text-gray-300">
        <p>
          MACRO CRM отправляет события в твою систему при создании и изменении лидов, сделок
          и договоров. Подпишись на нужные события и получай данные в реальном времени.
        </p>
        <p>
          Каждый webhook-запрос подписан HMAC-SHA256 заголовком{" "}
          <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded text-xs font-mono">
            X-MACRO-Signature
          </code>
          . Проверяй подпись на своём сервере для безопасности.
        </p>
        <div>
          <p className="font-medium text-gray-900 dark:text-gray-100 mb-2">Поддерживаемые события:</p>
          <ul className="space-y-1 pl-4">
            {[
              "leads.created — лид создан",
              "leads.converted — лид сконвертирован",
              "deals.created — сделка создана",
              "deals.stage_changed — сделка изменила этап",
              "deals.won — сделка выиграна",
              "deals.lost — сделка проиграна",
              "contracts.created — договор создан",
              "contracts.signed — договор подписан",
              "subscriptions.created — подписка создана",
              "subscriptions.health_changed — изменилось здоровье подписки",
            ].map((event) => (
              <li key={event} className="flex items-start gap-2">
                <code className="bg-gray-100 dark:bg-gray-700 px-1 rounded text-xs font-mono shrink-0 mt-0.5">
                  {event.split(" — ")[0]}
                </code>
                <span className="text-gray-600 dark:text-gray-400">{event.split(" — ")[1]}</span>
              </li>
            ))}
          </ul>
        </div>
      </div>
      <div className="mt-5">
        <Link href="/admin/webhooks" className="btn-secondary">
          <i className="bi bi-broadcast-pin mr-1" />
          Настроить вебхуки
        </Link>
      </div>
    </div>
  );
}
