"use client";

interface MoveErrorAlertProps {
  /** Подписи незаполненных полей (уже человекочитаемые RU). */
  missingLabels: string[];
  onClose: () => void;
}

/**
 * Wave 4: inline-баннер вверху карточки при 422 REQUIRED_FIELDS_MISSING.
 * Перечисляет незаполненные обязательные поля; сами поля подсвечиваются красным.
 */
export function MoveErrorAlert({ missingLabels, onClose }: MoveErrorAlertProps) {
  return (
    <div className="rounded-md bg-danger/10 border border-danger/30 text-danger px-4 py-3 text-sm flex items-start gap-2">
      <i className="bi bi-exclamation-triangle-fill shrink-0 mt-0.5" />
      <div className="flex-1 min-w-0">
        <div className="font-medium">Заполните обязательные поля для перехода в этап</div>
        {missingLabels.length > 0 && (
          <ul className="mt-1 list-disc list-inside text-danger/90">
            {missingLabels.map((l) => (
              <li key={l}>{l}</li>
            ))}
          </ul>
        )}
      </div>
      <button type="button" className="text-danger hover:opacity-70 shrink-0" onClick={onClose} aria-label="Закрыть">
        <i className="bi bi-x-lg" />
      </button>
    </div>
  );
}
