"use client";

import Link from "next/link";
import type { DupMatch } from "@/hooks/useDupCheck";

interface DupCheckWarningProps {
  matches: DupMatch[];
  checking: boolean;
  entityType: string;
  onDismiss: () => void;
}

// Маппинг entity_type → сегмент URL
function entityPath(entityType: string, id: number): string {
  const paths: Record<string, string> = {
    counterparty: `/counterparties/${id}`,
    contact: `/contacts/${id}`,
    company: `/companies/${id}`,
    lead: `/leads/${id}`,
  };
  return paths[entityType] ?? `/${entityType}s/${id}`;
}

export function DupCheckWarning({ matches, checking, entityType, onDismiss }: DupCheckWarningProps) {
  // Пока идёт проверка или нет совпадений — ничего не показываем
  if (checking || matches.length === 0) return null;

  const first = matches[0];
  const extra = matches.length - 1;

  return (
    <div className="mt-1.5 rounded-md bg-warning/10 border border-warning/30 px-3 py-2 text-sm">
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-start gap-1.5">
          <i className="bi bi-exclamation-triangle text-warning mt-0.5 shrink-0" />
          <div>
            <span className="font-medium text-gray-800">Похоже на существующего: </span>
            <Link
              href={entityPath(entityType, first.id)}
              target="_blank"
              className="text-primary underline underline-offset-2 hover:text-primary-light"
            >
              {first.display_name}
            </Link>
            {extra > 0 && (
              <span className="text-gray-500">
                {" "}и ещё {extra} похожих{" "}
                <Link
                  href="/admin/duplicates"
                  target="_blank"
                  className="text-primary underline underline-offset-2"
                >
                  → Дубли
                </Link>
              </span>
            )}
          </div>
        </div>
        <button
          type="button"
          className="text-gray-400 hover:text-gray-600 shrink-0 -mt-0.5"
          onClick={onDismiss}
          title="Закрыть предупреждение"
        >
          <i className="bi bi-x-lg text-xs" />
        </button>
      </div>
      <button
        type="button"
        className="mt-1.5 text-xs text-gray-500 hover:text-gray-700 underline underline-offset-1"
        onClick={onDismiss}
      >
        Я знаю, продолжить
      </button>
    </div>
  );
}
