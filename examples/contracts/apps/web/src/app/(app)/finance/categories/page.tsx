"use client";

import type React from "react";
import { useEffect, useState } from "react";
import useSWR, { mutate as globalMutate } from "swr";
import {
  DndContext,
  closestCenter,
  PointerSensor,
  KeyboardSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from "@dnd-kit/core";
import {
  SortableContext,
  verticalListSortingStrategy,
  arrayMove,
  useSortable,
  sortableKeyboardCoordinates,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { Modal } from "@/components/Modal";
import { FinTableSkeleton } from "@/components/Finance/FinTableSkeleton";
import { EmptyState } from "@/components/EmptyState";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import type { FinCashflowCategory, FinCashflowActivity, UserRole } from "@/lib/types";

const FINANCE_ROLES = ["accountant", "cfo", "director", "admin"] as const;
const MANAGE_ROLES: UserRole[] = ["accountant", "cfo", "admin"];

const ACTIVITY_LABELS: Record<FinCashflowActivity, string> = {
  operating: "Операционная",
  investing: "Инвестиционная",
  financing: "Финансовая",
};

const DIRECTION_LABELS: Record<string, string> = {
  income:  "Приток",
  expense: "Отток",
  inflow:  "Приток",
  outflow: "Отток",
  both:    "Обе стороны",
};

const ACTIVITY_OPTIONS: { value: FinCashflowActivity; label: string }[] = [
  { value: "operating",  label: "Операционная" },
  { value: "investing",  label: "Инвестиционная" },
  { value: "financing",  label: "Финансовая" },
];

const DIRECTION_OPTIONS = [
  { value: "inflow",  label: "Приток" },
  { value: "outflow", label: "Отток" },
];

// Skeleton: name, activity, direction, status, actions
const SKELETON_COLS = ["40%", "18%", "14%", "14%", "8%"];

function directionBadge(direction: string) {
  const isInflow = direction === "income" || direction === "inflow";
  return (
    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${isInflow ? "bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400" : "bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400"}`}>
      <i className={`bi ${isInflow ? "bi-arrow-down" : "bi-arrow-up"} text-[10px]`} />
      {DIRECTION_LABELS[direction] ?? direction}
    </span>
  );
}

// Иерархический отступ. Tailwind не поддерживает динамические pl-${n}, поэтому фиксированные.
function indentClass(level: number): string {
  switch (level) {
    case 1: return "pl-0";
    case 2: return "pl-4";
    case 3: return "pl-8";
    default: return "pl-12";
  }
}

function activityBadge(activity: FinCashflowActivity) {
  return (
    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-400">
      {ACTIVITY_LABELS[activity]}
    </span>
  );
}

interface SortableRowProps {
  cat: FinCashflowCategory;
  canManage: boolean;
  onEdit: (cat: FinCashflowCategory) => void;
  onDeactivate: (cat: FinCashflowCategory) => void;
}

function SortableRow({ cat, canManage, onEdit, onDeactivate }: SortableRowProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
    useSortable({ id: cat.id });

  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : cat.is_active ? 1 : 0.5,
    zIndex: isDragging ? 50 : undefined,
  };

  return (
    <tr
      ref={setNodeRef}
      style={style}
      className={`group border-b border-gray-100 dark:border-gray-800 transition-colors duration-100 hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06] ${isDragging ? "bg-primary/[0.05] dark:bg-primary/[0.08] shadow-lg" : ""}`}
    >
      {canManage && (
        <td className="px-2 py-2.5 w-8">
          <button
            type="button"
            {...attributes}
            {...listeners}
            tabIndex={-1}
            title="Перетащить для изменения порядка"
            className="cursor-grab active:cursor-grabbing p-1 text-gray-300 hover:text-gray-500 dark:text-gray-600 dark:hover:text-gray-400 transition-colors"
          >
            <i className="bi bi-grip-vertical" />
          </button>
        </td>
      )}
      <td className="px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300">
        <span className={`inline-flex items-center gap-1 ${indentClass(cat.level)}`}>
          {cat.level > 1 && <i className="bi bi-arrow-return-right text-gray-300 dark:text-gray-600 text-xs" />}
          {cat.name}
        </span>
      </td>
      <td className="px-4 py-2.5">{directionBadge(cat.direction)}</td>
      <td className="px-4 py-2.5">
        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${cat.is_active ? "bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400" : "bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-500"}`}>
          <span className="w-1.5 h-1.5 rounded-full bg-current opacity-70" />
          {cat.is_active ? "Активна" : "Неактивна"}
        </span>
      </td>
      {canManage && (
        <td className="px-4 py-2.5">
          <div className="opacity-0 group-hover:opacity-100 transition-opacity duration-100 flex items-center gap-1">
            <button
              className="p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-400 hover:text-primary dark:hover:text-blue-400 transition-colors"
              title="Редактировать"
              onClick={() => onEdit(cat)}
            >
              <i className="bi bi-pencil text-sm" />
            </button>
            <button
              className="p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-400 hover:text-warning transition-colors"
              title={cat.is_active ? "Деактивировать" : "Активировать"}
              onClick={() => onDeactivate(cat)}
            >
              <i className={`bi ${cat.is_active ? "bi-pause-circle" : "bi-play-circle"} text-sm`} />
            </button>
          </div>
        </td>
      )}
    </tr>
  );
}

interface CategoryFormState {
  name: string;
  code: string;
  activity: FinCashflowActivity;
  direction: string;
  parent_id: string;
  is_active: boolean;
}

const defaultForm = (): CategoryFormState => ({
  name: "",
  code: "",
  activity: "operating",
  direction: "inflow",
  parent_id: "",
  is_active: true,
});

interface CatSet {
  id: number;
  name: string;
  is_active: boolean;
}

function extractErrMsg(err: unknown): string {
  if (err instanceof ApiError) {
    const d = err.detail;
    if (typeof d === "object" && d !== null && "detail" in d) return String((d as Record<string, unknown>)["detail"]);
    if (typeof d === "string") return d;
  }
  return "Произошла ошибка";
}

export default function CategoriesPage() {
  const { user } = useMe();
  const { toast } = useToast();

  const [activityFilter, setActivityFilter] = useState("");
  const [directionFilter, setDirectionFilter] = useState("");
  const [modalOpen, setModalOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<FinCashflowCategory | null>(null);
  const [form, setForm] = useState<CategoryFormState>(defaultForm);
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const qs = new URLSearchParams();
  if (activityFilter) qs.set("activity", activityFilter);
  if (directionFilter) qs.set("direction", directionFilter);
  const swrKey = `/api/finance/categories?${qs.toString()}`;

  const { data: categories, isLoading, error } = useSWR<FinCashflowCategory[]>(swrKey, fetcher);
  const { data: catSets } = useSWR<CatSet[]>("/api/finance/cat-sets", fetcher);

  const canManage = user && MANAGE_ROLES.includes(user.role);

  // Локальное зеркало для оптимистичного DnD-reorder.
  const [items, setItems] = useState<FinCashflowCategory[]>([]);
  useEffect(() => {
    setItems(categories ?? []);
  }, [categories]);

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  // Группировка по виду деятельности (activity) — DnD ограничен рамками группы.
  const groups: { activity: FinCashflowActivity; rows: FinCashflowCategory[] }[] =
    ACTIVITY_OPTIONS.map((o) => ({
      activity: o.value,
      rows: items.filter((c) => c.activity === o.value),
    })).filter((g) => g.rows.length > 0);

  async function handleDragEnd(activity: FinCashflowActivity, event: DragEndEvent) {
    const { active, over } = event;
    if (!over || active.id === over.id) return;

    const groupRows = items.filter((c) => c.activity === activity);
    const oldIdx = groupRows.findIndex((c) => c.id === Number(active.id));
    const newIdx = groupRows.findIndex((c) => c.id === Number(over.id));
    if (oldIdx < 0 || newIdx < 0) return;

    const reorderedGroup = arrayMove(groupRows, oldIdx, newIdx);
    // Собираем новый общий список, сохраняя порядок остальных групп.
    const others = items.filter((c) => c.activity !== activity);
    const prevItems = items;
    setItems([...others, ...reorderedGroup]);

    const catSetId = reorderedGroup[0]?.cat_set_id;
    if (catSetId == null) return;
    try {
      await api(
        `/finance/categories/reorder?cat_set_id=${catSetId}&activity=${activity}`,
        {
          method: "PATCH",
          body: reorderedGroup.map((c, idx) => ({ id: c.id, sort_order: idx })),
        },
      );
      // Подтягиваем серверный порядок (фоном), без мерцания.
      void globalMutate(swrKey);
    } catch (err) {
      setItems(prevItems); // откат
      toast.error("Не удалось изменить порядок", extractErrMsg(err));
    }
  }

  function openCreate() {
    setEditTarget(null);
    setForm(defaultForm());
    setFormError(null);
    setModalOpen(true);
  }

  function openEdit(cat: FinCashflowCategory) {
    setEditTarget(cat);
    setForm({
      name: cat.name,
      code: cat.code,
      activity: cat.activity,
      direction: cat.direction,
      parent_id: cat.parent_id ? String(cat.parent_id) : "",
      is_active: cat.is_active,
    });
    setFormError(null);
    setModalOpen(true);
  }

  function closeModal() {
    setModalOpen(false);
    setEditTarget(null);
    setFormError(null);
  }

  async function handleSubmit() {
    if (!form.name.trim()) { setFormError("Укажите название"); return; }
    setSubmitting(true);
    setFormError(null);
    try {
      if (editTarget) {
        await api(`/finance/categories/${editTarget.id}`, {
          method: "PATCH",
          body: {
            name: form.name.trim(),
            activity: form.activity,
            direction: form.direction,
            parent_id: form.parent_id ? Number(form.parent_id) : null,
            is_active: form.is_active,
          },
        });
      } else {
        if (!form.code.trim()) { setFormError("Укажите код статьи"); setSubmitting(false); return; }
        const catSetId = catSets?.find((s) => s.is_active)?.id ?? catSets?.[0]?.id;
        if (!catSetId) { setFormError("Не найден набор статей ДДС (cat_set)"); setSubmitting(false); return; }
        const parentId = form.parent_id ? Number(form.parent_id) : null;
        const parent = parentId != null ? categories?.find((c) => c.id === parentId) : undefined;
        const level = parent ? Math.min(parent.level + 1, 3) : 1;
        await api("/finance/categories", {
          method: "POST",
          body: {
            cat_set_id: catSetId,
            code: form.code.trim(),
            name: form.name.trim(),
            level,
            activity: form.activity,
            direction: form.direction,
            parent_id: parentId,
          },
        });
      }
      await globalMutate(swrKey);
      toast.success(editTarget ? "Статья обновлена" : "Статья создана");
      closeModal();
    } catch (err) {
      const msg = extractErrMsg(err);
      setFormError(msg);
      toast.error("Ошибка сохранения", msg);
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDeactivate(cat: FinCashflowCategory) {
    if (!confirm(`${cat.is_active ? "Деактивировать" : "Активировать"} статью «${cat.name}»?`)) return;
    try {
      await api(`/finance/categories/${cat.id}`, { method: "PATCH", body: { is_active: !cat.is_active } });
      await globalMutate(swrKey);
      toast.success(cat.is_active ? "Статья деактивирована" : "Статья активирована");
    } catch (err) {
      toast.error("Ошибка", extractErrMsg(err));
    }
  }

  const parentOptions = categories?.filter((c) => !editTarget || c.id !== editTarget.id) ?? [];

  return (
    <RoleGate allowed={[...FINANCE_ROLES]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="Статьи ДДС"
          description="Справочник статей движения денежных средств"
          actions={
            canManage && (
              <button className="btn-primary" onClick={openCreate}>
                <i className="bi bi-plus mr-1" />
                Добавить статью
              </button>
            )
          }
        />

        <div className="p-6 flex flex-col gap-4">
          {/* Фильтр-бар */}
          <div className="card rounded-2xl shadow-elev-1 p-4">
            <div className="flex flex-wrap items-center gap-2">
              <select
                className="input text-sm"
                value={activityFilter}
                onChange={(e) => setActivityFilter(e.target.value)}
              >
                <option value="">Все виды деятельности</option>
                {ACTIVITY_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>{o.label}</option>
                ))}
              </select>
              <select
                className="input text-sm"
                value={directionFilter}
                onChange={(e) => setDirectionFilter(e.target.value)}
              >
                <option value="">Все направления</option>
                {DIRECTION_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>{o.label}</option>
                ))}
              </select>
            </div>
          </div>

          {canManage && (
            <p className="text-xs text-gray-400 dark:text-gray-500 -mt-1">
              <i className="bi bi-grip-vertical mr-1" />
              Перетаскивайте статьи за маркер слева, чтобы изменить порядок внутри вида деятельности.
            </p>
          )}

          {/* Состояния загрузки / ошибки / пусто */}
          {isLoading ? (
            <div className="card rounded-2xl overflow-hidden shadow-elev-1">
              <table className="w-full text-sm">
                <tbody>
                  <FinTableSkeleton rows={6} cols={SKELETON_COLS} />
                </tbody>
              </table>
            </div>
          ) : error ? (
            <div className="card rounded-2xl shadow-elev-1 px-4 py-6 text-center text-sm text-danger">
              <i className="bi bi-exclamation-triangle mr-2" />
              Не удалось загрузить статьи
            </div>
          ) : !groups.length ? (
            <div className="card rounded-2xl shadow-elev-1">
              <EmptyState
                icon="bi-tags"
                title="Нет статей ДДС"
                description="Создай первую статью для классификации операций"
                cta={
                  canManage ? (
                    <button className="btn-primary" onClick={openCreate}>
                      Добавить статью
                    </button>
                  ) : undefined
                }
              />
            </div>
          ) : (
            groups.map((group) => (
              <div key={group.activity} className="card rounded-2xl overflow-hidden shadow-elev-1">
                <div className="flex items-center gap-2 px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50/60 dark:bg-gray-800/40">
                  {activityBadge(group.activity)}
                  <span className="text-xs text-gray-400 dark:text-gray-500">{group.rows.length}</span>
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700">
                      <tr>
                        {canManage && <th className="px-2 py-2.5 w-8" />}
                        <th className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 text-left px-4 py-2.5">Название</th>
                        <th className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 text-left px-4 py-2.5">Направление</th>
                        <th className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 text-left px-4 py-2.5">Статус</th>
                        {canManage && <th className="px-4 py-2.5 w-20" />}
                      </tr>
                    </thead>
                    <DndContext
                      sensors={sensors}
                      collisionDetection={closestCenter}
                      onDragEnd={(e) => handleDragEnd(group.activity, e)}
                    >
                      <SortableContext
                        items={group.rows.map((c) => c.id)}
                        strategy={verticalListSortingStrategy}
                      >
                        <tbody>
                          {group.rows.map((cat) => (
                            <SortableRow
                              key={cat.id}
                              cat={cat}
                              canManage={!!canManage}
                              onEdit={openEdit}
                              onDeactivate={handleDeactivate}
                            />
                          ))}
                        </tbody>
                      </SortableContext>
                    </DndContext>
                  </table>
                </div>
              </div>
            ))
          )}
        </div>
      </div>

      {/* Модалка создания/редактирования */}
      <Modal
        open={modalOpen}
        title={editTarget ? "Редактировать статью" : "Новая статья ДДС"}
        onClose={closeModal}
        width="sm"
        footer={
          <>
            <button className="btn-ghost" onClick={closeModal} disabled={submitting}>Отмена</button>
            <button className="btn-primary" onClick={handleSubmit} disabled={submitting}>
              {submitting ? "Сохранение..." : "Сохранить"}
            </button>
          </>
        }
      >
        <div className="flex flex-col gap-4">
          <div>
            <label className="label">Название *</label>
            <input
              className="input"
              placeholder="Например: Поступления по подпискам"
              value={form.name}
              onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
            />
          </div>
          {!editTarget && (
            <div>
              <label className="label">Код статьи *</label>
              <input
                className="input font-mono"
                placeholder="Например: O-IN-SUB"
                value={form.code}
                onChange={(e) => setForm((f) => ({ ...f, code: e.target.value }))}
              />
            </div>
          )}
          <div>
            <label className="label">Вид деятельности</label>
            <select
              className="input"
              value={form.activity}
              onChange={(e) => setForm((f) => ({ ...f, activity: e.target.value as FinCashflowActivity }))}
            >
              {ACTIVITY_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="label">Направление</label>
            <select
              className="input"
              value={form.direction}
              onChange={(e) => setForm((f) => ({ ...f, direction: e.target.value }))}
            >
              {DIRECTION_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="label">Родительская статья (опционально)</label>
            <select
              className="input"
              value={form.parent_id}
              onChange={(e) => setForm((f) => ({ ...f, parent_id: e.target.value }))}
            >
              <option value="">— Нет (корневая)</option>
              {parentOptions.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
          </div>
          <div className="flex items-center gap-2">
            <input
              id="cat-active"
              type="checkbox"
              className="rounded border-gray-300 text-primary"
              checked={form.is_active}
              onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))}
            />
            <label htmlFor="cat-active" className="text-sm text-gray-700 dark:text-gray-300">Активна</label>
          </div>
          {formError && <p className="text-sm text-danger">{formError}</p>}
        </div>
      </Modal>
    </RoleGate>
  );
}
