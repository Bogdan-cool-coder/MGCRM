"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { OnboardingRequiredModal } from "@/components/Onboarding/OnboardingRequiredModal";
import { api, ApiError, fetcher } from "@/lib/api";
import type { BulkTargetType, TemplateInfo } from "@/lib/types";

/** Модалка выбора шаблона для bulk-генерации документов (Эпик 6 MVP).
 *
 *  POST /api/bulk-tasks/generate-documents → редирект на
 *  /admin/bulk-tasks?highlight={task_id}.
 */
interface Props {
  open: boolean;
  onClose: () => void;
  selectedIds: number[];
  targetType: BulkTargetType;
  /** Подпись типа целевых объектов в шапке («Контрагентов» / «Подписок»). */
  targetLabel: string;
  /** Колбэк после успешного создания задачи (например — очистить выбор). */
  onCreated?: () => void;
}

interface CreateResponse {
  task_id: number;
  status: string;
  total_count: number;
}

export function BulkDocumentModal({
  open,
  onClose,
  selectedIds,
  targetType,
  targetLabel,
  onCreated,
}: Props) {
  const router = useRouter();
  const { data: templates } = useSWR<TemplateInfo[]>(open ? "/templates" : null, fetcher);

  const [templateCode, setTemplateCode] = useState<string>("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [onboardingModalOpen, setOnboardingModalOpen] = useState(false);

  // Допустимые для bulk: только с категорией + не master_skeleton
  // (master — служебный скелет, не клиентский документ).
  const templateOptions = useMemo(() => {
    if (!templates) return [];
    return templates.filter(
      (t) => t.category && t.code !== "master_skeleton",
    );
  }, [templates]);

  async function submit() {
    if (!templateCode) {
      setError("Выберите шаблон");
      return;
    }
    if (selectedIds.length === 0) {
      setError("Не выбрано ни одного объекта");
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      const res = await api<CreateResponse>("/bulk-tasks/generate-documents", {
        method: "POST",
        body: {
          template_code: templateCode,
          target_type: targetType,
          target_ids: selectedIds,
        },
      });
      onCreated?.();
      onClose();
      router.push(`/admin/bulk-tasks?highlight=${res.task_id}`);
    } catch (err) {
      // Перехват 403 onboarding_required — показываем soft-gate modal
      if (err instanceof ApiError && err.status === 403) {
        const detail = err.detail as { code?: string } | null;
        if (detail && typeof detail === "object" && detail.code === "onboarding_required") {
          setOnboardingModalOpen(true);
          return;
        }
      }
      const msg =
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось запустить генерацию";
      setError(msg);
    } finally {
      setSubmitting(false);
    }
  }

  const count = selectedIds.length;
  const showWarn = count > 50;

  return (
    <>
    <OnboardingRequiredModal
      open={onboardingModalOpen}
      onClose={() => setOnboardingModalOpen(false)}
    />
    <Modal
      open={open}
      onClose={onClose}
      title="Массовая генерация документов"
      description={`Выбрано ${targetLabel.toLowerCase()}: ${count}`}
      width="md"
      footer={
        <>
          <button className="btn-secondary" onClick={onClose}>
            Отмена
          </button>
          <button
            className="btn-primary"
            onClick={submit}
            disabled={submitting || !templateCode || count === 0}
          >
            {submitting ? "Запуск…" : (<><i className="bi bi-play-fill" /> Запустить</>)}
          </button>
        </>
      }
    >
      <div className="space-y-4">
        {error && (
          <div className="text-danger text-sm bg-danger/10 px-3 py-2 rounded">{error}</div>
        )}

        {showWarn && (
          <div className="text-sm bg-warning/30 text-gray-900 px-3 py-2 rounded">
            <i className="bi bi-exclamation-triangle mr-1" />
            Большая партия ({count} шт.). Генерация может занять несколько минут.
          </div>
        )}

        <div>
          <label className="label">Шаблон</label>
          <select
            className="input"
            value={templateCode}
            onChange={(e) => setTemplateCode(e.target.value)}
          >
            <option value="">— выберите шаблон —</option>
            {templateOptions.map((t) => (
              <option key={t.code} value={t.code}>
                {t.title || t.code}
              </option>
            ))}
          </select>
          {templates && templateOptions.length === 0 && (
            <div className="text-xs text-gray-500 mt-1">
              Нет доступных шаблонов с категорией. Зайдите в раздел «Шаблоны» и задайте категорию хотя бы одному шаблону.
            </div>
          )}
        </div>

        <div className="text-xs text-gray-500">
          После запуска задача появится в разделе «Bulk-задачи». Скачать архив можно будет, когда статус станет «Готово».
        </div>
      </div>
    </Modal>
    </>
  );
}
