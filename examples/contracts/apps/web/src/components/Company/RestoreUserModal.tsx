"use client";

import { useState } from "react";
import { Modal } from "@/components/Modal";
import { SelectField } from "@/components/Field";
import { api, ApiError } from "@/lib/api";
import { RoleLabels, type EmployeeListItem, type UserRole } from "@/lib/types";

interface Props {
  open: boolean;
  employee: EmployeeListItem | null;
  onClose: () => void;
  onSuccess: (employeeName: string, role: string) => void;
}

const ROLE_OPTS: { value: UserRole; label: string }[] = (
  Object.entries(RoleLabels) as [UserRole, string][]
).map(([v, l]) => ({ value: v, label: l }));

export function RestoreUserModal({ open, employee, onClose, onSuccess }: Props) {
  const [role, setRole] = useState<UserRole>("manager");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleRestore() {
    if (!employee) return;
    setSubmitting(true);
    setError(null);
    try {
      await api(`/admin/users/${employee.id}/restore`, {
        method: "POST",
        body: { new_role: role },
      });
      onSuccess(employee.full_name, RoleLabels[role]);
      onClose();
    } catch (err) {
      setError(err instanceof ApiError ? String((err.detail as Record<string, unknown>)?.detail ?? err.message) : "Что-то пошло не так. Попробуй ещё раз.");
    } finally {
      setSubmitting(false);
    }
  }

  if (!employee) return null;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Восстановить сотрудника"
      width="sm"
      footer={
        <>
          <button className="btn-ghost" onClick={onClose} disabled={submitting}>Отмена</button>
          <button
            className="btn-primary"
            onClick={handleRestore}
            disabled={submitting || !role}
          >
            {submitting ? "Восстанавливаем…" : "Восстановить"}
          </button>
        </>
      }
    >
      <div className="space-y-4">
        <p className="text-sm text-gray-700 dark:text-gray-300">
          Восстановить <span className="font-semibold">{employee.full_name}</span>?
          Сотрудник снова получит доступ в систему.
        </p>

        <SelectField
          label="Новая роль"
          value={role}
          onChange={(v) => setRole(v as UserRole)}
          options={ROLE_OPTS}
          required
        />

        {error && (
          <div className="text-danger text-sm bg-danger/10 px-3 py-2 rounded">{error}</div>
        )}
      </div>
    </Modal>
  );
}
