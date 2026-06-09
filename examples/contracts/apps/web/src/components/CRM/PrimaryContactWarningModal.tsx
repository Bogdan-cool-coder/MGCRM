"use client";

import { Modal } from "@/components/Modal";

interface ExistingPrimary {
  id: number;
  name: string;
  position: string | null;
}

interface Props {
  open: boolean;
  existing: ExistingPrimary | null;
  onCancel: () => void;
  onConfirm: () => void;
}

/**
 * Модалка-предупреждение при попытке сохранить контакт как основной,
 * когда у компании уже есть primary-контакт.
 */
export function PrimaryContactWarningModal({ open, existing, onCancel, onConfirm }: Props) {
  return (
    <Modal
      open={open}
      title="Уже есть основной контакт"
      onClose={onCancel}
      width="sm"
      footer={
        <>
          <button type="button" className="btn-secondary" onClick={onCancel}>
            Отклонить
          </button>
          <button type="button" className="btn-primary" onClick={onConfirm}>
            Сохранить и заменить
          </button>
        </>
      }
    >
      <p className="text-sm text-gray-700 dark:text-gray-300 mb-3">
        У компании уже есть основной контакт:
      </p>
      {existing && (
        <a
          href={`/contacts/${existing.id}`}
          target="_blank"
          rel="noreferrer"
          className="block mb-4 px-3 py-2 rounded-md border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
        >
          <span className="font-medium text-primary dark:text-primary-light text-sm">
            {existing.name}
          </span>
          {existing.position && (
            <span className="text-xs text-gray-500 dark:text-gray-400 block">{existing.position}</span>
          )}
        </a>
      )}
      <p className="text-sm text-gray-500 dark:text-gray-400">
        Если сохранишь — прежний контакт потеряет статус «основного».
      </p>
    </Modal>
  );
}
