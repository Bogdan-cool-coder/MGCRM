"use client";

import { Modal } from "@/components/Modal";
import { RunStatusBadge } from "@/components/Automations/RunStatusBadge";
import { TARGET_TYPE_LABELS } from "@/lib/automationConfig";
import type { AutomationRun } from "@/lib/types";

interface Props {
  open: boolean;
  onClose: () => void;
  run: AutomationRun | null;
}

function fmt(dt: string | null): string {
  if (!dt) return "—";
  try {
    return new Date(dt).toLocaleString("ru-RU");
  } catch {
    return dt;
  }
}

function durationMs(started: string, finished: string | null): string {
  if (!finished) return "—";
  try {
    const ms = new Date(finished).getTime() - new Date(started).getTime();
    if (Number.isNaN(ms)) return "—";
    if (ms < 1000) return `${ms} мс`;
    return `${(ms / 1000).toFixed(2)} с`;
  } catch {
    return "—";
  }
}

/** Модалка деталей запуска. Показывает result_json, error_text, target. */
export function RunDetailsModal({ open, onClose, run }: Props) {
  if (!run) {
    return (
      <Modal open={open} onClose={onClose} title="Запуск автоматизации" width="lg">
        <div className="text-gray-500">Нет данных</div>
      </Modal>
    );
  }

  const target = run.target_type as keyof typeof TARGET_TYPE_LABELS;
  const targetLabel = TARGET_TYPE_LABELS[target] ?? run.target_type;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`Запуск #${run.id}`}
      description={run.automation_name ?? `Автоматизация #${run.automation_id}`}
      width="lg"
      footer={
        <button className="btn-secondary" onClick={onClose}>Закрыть</button>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
          <Cell label="Статус"><RunStatusBadge status={run.status} /></Cell>
          <Cell label="Цель">
            <span className="font-mono">{targetLabel} #{run.target_id}</span>
          </Cell>
          <Cell label="Начат">{fmt(run.started_at)}</Cell>
          <Cell label="Завершён">{fmt(run.finished_at)}</Cell>
          <Cell label="Длительность">{durationMs(run.started_at, run.finished_at)}</Cell>
          <Cell label="Automation ID"><span className="font-mono">#{run.automation_id}</span></Cell>
        </div>

        {run.error_text && (
          <div>
            <div className="text-xs text-gray-500 uppercase mb-1">Сообщение об ошибке / причина</div>
            <div className="text-sm bg-danger/10 border border-danger/30 rounded-md p-3 text-gray-900 whitespace-pre-wrap">
              {run.error_text}
            </div>
          </div>
        )}

        <div>
          <div className="text-xs text-gray-500 uppercase mb-1">Результат (result_json)</div>
          {run.result_json ? (
            <pre className="text-xs bg-gray-50 border border-gray-200 rounded-md p-3 overflow-x-auto whitespace-pre-wrap">
              {JSON.stringify(run.result_json, null, 2)}
            </pre>
          ) : (
            <div className="text-sm text-gray-500 italic">пусто</div>
          )}
        </div>
      </div>
    </Modal>
  );
}

function Cell({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <div className="text-xs text-gray-500 mb-0.5">{label}</div>
      <div>{children}</div>
    </div>
  );
}
