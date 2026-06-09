"use client";

import { useState } from "react";
import { Modal } from "@/components/Modal";
import { api, ApiError } from "@/lib/api";
import type { Holding, HoldingRole } from "@/lib/types";
import { HOLDING_ROLE_LABELS } from "@/lib/types";

interface Props {
  open: boolean;
  onClose: () => void;
  /** Вызывается после успешного создания холдинга — передаёт холдинг и выбранную роль. */
  onCreated: (holding: Holding, role: HoldingRole) => void;
}

function isHolding(v: unknown): v is Holding {
  return typeof v === "object" && v !== null && "id" in v && "name" in v;
}

/** Модалка создания нового холдинга с выбором роли компании в нём. */
export function HoldingSetupModal({ open, onClose, onCreated }: Props) {
  const [name, setName] = useState("");
  const [role, setRole] = useState<HoldingRole>("subsidiary");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function reset() {
    setName("");
    setRole("subsidiary");
    setError(null);
    setSaving(false);
  }

  async function handleCreate() {
    if (!name.trim()) { setError("Введите название холдинга"); return; }
    setSaving(true);
    setError(null);
    try {
      const result = await api<unknown>("/holdings", { method: "POST", body: { name: name.trim() } });
      if (isHolding(result)) {
        onCreated(result, role);
        reset();
        onClose();
      } else {
        setError("Неожиданный ответ сервера");
      }
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось создать холдинг");
    } finally {
      setSaving(false);
    }
  }

  function handleClose() {
    reset();
    onClose();
  }

  return (
    <Modal
      open={open}
      title="Создать холдинг"
      onClose={handleClose}
      width="sm"
      footer={
        <>
          <button type="button" className="btn-secondary" onClick={handleClose}>Отмена</button>
          <button
            type="button"
            className="btn-primary"
            onClick={handleCreate}
            disabled={saving || !name.trim()}
          >
            {saving ? "Создание…" : "Создать холдинг"}
          </button>
        </>
      }
    >
      <div className="space-y-4">
        {error && (
          <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>
        )}

        <div>
          <label className="label">Название холдинга <span className="text-danger">*</span></label>
          <input
            className="input"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Например: Группа компаний «Альфа»"
            autoFocus
          />
        </div>

        <div>
          <label className="label">Роль этой компании в холдинге</label>
          <div className="space-y-2 mt-1">
            {(Object.entries(HOLDING_ROLE_LABELS) as [HoldingRole, string][]).map(([val, label]) => (
              <label key={val} className="flex items-center gap-2 text-sm cursor-pointer">
                <input
                  type="radio"
                  name="holding_role"
                  value={val}
                  checked={role === val}
                  onChange={() => setRole(val)}
                  className="accent-primary"
                />
                {label}
              </label>
            ))}
          </div>
        </div>
      </div>
    </Modal>
  );
}
