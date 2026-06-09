"use client";

import { useEffect, useMemo, useState } from "react";
import useSWR from "swr";
import { useRouter, useSearchParams } from "next/navigation";
import { PageHeader } from "@/components/PageHeader";
import { Modal } from "@/components/Modal";
import { EmptyState } from "@/components/EmptyState";
import { VisualCanvas } from "@/components/Pipelines/VisualCanvas";
import { SourcesPanel } from "@/components/PipelineSettings/SourcesPanel";
import { StageSettingsDrawer } from "@/components/PipelineSettings/StageSettingsDrawer";
import { LostReasonsAdmin } from "@/components/PipelineSettings/LostReasonsAdmin";
import { MeetingQuestionAdmin } from "@/components/PipelineSettings/MeetingQuestionAdmin";
import { StageTasksAdmin } from "@/components/PipelineSettings/StageTasksAdmin";
import { DealCardFieldsConfig } from "@/components/PipelineSettings/DealCardFieldsConfig";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError, fetcher } from "@/lib/api";
import type { Pipeline, PipelineStage } from "@/lib/types";

type RegistryModal = "lost-reasons" | "meeting-questions" | "stage-tasks" | null;
type SettingsTab = "structure" | "card-fields";

export default function PipelinesAdminPage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { toast } = useToast();

  const { data: allPipelines, mutate: mPipes } = useSWR<Pipeline[]>("/pipelines", fetcher);

  // Выбранная воронка из query param
  const pidFromQuery = searchParams.get("pipeline_id");
  const [pid, setPid] = useState<number | null>(
    pidFromQuery ? Number(pidFromQuery) : null
  );

  // Дефолтная воронка при первой загрузке
  const defaultPipeline = useMemo(() => {
    if (!allPipelines || allPipelines.length === 0) return null;
    if (pidFromQuery) {
      const found = allPipelines.find((p) => p.id === Number(pidFromQuery));
      return found ?? allPipelines[0];
    }
    return allPipelines.find((p) => p.kind === "sales") ?? allPipelines[0];
  }, [allPipelines, pidFromQuery]);

  useEffect(() => {
    if (pid == null && defaultPipeline) {
      setPid(defaultPipeline.id);
      // Синхронизируем URL без навигации
      const params = new URLSearchParams(searchParams.toString());
      params.set("pipeline_id", String(defaultPipeline.id));
      router.replace(`/admin/pipelines?${params.toString()}`, { scroll: false });
    }
  }, [defaultPipeline, pid, router, searchParams]);

  function selectPipeline(id: number) {
    setPid(id);
    setSelectedStage(null);
    const params = new URLSearchParams(searchParams.toString());
    params.set("pipeline_id", String(id));
    router.replace(`/admin/pipelines?${params.toString()}`, { scroll: false });
  }

  const { mutate: mutateStages } = useSWR<PipelineStage[]>(
    pid ? `/pipelines/${pid}/stages` : null,
    fetcher
  );

  const selectedPipeline = allPipelines?.find((p) => p.id === pid) ?? null;

  // ── Создание воронки ────────────────────────────────────────────────────────
  const [newPipeModal, setNewPipeModal] = useState(false);
  const [newPipeName, setNewPipeName] = useState("");
  const [newPipeKind, setNewPipeKind] = useState("sales");
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);

  async function createPipeline() {
    if (!newPipeName.trim()) return;
    setCreating(true);
    setCreateError(null);
    try {
      const p = await api<Pipeline>("/pipelines", {
        method: "POST",
        body: { name: newPipeName.trim(), kind: newPipeKind },
      });
      await mPipes();
      setNewPipeName("");
      setNewPipeModal(false);
      selectPipeline(p.id);
      toast.success("Воронка создана");
    } catch (err) {
      const msg = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось создать воронку";
      setCreateError(msg);
      toast.error(msg);
    } finally {
      setCreating(false);
    }
  }

  // ── Переименование воронки ─────────────────────────────────────────────────
  const [renameModal, setRenameModal] = useState(false);
  const [renameName, setRenameName] = useState("");
  const [renaming, setRenaming] = useState(false);
  const [renameError, setRenameError] = useState<string | null>(null);

  function openRename() {
    setRenameName(selectedPipeline?.name ?? "");
    setRenameError(null);
    setRenameModal(true);
  }

  async function renamePipeline() {
    if (!pid || !renameName.trim()) return;
    setRenaming(true);
    setRenameError(null);
    try {
      await api(`/pipelines/${pid}`, {
        method: "PATCH",
        body: { name: renameName.trim() },
      });
      await mPipes();
      setRenameModal(false);
      toast.success("Воронка переименована");
    } catch (err) {
      const msg = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось переименовать";
      setRenameError(msg);
      toast.error(msg);
    } finally {
      setRenaming(false);
    }
  }

  // ── Удаление воронки ────────────────────────────────────────────────────────
  const [delPipeConfirm, setDelPipeConfirm] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [delError, setDelError] = useState<string | null>(null);

  async function deletePipeline() {
    if (!pid) return;
    setDeleting(true);
    setDelError(null);
    try {
      await api(`/pipelines/${pid}`, { method: "DELETE" });
      await mPipes();
      setPid(null);
      setDelPipeConfirm(false);
      toast.success("Воронка удалена");
    } catch (err) {
      const msg = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось удалить воронку";
      setDelError(msg);
      toast.error(msg);
    } finally {
      setDeleting(false);
    }
  }

  // ── Stage settings drawer ────────────────────────────────────────────────────
  const [selectedStage, setSelectedStage] = useState<PipelineStage | null>(null);
  const [registryModal, setRegistryModal] = useState<RegistryModal>(null);
  const [tab, setTab] = useState<SettingsTab>("structure");

  // Close drawer when pipeline changes
  useEffect(() => {
    setSelectedStage(null);
  }, [pid]);

  function handleStageSaved(_updated: PipelineStage) {
    setSelectedStage(null);
    void mutateStages();
  }

  return (
    <div className="flex flex-col h-full">
      <PageHeader
        title="Конструктор воронок"
        description="Управление воронками: структура этапов, источники, переходы и реестры"
        actions={
          <div className="flex items-center gap-2">
            {/* Pipeline selector */}
            {(allPipelines?.length ?? 0) > 0 && (
              <select
                className="input text-sm py-1.5 w-52"
                value={pid ?? ""}
                onChange={(e) => selectPipeline(Number(e.target.value))}
              >
                {(allPipelines ?? []).map((p) => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </select>
            )}

            {/* Rename pipeline */}
            {pid && (
              <button
                className="btn-ghost text-sm"
                title="Переименовать воронку"
                onClick={openRename}
              >
                <i className="bi bi-pencil" />
              </button>
            )}

            {/* Delete pipeline */}
            {pid && (
              <button
                className="btn-ghost text-sm text-danger"
                title="Удалить воронку"
                onClick={() => { setDelError(null); setDelPipeConfirm(true); }}
              >
                <i className="bi bi-trash" />
              </button>
            )}

            {/* Create pipeline */}
            <button
              className="btn-primary text-sm"
              onClick={() => { setNewPipeName(""); setCreateError(null); setNewPipeModal(true); }}
            >
              <i className="bi bi-plus-lg mr-1" />
              Воронка
            </button>
          </div>
        }
      />

      {/* Tab switcher */}
      <div className="flex items-center gap-0 px-4 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shrink-0">
        {([
          { value: "structure" as const, label: "Структура", icon: "bi-diagram-3" },
          { value: "card-fields" as const, label: "Поля карточки", icon: "bi-card-list" },
        ] as const).map((t) => (
          <button
            key={t.value}
            onClick={() => setTab(t.value)}
            className={
              "flex items-center gap-1.5 px-3 py-2.5 text-sm border-b-2 -mb-px transition-colors " +
              (tab === t.value
                ? "border-primary text-primary font-medium"
                : "border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200")
            }
          >
            <i className={`bi ${t.icon}`} />
            {t.label}
          </button>
        ))}
      </div>

      {!pid ? (
        <div className="flex-1 flex flex-col items-center justify-center">
          {allPipelines === undefined ? (
            /* Skeleton while pipelines are loading */
            <div className="flex flex-col items-center gap-3">
              <div className="w-12 h-12 rounded-full bg-gray-200 dark:bg-gray-700 animate-pulse" />
              <div className="h-3 w-40 bg-gray-200 dark:bg-gray-700 rounded animate-pulse" />
            </div>
          ) : (
            <EmptyState
              icon="bi-diagram-3"
              title="Воронок пока нет"
              description="Создайте первую воронку — настройте этапы, источники и автоматизации"
              cta={
                <button
                  className="btn-primary"
                  onClick={() => { setNewPipeName(""); setCreateError(null); setNewPipeModal(true); }}
                >
                  <i className="bi bi-plus-lg mr-1" />
                  Создать воронку
                </button>
              }
            />
          )}
        </div>
      ) : tab === "card-fields" ? (
        <div className="flex-1 overflow-y-auto">
          <DealCardFieldsConfig pipelineId={pid} />
        </div>
      ) : (
        <div className="flex flex-1 overflow-hidden relative">
          {/* Left: Sources panel */}
          <SourcesPanel
            pipelineId={pid}
            onOpenLostReasons={() => setRegistryModal("lost-reasons")}
            onOpenMeetingQuestions={() => setRegistryModal("meeting-questions")}
            onOpenStageTasks={() => setRegistryModal("stage-tasks")}
          />

          {/* Center: Visual canvas (шестерня → StageSettingsDrawer) */}
          <div className="flex-1 overflow-hidden">
            <VisualCanvas
              pipelineId={pid}
              onStageSettingsClick={(stage) => setSelectedStage(stage)}
              hideSourcesSidebar
            />
          </div>

          {/* Right: Stage settings drawer */}
          {selectedStage && pid && (
            <StageSettingsDrawer
              stage={selectedStage}
              pipelineId={pid}
              onClose={() => setSelectedStage(null)}
              onSaved={handleStageSaved}
            />
          )}
        </div>
      )}

      {/* Registry modals */}
      {registryModal === "lost-reasons" && (
        <Modal
          open
          title="Причины отказа"
          onClose={() => setRegistryModal(null)}
          width="md"
        >
          <LostReasonsAdmin />
        </Modal>
      )}

      {registryModal === "meeting-questions" && (
        <Modal
          open
          title="Вопросы встречи"
          onClose={() => setRegistryModal(null)}
          width="lg"
        >
          <MeetingQuestionAdmin />
        </Modal>
      )}

      {registryModal === "stage-tasks" && pid && (
        <Modal
          open
          title="Задачи этапов"
          onClose={() => setRegistryModal(null)}
          width="md"
        >
          <StageTasksAdmin pipelineId={pid} />
        </Modal>
      )}

      {/* Create pipeline modal */}
      {newPipeModal && (
        <Modal
          open
          title="Новая воронка"
          onClose={() => setNewPipeModal(false)}
          width="sm"
          footer={
            <>
              <button className="btn-ghost" onClick={() => setNewPipeModal(false)}>Отмена</button>
              <button
                className="btn-primary disabled:opacity-50"
                disabled={creating || !newPipeName.trim()}
                onClick={createPipeline}
              >
                {creating ? "Создание…" : "Создать"}
              </button>
            </>
          }
        >
          <div className="space-y-4">
            {createError && (
              <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{createError}</div>
            )}
            <div>
              <label className="label">Название <span className="text-danger">*</span></label>
              <input
                className="input"
                autoFocus
                placeholder="Напр.: Продажи, Партнёры"
                value={newPipeName}
                onChange={(e) => setNewPipeName(e.target.value)}
                onKeyDown={(e) => { if (e.key === "Enter") void createPipeline(); }}
              />
            </div>
            <div>
              <label className="label">Тип воронки</label>
              <select
                className="input"
                value={newPipeKind}
                onChange={(e) => setNewPipeKind(e.target.value)}
              >
                <option value="sales">Продажи (Sales)</option>
                <option value="lifecycle">Жизненный цикл (CS)</option>
                <option value="renewal">Продление (Renewal)</option>
                <option value="custom">Другой</option>
              </select>
            </div>
          </div>
        </Modal>
      )}

      {/* Rename pipeline modal */}
      {renameModal && (
        <Modal
          open
          title="Переименовать воронку"
          onClose={() => setRenameModal(false)}
          width="sm"
          footer={
            <>
              <button className="btn-ghost" onClick={() => setRenameModal(false)}>Отмена</button>
              <button
                className="btn-primary disabled:opacity-50"
                disabled={renaming || !renameName.trim()}
                onClick={renamePipeline}
              >
                {renaming ? "Сохранение…" : "Сохранить"}
              </button>
            </>
          }
        >
          <div className="space-y-3">
            {renameError && (
              <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{renameError}</div>
            )}
            <div>
              <label className="label">Новое название</label>
              <input
                className="input"
                autoFocus
                value={renameName}
                onChange={(e) => setRenameName(e.target.value)}
                onKeyDown={(e) => { if (e.key === "Enter") void renamePipeline(); }}
              />
            </div>
          </div>
        </Modal>
      )}

      {/* Delete pipeline confirm modal */}
      {delPipeConfirm && (
        <Modal
          open
          title="Удалить воронку"
          onClose={() => setDelPipeConfirm(false)}
          width="sm"
          footer={
            <>
              <button className="btn-ghost" onClick={() => setDelPipeConfirm(false)}>Отмена</button>
              <button
                className="btn-primary bg-danger border-danger hover:bg-danger/90 disabled:opacity-50"
                disabled={deleting}
                onClick={deletePipeline}
              >
                {deleting ? "Удаление…" : "Удалить"}
              </button>
            </>
          }
        >
          <div className="space-y-3">
            {delError && (
              <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{delError}</div>
            )}
            <p className="text-sm text-gray-700 dark:text-gray-300">
              Вы уверены, что хотите удалить воронку{" "}
              <span className="font-semibold">«{selectedPipeline?.name}»</span>?
              Это действие необратимо — все этапы будут удалены.
            </p>
          </div>
        </Modal>
      )}
    </div>
  );
}
