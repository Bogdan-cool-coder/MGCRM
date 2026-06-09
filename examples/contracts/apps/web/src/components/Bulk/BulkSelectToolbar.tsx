"use client";

/** Sticky-toolbar внизу экрана, появляется при наличии выбранных строк
 *  (Эпик 6 MVP). Используется в /registry и /counterparties.
 */
interface Props {
  selectedCount: number;
  onClear: () => void;
  onAction: () => void;
  /** Подпись действия — по умолчанию «Сгенерировать документы». */
  actionLabel?: string;
}

export function BulkSelectToolbar({
  selectedCount,
  onClear,
  onAction,
  actionLabel = "Сгенерировать документы",
}: Props) {
  if (selectedCount <= 0) return null;
  return (
    <div className="fixed bottom-4 left-1/2 -translate-x-1/2 z-30 bg-white border border-gray-200 rounded-lg shadow-lg px-4 py-3 flex items-center gap-3">
      <span className="text-sm font-medium text-gray-700">
        Выбрано: <span className="tabular-nums text-primary">{selectedCount}</span>
      </span>
      <div className="h-5 w-px bg-gray-200" />
      <button onClick={onAction} className="btn-primary text-sm">
        <i className="bi bi-archive-fill" /> {actionLabel}
      </button>
      <button onClick={onClear} className="btn-ghost text-sm" title="Снять выбор">
        <i className="bi bi-x-lg" /> Очистить
      </button>
    </div>
  );
}
