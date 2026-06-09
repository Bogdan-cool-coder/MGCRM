"use client";

/**
 * Конфиг триггера on_enter_stage. Минимальный — все параметры передаются через
 * stage_id (выбирается в общей форме). Здесь только подсказка для пользователя.
 *
 * Backend dry-run требует stage_id у автоматизации (если не задан — действует
 * на всех этапах воронки). target_type/target_id передаются caller-кодом
 * (роутер deals/leads после смены стадии).
 */
export function OnEnterStageConfig() {
  return (
    <div className="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-md p-3">
      <i className="bi bi-info-circle mr-1" />
      Триггер сработает <strong>в момент перевода</strong> сделки/лида на выбранный этап
      (или на любой этап воронки, если этап в форме не выбран).
      Дополнительных параметров не требуется.
    </div>
  );
}
