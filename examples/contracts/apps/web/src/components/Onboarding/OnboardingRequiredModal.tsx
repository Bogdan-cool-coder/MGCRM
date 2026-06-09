"use client";

import { useRouter } from "next/navigation";
import { Modal } from "@/components/Modal";

interface Props {
  open: boolean;
  onClose: () => void;
}

export function OnboardingRequiredModal({ open, onClose }: Props) {
  const router = useRouter();

  function goToOnboarding() {
    onClose();
    router.push("/onboarding");
  }

  return (
    <Modal
      open={open}
      title="Завершение обучения обязательно"
      onClose={onClose}
      width="sm"
      footer={
        <>
          <button className="btn-ghost" onClick={onClose}>Отмена</button>
          <button className="btn-primary" onClick={goToOnboarding}>
            Открыть обучение →
          </button>
        </>
      }
    >
      <div className="text-center py-4">
        <i className="bi bi-mortarboard-fill text-warning text-5xl" />
        <h3 className="text-lg font-semibold mt-3 mb-2">Сначала заверши обязательные курсы</h3>
        <p className="text-sm text-gray-600">
          У тебя есть просроченные курсы онбординга. Bulk-операции временно недоступны.
        </p>
      </div>
    </Modal>
  );
}
