"use client";

import { useState, useEffect } from "react";
import useSWR from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import { UserSelect } from "@/components/UserSelect";
import { Modal } from "@/components/Modal";
import type {
  PipelineStage,
  Pipeline,
  PipelineTransition,
  Department,
  TaskCategory,
} from "@/lib/types";

const STAGE_FEATURE_OPTIONS: { value: string; label: string; icon: string }[] = [
  { value: "send_presentation", label: "Отправка презентации", icon: "bi-file-earmark-slides" },
  { value: "meeting_report", label: "Отчёт по встрече", icon: "bi-clipboard2-check" },
  { value: "generate_document", label: "Генерация договора", icon: "bi-file-earmark-text" },
];

interface StageSettingsDrawerProps {
  stage: PipelineStage;
  pipelineId: number;
  onClose: () => void;
  onSaved: (updated: PipelineStage) => void;
}

export function StageSettingsDrawer({
  stage,
  pipelineId,
  onClose,
  onSaved,
}: StageSettingsDrawerProps) {
  // ── form state ───────────────────────────────────────────────────────────
  const [name, setName] = useState(stage.name);
  const [color, setColor] = useState(stage.color ?? "#2B4987");
  const [code, setCode] = useState(stage.code ?? "");
  const [description, setDescription] = useState(stage.description ?? "");
  const [slaHours, setSlaHours] = useState(stage.sla_hours ?? 0);
  const [isWon, setIsWon] = useState(stage.is_won);
  const [isLost, setIsLost] = useState(stage.is_lost);
  const [isActive, setIsActive] = useState(stage.is_active);
  const [hiddenByDefault, setHiddenByDefault] = useState(stage.hidden_by_default ?? false);
  const [wonGate, setWonGate] = useState(stage.won_gate ?? false);
  const [stageFeatures, setStageFeatures] = useState<string[]>(stage.stage_features ?? []);
  const [allowedTaskCategoryIds, setAllowedTaskCategoryIds] = useState<number[]>(
    stage.allowed_task_category_ids ?? []
  );
  const [visibleDeptIds, setVisibleDeptIds] = useState<number[]>(
    stage.visible_department_ids ?? []
  );
  const [visibleUserIdsStr, setVisibleUserIdsStr] = useState<string>(
    (stage.visible_user_ids ?? []).join(",")
  );

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<"main" | "visibility" | "features" | "gate" | "transitions">("main");
  const [transitionModalOpen, setTransitionModalOpen] = useState(false);
  const [editingTransition, setEditingTransition] = useState<PipelineTransition | null>(null);

  // ── remote data ──────────────────────────────────────────────────────────
  const { data: departments } = useSWR<Department[]>("/departments", fetcher);
  const { data: taskCategories } = useSWR<TaskCategory[]>("/task-categories", fetcher);
  const { data: transitions, mutate: mutateTransitions } = useSWR<PipelineTransition[]>(
    `/pipelines/${pipelineId}/transitions`,
    fetcher
  );
  const { data: allPipelines } = useSWR<Pipeline[]>("/pipelines", fetcher);
  const { data: substages, mutate: mutateSubstages } = useSWR<PipelineStage[]>(
    `/pipelines/${pipelineId}/stages`,
    fetcher
  );

  const thisTransitions = (transitions ?? []).filter((t) => t.from_stage_id === stage.id);
  const childStages = (substages ?? []).filter((s) => s.parent_stage_id === stage.id);

  // Parse visible user ids
  const visibleUserIds = visibleUserIdsStr
    .split(",")
    .map((s) => s.trim())
    .filter((s) => s !== "")
    .map(Number)
    .filter((n) => !isNaN(n));

  function toggleFeature(feat: string) {
    setStageFeatures((prev) =>
      prev.includes(feat) ? prev.filter((f) => f !== feat) : [...prev, feat]
    );
  }

  function toggleDept(id: number) {
    setVisibleDeptIds((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
    );
  }

  function toggleTaskCategory(id: number) {
    setAllowedTaskCategoryIds((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
    );
  }

  async function handleSave() {
    if (!name.trim()) {
      setError("Название обязательно");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      const updated = await api<PipelineStage>(`/pipelines/${pipelineId}/stages/${stage.id}`, {
        method: "PATCH",
        body: {
          name: name.trim(),
          color: color || null,
          code: code.trim() || null,
          description: description.trim() || null,
          sla_hours: slaHours || null,
          is_won: isWon,
          is_lost: isLost,
          is_active: isActive,
          hidden_by_default: hiddenByDefault,
          won_gate: wonGate,
          stage_features: stageFeatures,
          allowed_task_category_ids: allowedTaskCategoryIds,
          visible_department_ids: visibleDeptIds,
          visible_user_ids: visibleUserIds,
        },
      });
      onSaved(updated);
      onClose();
    } catch (err) {
      setError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось сохранить"
      );
    } finally {
      setSaving(false);
    }
  }

  async function deleteTransition(id: number) {
    try {
      await api(`/pipelines/${pipelineId}/transitions/${id}`, { method: "DELETE" });
      await mutateTransitions();
    } catch {
      // ignore
    }
  }

  async function createSubstage(subname: string) {
    try {
      await api<PipelineStage>(`/pipelines/${pipelineId}/stages`, {
        method: "POST",
        body: {
          name: subname,
          parent_stage_id: stage.id,
          sort_order: childStages.length,
        },
      });
      await mutateSubstages();
    } catch {
      // ignore
    }
  }

  async function deleteSubstage(id: number) {
    if (!confirm("Удалить подстатус?")) return;
    try {
      await api(`/pipelines/${pipelineId}/stages/${id}`, { method: "DELETE" });
      await mutateSubstages();
    } catch {
      // ignore
    }
  }

  const TABS = [
    { key: "main", label: "Основные" },
    { key: "visibility", label: "Видимость" },
    { key: "features", label: "Фичи" },
    { key: "gate", label: "Гейт" },
    { key: "transitions", label: "Переходы" },
  ] as const;

  return (
    <>
      {/* Drawer overlay */}
      <div
        className="fixed inset-0 z-30 bg-black/20"
        onClick={onClose}
      />

      {/* Drawer panel */}
      <div className="fixed top-0 right-0 h-full w-[480px] z-40 bg-white dark:bg-gray-800 shadow-2xl flex flex-col overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b border-gray-200 dark:border-gray-700 shrink-0">
          <div className="min-w-0">
            <h2 className="text-base font-semibold text-gray-800 dark:text-gray-100 truncate">
              {stage.name}
            </h2>
            <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Настройки этапа</p>
          </div>
          <button
            className="p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 shrink-0"
            onClick={onClose}
          >
            <i className="bi bi-x-lg" />
          </button>
        </div>

        {/* Tabs */}
        <div className="flex gap-0 border-b border-gray-200 dark:border-gray-700 overflow-x-auto shrink-0">
          {TABS.map((tab) => (
            <button
              key={tab.key}
              onClick={() => setActiveTab(tab.key)}
              className={`px-4 py-2.5 text-sm font-medium whitespace-nowrap border-b-2 transition-colors ${
                activeTab === tab.key
                  ? "border-primary text-primary"
                  : "border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300"
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto px-5 py-4">
          {/* ── Main tab ───────────────────────────────────────────────────── */}
          {activeTab === "main" && (
            <div className="space-y-4">
              <div>
                <label className="label">Название <span className="text-danger">*</span></label>
                <input
                  className="input"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="Название этапа"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="label">Цвет</label>
                  <div className="flex gap-2 items-center">
                    <input
                      type="color"
                      className="h-9 w-10 rounded border border-gray-300 cursor-pointer"
                      value={color}
                      onChange={(e) => setColor(e.target.value)}
                    />
                    <input
                      className="input flex-1"
                      value={color}
                      onChange={(e) => setColor(e.target.value)}
                      placeholder="#2B4987"
                    />
                  </div>
                </div>
                <div>
                  <label className="label">Код</label>
                  <input
                    className="input"
                    value={code}
                    onChange={(e) => setCode(e.target.value)}
                    placeholder="B0 / A1"
                  />
                </div>
              </div>

              <div>
                <label className="label">Описание</label>
                <textarea
                  className="input"
                  rows={3}
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  placeholder="Что должно произойти на этом этапе…"
                />
              </div>

              <div>
                <label className="label">SLA (часов)</label>
                <div className="flex items-center gap-2">
                  <input
                    className="input w-28"
                    type="number"
                    min={0}
                    value={slaHours}
                    onChange={(e) => setSlaHours(Math.max(0, Number(e.target.value) || 0))}
                  />
                  <span className="text-sm text-gray-500">0 = без SLA</span>
                </div>
              </div>

              <div>
                <label className="label">Флаги</label>
                <div className="space-y-2 text-sm">
                  {[
                    { label: "Финальный успешный", value: isWon, set: setIsWon, icon: "bi-trophy text-success" },
                    { label: "Финальный проигрышный", value: isLost, set: setIsLost, icon: "bi-x-circle text-danger" },
                    { label: "Скрыт по умолчанию", value: hiddenByDefault, set: setHiddenByDefault, icon: "bi-eye-slash text-gray-400" },
                    { label: "Этап активен", value: isActive, set: setIsActive, icon: "bi-toggle-on text-success" },
                  ].map((flag) => (
                    <label key={flag.label} className="flex items-center gap-2 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={flag.value}
                        onChange={(e) => flag.set(e.target.checked)}
                      />
                      <i className={`bi ${flag.icon} text-xs`} />
                      <span>{flag.label}</span>
                    </label>
                  ))}
                </div>
              </div>

              {/* Substages (only for is_won stages) */}
              {isWon && (
                <div>
                  <label className="label">Подстатусы (для «Успех»)</label>
                  <SubstatusList
                    substages={childStages}
                    onCreate={createSubstage}
                    onDelete={deleteSubstage}
                  />
                </div>
              )}
            </div>
          )}

          {/* ── Visibility tab ──────────────────────────────────────────────── */}
          {activeTab === "visibility" && (
            <div className="space-y-5">
              <div>
                <label className="label">Видимость по отделам</label>
                <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">
                  Если не выбрано — этап виден всем. Выберите отделы, которым доступен этот этап.
                </p>
                {!departments && (
                  <div className="text-sm text-gray-400">Загрузка…</div>
                )}
                {departments && departments.length === 0 && (
                  <div className="text-sm text-gray-400">Отделы не настроены</div>
                )}
                {departments && departments.length > 0 && (
                  <div className="space-y-1.5 max-h-48 overflow-y-auto">
                    {departments.map((dept) => (
                      <label
                        key={dept.id}
                        className="flex items-center gap-2 cursor-pointer text-sm rounded px-2 py-1 hover:bg-gray-50 dark:hover:bg-gray-700"
                      >
                        <input
                          type="checkbox"
                          checked={visibleDeptIds.includes(dept.id)}
                          onChange={() => toggleDept(dept.id)}
                        />
                        <span className="text-gray-800 dark:text-gray-200">{dept.name}</span>
                      </label>
                    ))}
                  </div>
                )}
              </div>

              <div>
                <label className="label">Видимость по пользователям</label>
                <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">
                  Дополнительно — конкретные пользователи вне отделов.
                </p>
                <UserMultiSelect
                  selectedIds={visibleUserIds}
                  onChange={(ids) => setVisibleUserIdsStr(ids.join(","))}
                />
              </div>
            </div>
          )}

          {/* ── Features tab ───────────────────────────────────────────────── */}
          {activeTab === "features" && (
            <div className="space-y-5">
              <div>
                <label className="label">Специальные действия этапа</label>
                <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">
                  Кнопки-действия, доступные менеджеру при работе со сделкой на этом этапе.
                </p>
                <div className="space-y-2">
                  {STAGE_FEATURE_OPTIONS.map((feat) => (
                    <label
                      key={feat.value}
                      className="flex items-center gap-3 cursor-pointer rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-700/50"
                    >
                      <input
                        type="checkbox"
                        checked={stageFeatures.includes(feat.value)}
                        onChange={() => toggleFeature(feat.value)}
                        className="w-4 h-4 accent-primary"
                      />
                      <i className={`bi ${feat.icon} text-primary`} />
                      <span className="text-sm text-gray-800 dark:text-gray-200">{feat.label}</span>
                    </label>
                  ))}
                </div>
              </div>

              <div>
                <label className="label">Разрешённые категории задач</label>
                <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">
                  Какие категории задач может создавать менеджер на этом этапе.
                  Если не выбрано — доступны все.
                </p>
                {!taskCategories && <div className="text-sm text-gray-400">Загрузка…</div>}
                {taskCategories && taskCategories.length === 0 && (
                  <div className="text-sm text-gray-400">
                    Категорий нет.{" "}
                    <a href="/admin/task-categories" className="text-primary hover:underline">
                      Создать →
                    </a>
                  </div>
                )}
                {taskCategories && taskCategories.length > 0 && (
                  <div className="space-y-1.5 max-h-48 overflow-y-auto">
                    {taskCategories.map((cat) => (
                      <label
                        key={cat.id}
                        className="flex items-center gap-2 cursor-pointer text-sm rounded px-2 py-1 hover:bg-gray-50 dark:hover:bg-gray-700"
                      >
                        <input
                          type="checkbox"
                          checked={allowedTaskCategoryIds.includes(cat.id)}
                          onChange={() => toggleTaskCategory(cat.id)}
                        />
                        <span
                          className="w-2 h-2 rounded-full shrink-0"
                          style={{ backgroundColor: cat.color ?? "#6B7A99" }}
                        />
                        <span className="text-gray-800 dark:text-gray-200">{cat.name}</span>
                      </label>
                    ))}
                  </div>
                )}
              </div>
            </div>
          )}

          {/* ── Gate tab ───────────────────────────────────────────────────── */}
          {activeTab === "gate" && (
            <div className="space-y-4">
              <div>
                <label className="label">Гейт успеха (Won Gate)</label>
                <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">
                  Если включён — перевод сделки в этот этап заблокируется до выполнения условий.
                </p>
                <label className="flex items-center gap-3 cursor-pointer rounded-lg border border-gray-200 dark:border-gray-700 px-4 py-3">
                  <input
                    type="checkbox"
                    checked={wonGate}
                    onChange={(e) => setWonGate(e.target.checked)}
                    className="w-4 h-4 accent-primary"
                  />
                  <div>
                    <div className="text-sm font-medium text-gray-800 dark:text-gray-200">
                      Требовать выполнение условий
                    </div>
                    <div className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                      Система проверит наличие скана договора или оплаты
                    </div>
                  </div>
                </label>
              </div>

              {wonGate && (
                <div className="p-3 bg-info/5 dark:bg-info/10 border border-info/30 rounded-lg">
                  <div className="text-xs font-medium text-info mb-2">Что проверяется:</div>
                  <ul className="space-y-1 text-xs text-gray-700 dark:text-gray-300">
                    <li className="flex items-center gap-1.5">
                      <i className="bi bi-file-earmark-check text-success" />
                      Подписанный скан договора (вложение kind=signed_scan)
                    </li>
                    <li className="flex items-center gap-1.5">
                      <i className="bi bi-credit-card text-success" />
                      Отметка об оплате (mark-paid)
                    </li>
                  </ul>
                  <p className="text-xs text-gray-500 dark:text-gray-400 mt-2">
                    Достаточно выполнить одно из условий.
                  </p>
                </div>
              )}
            </div>
          )}

          {/* ── Transitions tab ───────────────────────────────────────────── */}
          {activeTab === "transitions" && (
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <div>
                  <label className="label mb-0">Межворонночные переходы</label>
                  <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                    Переводы сделки в другую воронку с этого этапа.
                  </p>
                </div>
                <button
                  className="btn-primary text-xs"
                  onClick={() => { setEditingTransition(null); setTransitionModalOpen(true); }}
                >
                  <i className="bi bi-plus mr-1" />Добавить
                </button>
              </div>

              {thisTransitions.length === 0 && (
                <div className="text-sm text-gray-400 py-4 text-center">
                  <i className="bi bi-arrow-right-circle text-2xl block mb-2" />
                  Переходов нет
                </div>
              )}

              {thisTransitions.map((tr) => {
                const toPipeline = allPipelines?.find((p) => p.id === tr.to_pipeline_id);
                return (
                  <div
                    key={tr.id}
                    className="border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5"
                  >
                    <div className="flex items-center justify-between gap-2">
                      <div className="min-w-0">
                        <div className="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">
                          {tr.name || "Без названия"}
                        </div>
                        <div className="text-xs text-gray-500 dark:text-gray-400">
                          → {toPipeline?.name ?? `Воронка #${tr.to_pipeline_id}`}
                        </div>
                      </div>
                      <div className="flex items-center gap-1 shrink-0">
                        <button
                          className="btn-ghost text-xs p-1"
                          onClick={() => { setEditingTransition(tr); setTransitionModalOpen(true); }}
                        >
                          <i className="bi bi-pencil" />
                        </button>
                        <button
                          className="btn-ghost text-xs p-1 text-danger"
                          onClick={() => void deleteTransition(tr.id)}
                        >
                          <i className="bi bi-trash" />
                        </button>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          )}

          {error && (
            <div className="mt-4 text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>
          )}
        </div>

        {/* Footer */}
        <div className="px-5 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 flex items-center justify-end gap-2 shrink-0">
          <button className="btn-ghost" onClick={onClose}>Отмена</button>
          <button
            className="btn-primary disabled:opacity-50"
            onClick={handleSave}
            disabled={saving || !name.trim()}
          >
            {saving ? "Сохранение…" : "Сохранить"}
          </button>
        </div>
      </div>

      {/* Cross-pipeline transition modal */}
      {transitionModalOpen && (
        <CrossPipelineTransitionModal
          pipelineId={pipelineId}
          fromStageId={stage.id}
          transition={editingTransition}
          allPipelines={allPipelines ?? []}
          onClose={() => setTransitionModalOpen(false)}
          onSaved={() => { setTransitionModalOpen(false); void mutateTransitions(); }}
        />
      )}
    </>
  );
}

// ── UserMultiSelect ────────────────────────────────────────────────────────────
function UserMultiSelect({
  selectedIds,
  onChange,
}: {
  selectedIds: number[];
  onChange: (ids: number[]) => void;
}) {
  const { data: users } = useSWR("/users", fetcher);
  const list = (users ?? []) as { id: number; full_name: string }[];

  function toggle(id: number) {
    onChange(
      selectedIds.includes(id) ? selectedIds.filter((x) => x !== id) : [...selectedIds, id]
    );
  }

  return (
    <div className="space-y-1.5 max-h-48 overflow-y-auto">
      {list.map((u) => (
        <label
          key={u.id}
          className="flex items-center gap-2 cursor-pointer text-sm rounded px-2 py-1 hover:bg-gray-50 dark:hover:bg-gray-700"
        >
          <input
            type="checkbox"
            checked={selectedIds.includes(u.id)}
            onChange={() => toggle(u.id)}
          />
          <span className="text-gray-800 dark:text-gray-200">{u.full_name}</span>
        </label>
      ))}
      {list.length === 0 && <div className="text-sm text-gray-400">Загрузка…</div>}
    </div>
  );
}

// ── SubstatusList ─────────────────────────────────────────────────────────────
function SubstatusList({
  substages,
  onCreate,
  onDelete,
}: {
  substages: PipelineStage[];
  onCreate: (name: string) => Promise<void>;
  onDelete: (id: number) => Promise<void>;
}) {
  const [adding, setAdding] = useState(false);
  const [newName, setNewName] = useState("");
  const [creating, setCreating] = useState(false);

  async function handleCreate() {
    const trimmed = newName.trim();
    if (!trimmed) return;
    setCreating(true);
    try {
      await onCreate(trimmed);
      setNewName("");
      setAdding(false);
    } finally {
      setCreating(false);
    }
  }

  return (
    <div className="space-y-1.5">
      {substages.map((s) => (
        <div
          key={s.id}
          className="flex items-center gap-2 text-sm border border-gray-200 dark:border-gray-700 rounded px-2.5 py-1.5"
        >
          <span
            className="w-2 h-2 rounded-full shrink-0"
            style={{ backgroundColor: s.color ?? "#6B7A99" }}
          />
          <span className="flex-1 text-gray-800 dark:text-gray-200">{s.name}</span>
          <button
            className="text-danger hover:opacity-70 text-xs p-0.5"
            onClick={() => void onDelete(s.id)}
          >
            <i className="bi bi-trash" />
          </button>
        </div>
      ))}

      {adding ? (
        <div className="flex gap-2">
          <input
            autoFocus
            className="input text-sm flex-1"
            placeholder="Название подстатуса…"
            value={newName}
            disabled={creating}
            onChange={(e) => setNewName(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") void handleCreate();
              if (e.key === "Escape") { setAdding(false); setNewName(""); }
            }}
          />
          <button
            className="btn-primary text-xs px-2 disabled:opacity-50"
            disabled={creating || !newName.trim()}
            onClick={handleCreate}
          >
            {creating ? "…" : <i className="bi bi-check" />}
          </button>
          <button className="btn-ghost text-xs px-2" onClick={() => { setAdding(false); setNewName(""); }}>
            <i className="bi bi-x" />
          </button>
        </div>
      ) : (
        <button
          className="text-xs text-primary hover:underline flex items-center gap-1"
          onClick={() => setAdding(true)}
        >
          <i className="bi bi-plus" />Добавить подстатус
        </button>
      )}
    </div>
  );
}

// ── CrossPipelineTransitionModal ──────────────────────────────────────────────
function CrossPipelineTransitionModal({
  pipelineId,
  fromStageId,
  transition,
  allPipelines,
  onClose,
  onSaved,
}: {
  pipelineId: number;
  fromStageId: number;
  transition: PipelineTransition | null;
  allPipelines: Pipeline[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [name, setName] = useState(transition?.name ?? "");
  const [toPipelineId, setToPipelineId] = useState<number | "">(transition?.to_pipeline_id ?? "");
  const [toStageId, setToStageId] = useState<number | "">(transition?.to_stage_id ?? "");
  const [requireScan, setRequireScan] = useState(transition?.conditions.require_signed_scan ?? false);
  const [isActive, setIsActive] = useState(transition?.is_active ?? true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { data: toStages } = useSWR<PipelineStage[]>(
    toPipelineId ? `/pipelines/${toPipelineId}/stages` : null,
    fetcher
  );

  const otherPipelines = allPipelines.filter((p) => p.id !== pipelineId && p.is_active);

  async function handleSave() {
    if (!name.trim() || !toPipelineId || !toStageId) {
      setError("Заполните все обязательные поля");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      if (transition) {
        await api(`/pipelines/${pipelineId}/transitions/${transition.id}`, {
          method: "PATCH",
          body: {
            name: name.trim(),
            to_pipeline_id: toPipelineId,
            to_stage_id: toStageId,
            conditions: { require_signed_scan: requireScan },
            is_active: isActive,
          },
        });
      } else {
        await api(`/pipelines/${pipelineId}/transitions`, {
          method: "POST",
          body: {
            name: name.trim(),
            from_stage_id: fromStageId,
            to_pipeline_id: toPipelineId,
            to_stage_id: toStageId,
            conditions: { require_signed_scan: requireScan },
            is_active: isActive,
          },
        });
      }
      onSaved();
    } catch (err) {
      setError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Ошибка сохранения"
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open
      title={transition ? "Редактировать переход" : "Новый переход"}
      onClose={onClose}
      width="sm"
      footer={
        <>
          <button className="btn-ghost" onClick={onClose}>Отмена</button>
          <button
            className="btn-primary disabled:opacity-50"
            onClick={handleSave}
            disabled={saving}
          >
            {saving ? "Сохранение…" : "Сохранить"}
          </button>
        </>
      }
    >
      <div className="space-y-4">
        {error && (
          <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>
        )}

        <div>
          <label className="label">Название <span className="text-danger">*</span></label>
          <input
            className="input"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Напр.: «Перевести в CS-воронку»"
          />
        </div>

        <div>
          <label className="label">Целевая воронка <span className="text-danger">*</span></label>
          <select
            className="input"
            value={toPipelineId}
            onChange={(e) => { setToPipelineId(Number(e.target.value)); setToStageId(""); }}
          >
            <option value="">— выберите —</option>
            {otherPipelines.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </select>
        </div>

        <div>
          <label className="label">Целевой этап <span className="text-danger">*</span></label>
          <select
            className="input"
            value={toStageId}
            onChange={(e) => setToStageId(Number(e.target.value))}
            disabled={!toPipelineId}
          >
            <option value="">— выберите —</option>
            {(toStages ?? []).map((s) => (
              <option key={s.id} value={s.id}>{s.name}</option>
            ))}
          </select>
        </div>

        <div className="space-y-2">
          <label className="label">Условия</label>
          <label className="flex items-center gap-2 cursor-pointer text-sm">
            <input
              type="checkbox"
              checked={requireScan}
              onChange={(e) => setRequireScan(e.target.checked)}
            />
            <span>Требовать скан подписанного договора</span>
          </label>
          <label className="flex items-center gap-2 cursor-pointer text-sm">
            <input
              type="checkbox"
              checked={isActive}
              onChange={(e) => setIsActive(e.target.checked)}
            />
            <span>Переход активен</span>
          </label>
        </div>
      </div>
    </Modal>
  );
}
