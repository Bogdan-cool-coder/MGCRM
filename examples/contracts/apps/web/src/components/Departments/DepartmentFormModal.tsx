"use client";

import { useState, useEffect } from "react";
import { Modal } from "@/components/Modal";
import { Field } from "@/components/Field";
import { UserSelect } from "@/components/UserSelect";
import { DepartmentSelect } from "./DepartmentSelect";
import { api, ApiError } from "@/lib/api";
import type { Department, User } from "@/lib/types";

interface DepartmentFormModalProps {
  open: boolean;
  onClose: () => void;
  onSaved: () => void;
  department?: Department;
  allDepartments: Department[];
  allUsers: User[];
}

interface FormState {
  name: string;
  parent_id: string;
  head_user_id: string;
}

const EMPTY: FormState = {
  name: "",
  parent_id: "",
  head_user_id: "",
};

export function DepartmentFormModal({
  open,
  onClose,
  onSaved,
  department,
  allDepartments,
  allUsers,
}: DepartmentFormModalProps) {
  const isEdit = !!department;
  const [form, setForm] = useState<FormState>(EMPTY);
  const [initial, setInitial] = useState<FormState>(EMPTY);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    const next: FormState = department
      ? {
          name: department.name,
          parent_id: department.parent_id != null ? String(department.parent_id) : "",
          head_user_id: department.head_user_id != null ? String(department.head_user_id) : "",
        }
      : EMPTY;
    setForm(next);
    setInitial(next);
    setError(null);
  }, [open, department]);

  const isDirty = JSON.stringify(form) !== JSON.stringify(initial);
  const isValid = form.name.trim() !== "";

  async function handleSave(): Promise<boolean> {
    if (!isValid) {
      setError("Название обязательно");
      return false;
    }
    setSaving(true);
    setError(null);
    try {
      const body = {
        name: form.name.trim(),
        parent_id: form.parent_id ? Number(form.parent_id) : null,
        head_user_id: form.head_user_id ? Number(form.head_user_id) : null,
      };
      if (isEdit && department) {
        await api(`/departments/${department.id}`, { method: "PATCH", body });
      } else {
        await api("/departments", { method: "POST", body });
      }
      onSaved();
      return true;
    } catch (err) {
      if (err instanceof ApiError) {
        const detail = (err.detail as { detail?: string })?.detail ?? err.message;
        setError(String(detail));
      } else {
        setError("Не удалось сохранить. Попробуй ещё раз.");
      }
      return false;
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      onTrySave={handleSave}
      isDirty={isDirty}
      title={isEdit ? "Редактировать отдел" : "Новый отдел"}
      width="sm"
      footer={
        <>
          <button className="btn-ghost" onClick={onClose} disabled={saving}>
            Отмена
          </button>
          <button
            className="btn-primary"
            onClick={handleSave}
            disabled={!isValid || saving}
          >
            {saving ? "Сохраняем…" : "Сохранить"}
          </button>
        </>
      }
    >
      <div className="space-y-4">
        {error && (
          <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">
            {error}
          </div>
        )}

        <Field
          label="Название"
          value={form.name}
          onChange={(v) => setForm({ ...form, name: v })}
          required
          placeholder="Например: Отдел продаж"
        />

        <div>
          <label className="label">Родительский отдел</label>
          <DepartmentSelect
            value={form.parent_id}
            onChange={(v) => setForm({ ...form, parent_id: v })}
            departments={allDepartments}
            placeholder="Корневой (без родителя)"
            excludeId={department?.id}
          />
        </div>

        <div>
          <label className="label">Руководитель отдела</label>
          <UserSelect
            value={form.head_user_id}
            onChange={(v) => setForm({ ...form, head_user_id: v })}
            users={allUsers}
            placeholder="Не назначен"
          />
        </div>
      </div>
    </Modal>
  );
}
