"use client";

import { useEffect, useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { UserSelect } from "@/components/UserSelect";
import { api, ApiError, fetcher } from "@/lib/api";
import {
  type EmployeeListItem,
  type TransferCategory,
  type TransferPreview,
  TRANSFER_CATEGORY_LABELS,
} from "@/lib/types";

interface Props {
  open: boolean;
  employee: EmployeeListItem | null;
  onClose: () => void;
  onSuccess: (employeeName: string, substituteName: string) => void;
}

const ALL_CATEGORIES: TransferCategory[] = [
  "contacts",
  "deals",
  "tasks_assignee",
  "tasks_creator",
  "approvals",
  "configs",
];

type Step = 1 | 2 | 3;

interface StepIndicatorProps {
  current: Step;
}

function StepIndicator({ current }: StepIndicatorProps) {
  const steps: { id: Step; label: string }[] = [
    { id: 1, label: "Substitute" },
    { id: 2, label: "Что передать" },
    { id: 3, label: "Причина" },
  ];

  return (
    <div className="flex items-center gap-1 mb-6">
      {steps.map((step, idx) => {
        const done = step.id < current;
        const active = step.id === current;
        return (
          <div key={step.id} className="flex items-center gap-1 flex-1">
            <div className="flex items-center gap-2 shrink-0">
              <div
                className={[
                  "w-6 h-6 rounded-full text-xs flex items-center justify-center font-semibold",
                  done ? "bg-success text-white" : active ? "bg-primary text-white" : "bg-gray-200 dark:bg-gray-600 text-gray-500",
                ].join(" ")}
              >
                {done ? <i className="bi bi-check" /> : step.id}
              </div>
              <span className={`text-xs hidden sm:inline ${active ? "font-semibold text-primary" : "text-gray-500 dark:text-gray-400"}`}>
                {step.label}
              </span>
            </div>
            {idx < steps.length - 1 && (
              <div className={`h-px flex-1 mx-2 ${step.id < current ? "bg-primary" : "bg-gray-200 dark:bg-gray-600"}`} />
            )}
          </div>
        );
      })}
    </div>
  );
}

export function EmployeeDismissModal({ open, employee, onClose, onSuccess }: Props) {
  const [step, setStep] = useState<Step>(1);
  const [substituteId, setSubstituteId] = useState("");
  const [categories, setCategories] = useState<TransferCategory[]>([...ALL_CATEGORIES]);
  const [reason, setReason] = useState("");
  const [showConfirm, setShowConfirm] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [stepError, setStepError] = useState<string | null>(null);

  // Load all employees for UserSelect (exclude dismissed + the employee being dismissed)
  const { data: allEmployees } = useSWR<EmployeeListItem[]>(
    open ? "/admin/users/employees" : null,
    fetcher
  );

  // Transfer preview
  const { data: preview, isLoading: previewLoading } = useSWR<TransferPreview>(
    open && employee && step === 2 ? `/admin/users/${employee.id}/transfer-preview` : null,
    fetcher
  );

  // Reset on open
  useEffect(() => {
    if (open) {
      setStep(1);
      setSubstituteId("");
      setCategories([...ALL_CATEGORIES]);
      setReason("");
      setShowConfirm(false);
      setError(null);
      setStepError(null);
    }
  }, [open]);

  const availableUsers = allEmployees?.filter(
    (u) => u.employment_status !== "dismissed" && u.id !== employee?.id
  );

  const substituteName = allEmployees?.find((u) => String(u.id) === substituteId)?.full_name ?? "";

  function toggleCategory(cat: TransferCategory) {
    setCategories((prev) =>
      prev.includes(cat) ? prev.filter((c) => c !== cat) : [...prev, cat]
    );
  }

  function nextStep() {
    setStepError(null);
    if (step === 1) {
      if (!substituteId) {
        setStepError("Выбери substitute");
        return;
      }
      setStep(2);
    } else if (step === 2) {
      setStep(3);
    }
  }

  function prevStep() {
    setStepError(null);
    if (step === 2) setStep(1);
    else if (step === 3) setStep(2);
  }

  async function handleDismiss() {
    if (!employee) return;
    setSubmitting(true);
    setError(null);
    try {
      await api(`/admin/users/${employee.id}/dismiss`, {
        method: "POST",
        body: {
          substitute_user_id: Number(substituteId),
          transfer_categories: categories,
          reason: reason.trim() || null,
        },
      });
      onSuccess(employee.full_name, substituteName);
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? String((err.detail as Record<string, unknown>)?.detail ?? err.message) : "Что-то пошло не так. Попробуй ещё раз.");
    } finally {
      setSubmitting(false);
      setShowConfirm(false);
    }
  }

  if (!employee) return null;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`Увольнение: ${employee.full_name}`}
      width="md"
      footer={
        <div className="flex items-center justify-between w-full">
          <button className="btn-ghost" onClick={step === 1 ? onClose : prevStep}>
            {step === 1 ? "Отмена" : "← Назад"}
          </button>
          {step < 3 ? (
            <button className="btn-primary" onClick={nextStep}>
              Далее →
            </button>
          ) : (
            !showConfirm && (
              <button
                className="bg-danger hover:bg-danger/90 text-white rounded-md px-4 py-2 text-sm font-medium transition-colors"
                onClick={() => setShowConfirm(true)}
                disabled={submitting}
              >
                Далее →
              </button>
            )
          )}
        </div>
      }
    >
      <StepIndicator current={step} />

      {/* Step 1: Substitute */}
      {step === 1 && (
        <div className="space-y-4">
          <div>
            <label className="label">Кто заменит <span className="text-danger">*</span></label>
            <UserSelect
              value={substituteId}
              onChange={(v) => { setSubstituteId(v); setStepError(null); }}
              placeholder="Выбери substitute"
              users={availableUsers as import("@/lib/types").User[] | undefined}
            />
            {stepError && (
              <div className="text-danger text-sm mt-1">{stepError}</div>
            )}
            <p className="text-sm text-gray-500 dark:text-gray-400 mt-2">
              На этого человека перейдут все незавершённые задачи, контакты и сделки{" "}
              <span className="font-medium">{employee.full_name}</span>
            </p>
          </div>
        </div>
      )}

      {/* Step 2: Categories */}
      {step === 2 && (
        <div className="space-y-4">
          <h3 className="font-semibold text-gray-700 dark:text-gray-300">Выбери что передать substitute</h3>
          <div className="space-y-1">
            {ALL_CATEGORIES.map((cat) => (
              <label
                key={cat}
                className="flex items-center gap-2 py-2 px-2 rounded cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700"
              >
                <input
                  type="checkbox"
                  className="mr-1"
                  checked={categories.includes(cat)}
                  onChange={() => toggleCategory(cat)}
                />
                <span className="text-sm text-gray-700 dark:text-gray-300">
                  {TRANSFER_CATEGORY_LABELS[cat]}
                </span>
              </label>
            ))}
          </div>

          {/* Preview */}
          {previewLoading && (
            <div className="text-sm text-gray-500 animate-pulse">Считаем…</div>
          )}
          {preview && !previewLoading && (
            <div className="bg-gray-50 dark:bg-gray-700/50 rounded p-3 mt-3">
              <div className="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-2 uppercase tracking-wide">
                Будет передано:
              </div>
              <div className="grid grid-cols-3 gap-2 text-sm">
                {categories.includes("contacts") && (
                  <div>
                    <span className="font-semibold text-primary">{preview.contacts}</span>
                    <span className="text-gray-500 ml-1">контакт{preview.contacts === 1 ? "" : preview.contacts < 5 ? "а" : "ов"}</span>
                  </div>
                )}
                {categories.includes("deals") && (
                  <div>
                    <span className="font-semibold text-primary">{preview.deals}</span>
                    <span className="text-gray-500 ml-1">сделок</span>
                  </div>
                )}
                {(categories.includes("tasks_assignee") || categories.includes("tasks_creator")) && (
                  <div>
                    <span className="font-semibold text-primary">
                      {(categories.includes("tasks_assignee") ? preview.tasks_assignee : 0) +
                        (categories.includes("tasks_creator") ? preview.tasks_creator : 0)}
                    </span>
                    <span className="text-gray-500 ml-1">задач</span>
                  </div>
                )}
                {categories.includes("approvals") && (
                  <div>
                    <span className="font-semibold text-primary">{preview.approvals}</span>
                    <span className="text-gray-500 ml-1">согласований</span>
                  </div>
                )}
                {categories.includes("configs") && (
                  <div>
                    <span className="font-semibold text-primary">{preview.configs}</span>
                    <span className="text-gray-500 ml-1">конфигов</span>
                  </div>
                )}
              </div>
            </div>
          )}
        </div>
      )}

      {/* Step 3: Reason + Confirm */}
      {step === 3 && (
        <div className="space-y-4">
          <div>
            <label className="label">Причина увольнения</label>
            <textarea
              className="input"
              rows={4}
              placeholder="Опционально: укажи причину увольнения…"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
            <p className="text-xs text-gray-500 mt-1">
              Причина сохраняется в журнале и видна только администраторам
            </p>
          </div>

          {showConfirm && (
            <div className="bg-danger/10 border border-danger/30 rounded p-4">
              <div className="font-semibold text-danger mb-2">
                Уволить {employee.full_name}?
              </div>
              <p className="text-sm text-gray-700 dark:text-gray-300 mb-4">
                После увольнения войти в систему будет невозможно.
                Данные перейдут к {substituteName}.
              </p>
              {error && (
                <div className="text-danger text-sm bg-danger/10 px-3 py-2 rounded mb-3">
                  {error}
                </div>
              )}
              <div className="flex items-center gap-2">
                <button
                  className="btn-ghost"
                  onClick={() => { setShowConfirm(false); setError(null); }}
                  disabled={submitting}
                >
                  Отмена
                </button>
                <button
                  className="bg-danger hover:bg-danger/90 text-white rounded-md px-4 py-2 text-sm font-medium transition-colors disabled:opacity-60"
                  onClick={handleDismiss}
                  disabled={submitting}
                >
                  {submitting ? "Увольняем…" : "Уволить сотрудника"}
                </button>
              </div>
            </div>
          )}
        </div>
      )}
    </Modal>
  );
}
