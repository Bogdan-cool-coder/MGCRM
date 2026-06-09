"use client";

import { useCallback } from "react";
import { mutate } from "swr";
import { PageHeader } from "@/components/PageHeader";
import { EmployeesTable } from "@/components/Company/EmployeesTable";
import { EmployeeDismissModal } from "@/components/Company/EmployeeDismissModal";
import { RestoreUserModal } from "@/components/Company/RestoreUserModal";
import { RightsTransferModal } from "@/components/Company/RightsTransferModal";
import { useToast } from "@/components/ui/Toast";
import type { EmployeeListItem } from "@/lib/types";
import { useState } from "react";

export default function EmployeesPage() {
  const { toast } = useToast();
  const [dismissTarget, setDismissTarget] = useState<EmployeeListItem | null>(null);
  const [restoreTarget, setRestoreTarget] = useState<EmployeeListItem | null>(null);
  const [transferTarget, setTransferTarget] = useState<EmployeeListItem | null>(null);

  function invalidate() {
    void mutate((key) => typeof key === "string" && key.includes("/admin/users/employees"), undefined, { revalidate: true });
  }

  const handleDismissSuccess = useCallback((employeeName: string, substituteName: string) => {
    toast.success(`${employeeName} уволен`, `Данные переданы ${substituteName}.`);
    invalidate();
  }, [toast]);

  const handleRestoreSuccess = useCallback((employeeName: string, role: string) => {
    toast.success(`${employeeName} восстановлен`, `Роль: ${role}`);
    invalidate();
  }, [toast]);

  const handleTransferSuccess = useCallback((toName: string) => {
    toast.success("Права переданы", toName);
    invalidate();
  }, [toast]);

  function handleSchedule(employee: EmployeeListItem) {
    window.location.href = "/company/schedules";
    void employee;
  }

  return (
    <>
      <PageHeader
        title="Сотрудники"
        description="Управление статусами сотрудников и передача прав"
        actions={
          <a href="/admin/users" className="btn-primary">
            <i className="bi bi-plus-lg mr-1" />
            Добавить
          </a>
        }
      />

      <div className="p-8">
        <EmployeesTable
          onEdit={(emp) => {
            window.open(`/admin/users`, "_blank");
            void emp;
          }}
          onDismiss={setDismissTarget}
          onRestore={setRestoreTarget}
          onTransfer={setTransferTarget}
          onSchedule={handleSchedule}
        />
      </div>

      <EmployeeDismissModal
        open={!!dismissTarget}
        employee={dismissTarget}
        onClose={() => setDismissTarget(null)}
        onSuccess={handleDismissSuccess}
      />

      <RestoreUserModal
        open={!!restoreTarget}
        employee={restoreTarget}
        onClose={() => setRestoreTarget(null)}
        onSuccess={handleRestoreSuccess}
      />

      <RightsTransferModal
        open={!!transferTarget}
        employee={transferTarget}
        onClose={() => setTransferTarget(null)}
        onSuccess={handleTransferSuccess}
      />
    </>
  );
}
