"use client";

import { useState } from "react";
import useSWR from "swr";
import { api, fetcher, errorMessage } from "@/lib/api";
import { Modal } from "@/components/Modal";
import { UserSelect } from "@/components/UserSelect";
import type { DealBulkResult, LostReasonItem, PipelineStage, UserRole } from "@/lib/types";

interface Props {
  selectedIds: number[];
  stages: PipelineStage[];
  userRole: UserRole;
  onClear: () => void;
  onMutate: () => void;
}

type TagMode = "add" | "replace" | "remove";

export function DealBulkActionsBar({ selectedIds, stages, userRole, onClear, onMutate }: Props) {
  const [ownerOpen, setOwnerOpen] = useState(false);
  const [stageOpen, setStageOpen] = useState(false);
  const [tagsOpen, setTagsOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [ownerUserId, setOwnerUserId] = useState("");
  const [stageId, setStageId] = useState("");
  const [lostReasonId, setLostReasonId] = useState("");
  const [lostReasonText, setLostReasonText] = useState("");
  const [tagsInput, setTagsInput] = useState("");
  const [tagMode, setTagMode] = useState<TagMode>("add");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const n = selectedIds.length;
  const canDelete = userRole === "admin" || userRole === "director";

  // Выбранный этап и признак «Проиграна» (требует причину на бэке).
  const selectedStage = stages.find((s) => String(s.id) === stageId);
  const stageIsLost = selectedStage?.is_lost ?? false;

  // Реестр причин проигрыша — грузим только когда нужен (выбран lost-этап).
  const { data: lostReasons, error: reasonsError } = useSWR<LostReasonItem[]>(
    stageOpen && stageIsLost ? "/deals/lost-reasons" : null,
    fetcher
  );
  const registryReasons = (lostReasons ?? []).filter((r) => r.is_active);
  const usePresets = stageIsLost && (!lostReasons || !!reasonsError || registryReasons.length === 0);

  // Для lost-этапа нужна причина: либо id из реестра, либо текст.
  const lostReasonReady = !stageIsLost || lostReasonId !== "" || lostReasonText.trim().length > 0;

  function buildStagePayload(): Record<string, unknown> {
    const payload: Record<string, unknown> = { stage_id: Number(stageId) };
    if (stageIsLost) {
      if (lostReasonId !== "") payload.lost_reason_id = Number(lostReasonId);
      if (lostReasonText.trim()) payload.lost_reason = lostReasonText.trim();
    }
    return payload;
  }

  function closeStageModal() {
    setStageOpen(false);
    setStageId("");
    setLostReasonId("");
    setLostReasonText("");
  }

  async function runBulk(action: string, payload: Record<string, unknown>) {
    setSubmitting(true);
    setError(null);
    const total = selectedIds.length;
    try {
      const res = await api<DealBulkResult>("/deals/bulk", {
        method: "POST",
        body: { action, ids: selectedIds, payload },
      });
      onMutate();
      const errs = res.errors ?? [];
      if (errs.length > 0) {
        // Частичный успех: не закрываем модалку и не чистим выбор — показываем сводку.
        const preview = errs.slice(0, 5).join("; ");
        const more = errs.length > 5 ? ` и ещё ${errs.length - 5}` : "";
        setError(`Обновлено ${res.updated} из ${total}. Ошибки: ${preview}${more}`);
      } else {
        onClear();
        setOwnerOpen(false);
        setStageOpen(false);
        setStageId("");
        setLostReasonId("");
        setLostReasonText("");
        setTagsOpen(false);
        setDeleteOpen(false);
      }
    } catch (err: unknown) {
      setError(errorMessage(err, "Не удалось выполнить действие"));
    } finally {
      setSubmitting(false);
    }
  }

  function parseTags(): string[] {
    return tagsInput.split(",").map((t) => t.trim()).filter(Boolean);
  }

  return (
    <>
      <div
        className={
          "fixed bottom-0 left-[240px] right-0 z-30 bg-white dark:bg-gray-800 " +
          "border-t border-gray-200 dark:border-gray-700 px-6 py-3 " +
          "flex items-center gap-3 shadow-lg transition-transform duration-200 " +
          (n > 0 ? "translate-y-0" : "translate-y-full")
        }
      >
        <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
          Выбрано: {n}
        </span>
        <div className="h-4 w-px bg-gray-300 dark:border-gray-600" />

        <button className="btn-secondary text-sm" onClick={() => setOwnerOpen(true)}>
          <i className="bi bi-person-check mr-1.5" />
          Ответственный
        </button>

        <button className="btn-secondary text-sm" onClick={() => setStageOpen(true)}>
          <i className="bi bi-arrow-right-circle mr-1.5" />
          Изменить этап
        </button>

        <button className="btn-secondary text-sm" onClick={() => setTagsOpen(true)}>
          <i className="bi bi-tags mr-1.5" />
          Теги
        </button>

        {canDelete && (
          <button className="btn-secondary text-sm text-danger" onClick={() => setDeleteOpen(true)}>
            <i className="bi bi-trash mr-1.5" />
            Удалить
          </button>
        )}

        <button className="btn-ghost text-sm ml-auto" onClick={onClear}>
          Отмена выбора
        </button>
      </div>

      {/* Owner modal */}
      <Modal
        open={ownerOpen}
        title={`Сменить ответственного для ${n} сделок`}
        onClose={() => setOwnerOpen(false)}
        width="sm"
        footer={
          <>
            <button className="btn-ghost" onClick={() => setOwnerOpen(false)}>Отмена</button>
            <button
              className="btn-primary disabled:opacity-50"
              disabled={!ownerUserId || submitting}
              onClick={() => runBulk("change_owner", { owner_user_id: Number(ownerUserId) })}
            >
              {submitting ? "Сохранение…" : "Сохранить"}
            </button>
          </>
        }
      >
        <div className="space-y-3">
          {error && <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>}
          <div>
            <label className="label">Новый ответственный</label>
            <UserSelect value={ownerUserId} onChange={setOwnerUserId} placeholder="Выбрать…" />
          </div>
        </div>
      </Modal>

      {/* Stage modal */}
      <Modal
        open={stageOpen}
        title={`Изменить этап для ${n} сделок`}
        onClose={closeStageModal}
        width="sm"
        footer={
          <>
            <button className="btn-ghost" onClick={closeStageModal}>Отмена</button>
            <button
              className="btn-primary disabled:opacity-50"
              disabled={!stageId || !lostReasonReady || submitting}
              onClick={() => runBulk("change_stage", buildStagePayload())}
            >
              {submitting ? "Сохранение…" : "Сохранить"}
            </button>
          </>
        }
      >
        <div className="space-y-3">
          {error && <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>}
          <div>
            <label className="label">Этап</label>
            <select
              className="input"
              value={stageId}
              onChange={(e) => {
                setStageId(e.target.value);
                // Сброс причины при смене этапа.
                setLostReasonId("");
                setLostReasonText("");
              }}
            >
              <option value="">— выбрать —</option>
              {stages.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </select>
          </div>

          {/* При переводе в «Проиграна» бэк требует причину — показываем выбор. */}
          {stageIsLost && (
            <>
              {!usePresets && (
                <div>
                  <label className="label">
                    Причина проигрыша <span className="text-danger">*</span>
                  </label>
                  <div className="flex flex-wrap gap-2">
                    {registryReasons.map((item) => (
                      <button
                        key={item.id}
                        type="button"
                        onClick={() =>
                          setLostReasonId((prev) => (prev === String(item.id) ? "" : String(item.id)))
                        }
                        className={`px-2.5 py-1 text-xs rounded-full border transition-colors ${
                          lostReasonId === String(item.id)
                            ? "bg-primary text-white border-primary"
                            : "border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:border-primary hover:text-primary dark:hover:text-primary"
                        }`}
                      >
                        {item.name}
                      </button>
                    ))}
                  </div>
                </div>
              )}
              <div>
                <label className="label">
                  {lostReasonId !== "" ? "Дополнительный комментарий" : (
                    <>Комментарий <span className="text-danger">*</span></>
                  )}
                </label>
                <textarea
                  className="input min-h-[60px]"
                  placeholder={
                    lostReasonId !== ""
                      ? "Можно добавить детали (необязательно)…"
                      : "Опишите причину проигрыша…"
                  }
                  value={lostReasonText}
                  onChange={(e) => setLostReasonText(e.target.value)}
                />
              </div>
            </>
          )}
        </div>
      </Modal>

      {/* Tags modal */}
      <Modal
        open={tagsOpen}
        title={`Редактировать теги для ${n} сделок`}
        onClose={() => setTagsOpen(false)}
        width="sm"
        footer={
          <>
            <button className="btn-ghost" onClick={() => setTagsOpen(false)}>Отмена</button>
            <button
              className="btn-primary disabled:opacity-50"
              disabled={submitting}
              onClick={() => runBulk("set_tags", { tags: parseTags(), mode: tagMode })}
            >
              {submitting ? "Сохранение…" : "Применить"}
            </button>
          </>
        }
      >
        <div className="space-y-3">
          {error && <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>}
          <div>
            <label className="label">Режим</label>
            <div className="flex gap-2 text-sm">
              {(["add", "replace", "remove"] as TagMode[]).map((m) => (
                <label key={m} className="flex items-center gap-1.5 cursor-pointer">
                  <input
                    type="radio"
                    name="tagMode"
                    checked={tagMode === m}
                    onChange={() => setTagMode(m)}
                  />
                  {m === "add" ? "Добавить" : m === "replace" ? "Заменить" : "Удалить"}
                </label>
              ))}
            </div>
          </div>
          <div>
            <label className="label">Теги (через запятую)</label>
            <input
              className="input"
              placeholder="тег1, тег2, тег3"
              value={tagsInput}
              onChange={(e) => setTagsInput(e.target.value)}
            />
          </div>
        </div>
      </Modal>

      {/* Delete confirm */}
      {canDelete && (
        <Modal
          open={deleteOpen}
          title={`Удалить ${n} сделок?`}
          onClose={() => setDeleteOpen(false)}
          width="sm"
          footer={
            <>
              <button className="btn-ghost" onClick={() => setDeleteOpen(false)}>Отмена</button>
              <button
                className="btn-primary text-danger disabled:opacity-50"
                disabled={submitting}
                onClick={() => runBulk("delete", {})}
              >
                {submitting ? "Удаление…" : "Удалить"}
              </button>
            </>
          }
        >
          <p className="text-sm text-danger">
            Это действие нельзя отменить. Все {n} сделок будут удалены.
          </p>
        </Modal>
      )}
    </>
  );
}
