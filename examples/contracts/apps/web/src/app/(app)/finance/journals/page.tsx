"use client";

import { useState, useRef, useEffect, useCallback } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import useSWR, { mutate as globalMutate } from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { DatePicker } from "@/components/ui/DatePicker";
import { useToast } from "@/components/ui/Toast";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { api, ApiError, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import type { FinManualJournal, FinJournalStatus, FinLegalEntity, UserRole } from "@/lib/types";
import { formatDate } from "@/lib/dates";

const ALLOWED_ROLES: UserRole[] = ["accountant", "cfo", "admin"];

const STATUS_FILTER_OPTIONS: { value: string; label: string }[] = [
  { value: "", label: "Все статусы" },
  { value: "draft", label: "Черновик" },
  { value: "posted", label: "Проведено" },
  { value: "reversed", label: "Сторнировано" },
];

const JOURNAL_STATUS_META: Record<FinJournalStatus, { label: string; classes: string }> = {
  draft:    { label: "Черновик",     classes: "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400" },
  posted:   { label: "Проведено",    classes: "bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400" },
  reversed: { label: "Сторнировано", classes: "bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400" },
};

function JournalStatusBadge({ status }: { status: FinJournalStatus }) {
  const meta = JOURNAL_STATUS_META[status] ?? { label: status, classes: "bg-gray-100 text-gray-600" };
  return (
    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${meta.classes}`}>
      <span className="w-1.5 h-1.5 rounded-full bg-current opacity-70" />
      {meta.label}
    </span>
  );
}

function extractErrMsg(err: unknown): string {
  if (err instanceof ApiError) {
    const d = err.detail;
    if (typeof d === "object" && d !== null && "detail" in d) return String((d as Record<string, unknown>)["detail"]);
    if (typeof d === "string") return d;
  }
  return "Ошибка выполнения операции";
}

// Изолированный dropdown-меню для строки журнала (собственный state открытия).
function JournalRowMenu({
  journal,
  swrKey,
  canPost,
  router,
  toast,
}: {
  journal: FinManualJournal;
  swrKey: string;
  canPost: boolean;
  router: ReturnType<typeof useRouter>;
  toast: ReturnType<typeof useToast>["toast"];
}) {
  const [open, setOpen] = useState(false);
  const [actioning, setActioning] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handler(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    }
    if (open) document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, [open]);

  const handleToggle = useCallback((e: React.MouseEvent) => {
    e.stopPropagation();
    setOpen((o) => !o);
  }, []);

  async function handlePost(e: React.MouseEvent) {
    e.stopPropagation();
    setActioning(true);
    try {
      await api(`/finance/journals/${journal.id}/post`, { method: "POST" });
      await globalMutate(swrKey);
      toast.success("Проводка проведена");
    } catch (err) {
      toast.error("Не удалось провести", extractErrMsg(err));
    } finally {
      setActioning(false);
      setOpen(false);
    }
  }

  async function handleReverse(e: React.MouseEvent) {
    e.stopPropagation();
    setActioning(true);
    try {
      await api(`/finance/journals/${journal.id}/reverse`, { method: "POST", body: {} });
      await globalMutate(swrKey);
      toast.success("Сторно-проводка создана");
    } catch (err) {
      toast.error("Не удалось сторнировать", extractErrMsg(err));
    } finally {
      setActioning(false);
      setOpen(false);
    }
  }

  async function handleDelete(e: React.MouseEvent) {
    e.stopPropagation();
    if (!confirm("Удалить черновик журнала?")) return;
    setActioning(true);
    try {
      await api(`/finance/journals/${journal.id}`, { method: "DELETE" });
      await globalMutate(swrKey);
      toast.success("Черновик удалён");
    } catch (err) {
      toast.error("Не удалось удалить", extractErrMsg(err));
    } finally {
      setActioning(false);
      setOpen(false);
    }
  }

  return (
    <div ref={ref} className="relative flex justify-end">
      <button
        className="p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-400 dark:text-gray-500 transition-colors"
        onClick={handleToggle}
        disabled={actioning}
      >
        <i className="bi bi-three-dots-vertical" />
      </button>
      {open && (
        <div className="absolute right-0 top-9 z-20 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-elev-2 py-1 min-w-[160px]">
          <button
            className="w-full text-left px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 flex items-center gap-2 transition-colors"
            onClick={(e) => { e.stopPropagation(); setOpen(false); router.push(`/finance/journals/${journal.id}`); }}
          >
            <i className="bi bi-arrow-up-right-square" /> Открыть
          </button>
          {journal.status === "draft" && canPost && (
            <button
              className="w-full text-left px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 flex items-center gap-2 transition-colors"
              disabled={actioning}
              onClick={handlePost}
            >
              <i className="bi bi-check-circle text-success" />
              {actioning ? "Проведение..." : "Провести"}
            </button>
          )}
          {journal.status === "posted" && canPost && (
            <button
              className="w-full text-left px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 flex items-center gap-2 transition-colors"
              disabled={actioning}
              onClick={handleReverse}
            >
              <i className="bi bi-arrow-counterclockwise text-warning" />
              Сторнировать
            </button>
          )}
          {journal.status === "draft" && canPost && (
            <>
              <hr className="my-1 border-gray-100 dark:border-gray-700" />
              <button
                className="w-full text-left px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 text-danger flex items-center gap-2 transition-colors"
                disabled={actioning}
                onClick={handleDelete}
              >
                <i className="bi bi-trash" /> Удалить
              </button>
            </>
          )}
        </div>
      )}
    </div>
  );
}

export default function JournalsPage() {
  const router = useRouter();
  const { user } = useMe();
  const { toast } = useToast();

  const [statusFilter, setStatusFilter] = useState("");
  const [entityId, setEntityId] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");

  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);

  const qs = new URLSearchParams();
  if (statusFilter) qs.set("status", statusFilter);
  if (entityId) qs.set("entity", entityId);
  if (dateFrom) qs.set("date_from", dateFrom);
  if (dateTo) qs.set("date_to", dateTo);
  const swrKey = `/api/finance/journals?${qs.toString()}`;

  const { data: journals, isLoading, error } = useSWR<FinManualJournal[]>(swrKey, fetcher);

  const canPost = user && (["accountant", "cfo", "admin"] as UserRole[]).includes(user.role);

  const columns: DataTableColumn<FinManualJournal>[] = [
    {
      key: "date",
      header: "Дата",
      width: "12%",
      skeletonWidth: "70%",
      render: (j) => (
        <span className="text-gray-700 dark:text-gray-300">{formatDate(j.date)}</span>
      ),
    },
    {
      key: "number",
      header: "Номер",
      width: "14%",
      skeletonWidth: "65%",
      render: (j) => (
        <span className="font-mono text-gray-500 dark:text-gray-400">{j.number ?? "—"}</span>
      ),
    },
    {
      key: "memo",
      header: "Обоснование",
      skeletonWidth: "85%",
      render: (j) => (
        <span className="text-gray-700 dark:text-gray-300 max-w-xs truncate block">
          {j.memo || "—"}
        </span>
      ),
    },
    {
      key: "status",
      header: "Статус",
      width: "16%",
      skeletonWidth: "70%",
      render: (j) => <JournalStatusBadge status={j.status} />,
    },
  ];

  return (
    <RoleGate
      allowed={ALLOWED_ROLES}
      fallback={
        <div className="p-8 text-center">
          <p className="text-sm text-gray-500 dark:text-gray-400">Нет доступа к разделу журналов</p>
        </div>
      }
    >
      <div className="flex flex-col h-full">
        <PageHeader
          title="Ручные журналы"
          description="Корректировочные проводки бухгалтера"
          actions={
            canPost && (
              <Link href="/finance/journals/new" className="btn-primary">
                <i className="bi bi-plus mr-1" />
                Новая проводка
              </Link>
            )
          }
        />

        <div className="p-6 flex flex-col gap-4">
          {/* Фильтр-бар */}
          <div className="card rounded-2xl shadow-elev-1 p-4">
            <div className="flex flex-wrap items-center gap-2">
              <select
                className="input text-sm"
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
              >
                {STATUS_FILTER_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>{o.label}</option>
                ))}
              </select>
              <select
                className="input text-sm"
                value={entityId}
                onChange={(e) => setEntityId(e.target.value)}
              >
                <option value="">Все юрлица</option>
                {entities?.map((e) => (
                  <option key={e.id} value={e.id}>{e.name}</option>
                ))}
              </select>
              <DatePicker
                value={dateFrom || null}
                onChange={(v) => setDateFrom(v ?? "")}
                placeholder="Дата с"
              />
              <DatePicker
                value={dateTo || null}
                onChange={(v) => setDateTo(v ?? "")}
                placeholder="Дата по"
              />
            </div>
          </div>

          <DataTable<FinManualJournal>
            columns={columns}
            rows={isLoading ? undefined : (journals ?? [])}
            getRowKey={(j) => j.id}
            onRowClick={(j) => router.push(`/finance/journals/${j.id}`)}
            isError={!!error}
            errorText="Не удалось загрузить журналы"
            emptyIcon="bi-journal-text"
            emptyTitle="Нет ручных журналов"
            emptyText="Создай первую корректировочную проводку"
            emptyCta={
              canPost ? (
                <Link href="/finance/journals/new" className="btn-primary mt-1">
                  Новая проводка
                </Link>
              ) : undefined
            }
            skeletonRows={6}
            rowActions={(j) => (
              <JournalRowMenu
                journal={j}
                swrKey={swrKey}
                canPost={!!canPost}
                router={router}
                toast={toast}
              />
            )}
            ariaLabel="Ручные журналы"
          />
        </div>
      </div>
    </RoleGate>
  );
}
