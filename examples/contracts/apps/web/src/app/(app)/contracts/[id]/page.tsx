"use client";

import { useEffect, useMemo, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import useSWR from "swr";
import clsx from "clsx";
import { PageHeader } from "@/components/PageHeader";
import { StatusBadge } from "@/components/StatusBadge";
import { Field, SelectField } from "@/components/Field";
import { DatePicker } from "@/components/ui/DatePicker";
import { ApprovalPanel } from "@/components/ApprovalPanel";
import { RemarksChecklist } from "@/components/RemarksChecklist";
import { RevisionsHistory } from "@/components/RevisionsHistory";
import { CustomFieldsSection } from "@/components/CustomFieldsSection";
import { ProductsSection, type ProductsDerived } from "@/components/ProductsSection";
import { CategoryBadge } from "@/components/CategoryBadge";
import { EmptyState } from "@/components/EmptyState";
import { BlurFade } from "@/components/magicui/BlurFade";
import { Dropzone } from "@/components/ui/Dropzone";
import { useToast } from "@/components/ui/Toast";
import { useMe } from "@/lib/auth";
import { api, ApiError, fetcher } from "@/lib/api";
import { addMonths, parseRuDate, isoToRu, ruToIso } from "@/lib/dates";
import { useAutoSave } from "@/lib/useAutoSave";
import {
  LICENSE_TYPES,
  type Contract,
  type ContractAttachment,
  type Counterparty,
  type CountryInfo,
  type LicensorEntity,
  type ProductInfo,
  type TemplateVariable,
} from "@/lib/types";
import { CustomFieldsBlock } from "@/components/CustomFields/CustomFieldsBlock";
import { AuditLogTimeline } from "@/components/AuditLog/AuditLogTimeline";
import { ContractAnalysisModal } from "@/components/AI/ContractAnalysisModal";

// ─── Local types ───────────────────────────────────────────────────────────────

type PaymentRow = {
  number?: number;
  amount?: string;
  vat?: string;
  due_date?: string;
  period?: string;
  included?: string;
};
type ActRow = {
  number?: number;
  amount?: string;
  vat?: string;
  sign_by_date?: string;
  period?: string;
};

type ContextLicense = {
  type?: string;
  start_date?: string;
  end_date?: string;
  duration_months?: number | string;
  price_amount_text?: string;
  price_amount_words?: string;
  implementation_start_date?: string;
  payment_schedule?: PaymentRow[];
  act_schedule?: ActRow[];
};

type Sublicensee = {
  full_legal_form?: string;
  legal_form?: string;
  gender_ending_ое?: string;
  name?: string;
  director_position?: string;
  director_genitive?: string;
  director_short?: string;
  acts_basis?: string;
  tax_id_label?: string;
  tax_id?: string;
  address?: string;
  bank?: string;
  bank_code_label?: string;
  bank_code?: string;
  account?: string;
  phone?: string;
  email?: string;
  website?: string;
};

type ContractCtx = {
  contract?: {
    number?: string;
    date_day?: string;
    date_month?: string;
    date_year?: string;
    date_raw?: string;
    /** Тип документа (модуль «Документы», задел на будущее). */
    document_type_label?: string;
  };
  sublicensee?: Sublicensee;
  license?: ContextLicense;
  licensor_override_id?: number;
  custom?: Record<string, unknown>;
};

// ─── Skeleton ─────────────────────────────────────────────────────────────────

function ContractSkeleton() {
  return (
    <>
      <PageHeader title="Документ" />
      {/* Action-bar skeleton */}
      <div className="px-8 pt-4 pb-3 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
        <div className="flex items-center gap-2">
          {[120, 90, 80].map((w) => (
            <div
              key={w}
              className="h-9 rounded-lg bg-gray-200 dark:bg-gray-700 animate-pulse"
              style={{ width: w }}
            />
          ))}
        </div>
      </div>
      {/* Hero skeleton */}
      <div className="px-8 py-5 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700">
        <div className="h-4 w-32 bg-gray-200 dark:bg-gray-700 rounded animate-pulse mb-2" />
        <div className="h-7 w-64 bg-gray-200 dark:bg-gray-700 rounded animate-pulse mb-4" />
        <div className="flex gap-6">
          {[100, 80, 120].map((w) => (
            <div key={w} className="h-4 rounded bg-gray-200 dark:bg-gray-700 animate-pulse" style={{ width: w }} />
          ))}
        </div>
      </div>
      {/* Body skeleton */}
      <div className="p-8 grid grid-cols-1 xl:grid-cols-3 gap-6">
        <div className="xl:col-span-2 space-y-4">
          {[1, 2, 3].map((i) => (
            <div key={i} className="rounded-2xl bg-white dark:bg-gray-800 shadow-elev-1 p-5">
              <div className="h-5 w-40 bg-gray-200 dark:bg-gray-700 rounded animate-pulse mb-4" />
              <div className="grid grid-cols-2 gap-3">
                {[1, 2, 3, 4].map((j) => (
                  <div key={j} className="h-10 rounded-lg bg-gray-100 dark:bg-gray-700/60 animate-pulse" />
                ))}
              </div>
            </div>
          ))}
        </div>
        <div className="space-y-4">
          {[1, 2].map((i) => (
            <div key={i} className="rounded-2xl bg-white dark:bg-gray-800 shadow-elev-1 p-5">
              <div className="h-5 w-32 bg-gray-200 dark:bg-gray-700 rounded animate-pulse mb-3" />
              <div className="space-y-2">
                {[1, 2, 3].map((j) => (
                  <div key={j} className="h-4 rounded bg-gray-100 dark:bg-gray-700/60 animate-pulse" />
                ))}
              </div>
            </div>
          ))}
        </div>
      </div>
    </>
  );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function ContractPage() {
  const params = useParams();
  const router = useRouter();
  const id = Number(params.id);
  const { toast } = useToast();

  const { data: contract, mutate } = useSWR<Contract>(`/contracts/${id}`, fetcher);
  const { data: products } = useSWR<ProductInfo[]>("/templates/products", fetcher);
  const { data: countries } = useSWR<CountryInfo[]>("/templates/countries", fetcher);
  const { data: licensors } = useSWR<LicensorEntity[]>("/licensors", fetcher);
  const { data: customVars } = useSWR<TemplateVariable[]>(
    contract
      ? `/template-variables/for-form?product=${contract.product_code}&country=${contract.country_code}`
      : null,
    fetcher,
  );
  const { data: counterparty } = useSWR<Counterparty>(
    contract?.counterparty_id ? `/counterparties/${contract.counterparty_id}` : null,
    fetcher,
  );
  const { data: attachments, mutate: mutateAtt } = useSWR<ContractAttachment[]>(
    contract && ["approved", "uploaded", "signed"].includes(contract.status)
      ? `/contracts/${id}/attachments`
      : null,
    fetcher,
  );
  const { user: me } = useMe();

  // Inline error state for non-critical inline errors (validation hints)
  const [inlineError, setInlineError] = useState<string | null>(null);
  const [driveFolderUrl, setDriveFolderUrl] = useState("");
  const [actionLoading, setActionLoading] = useState(false);
  const [showPdf, setShowPdf] = useState(false);
  const [uploadingScan, setUploadingScan] = useState(false);
  const [aiAnalysisOpen, setAiAnalysisOpen] = useState(false);

  const ctx = (contract?.context as ContractCtx) || {};
  const product = products?.find((p) => p.code === contract?.product_code);
  const country = countries?.find((c) => c.code === contract?.country_code);
  const currency = country?.currency_code;
  const editable =
    contract?.status === "draft" ||
    contract?.status === "rejected" ||
    contract?.status === "needs_rework";

  // ── Auto-fill sublicensee on draft open ─────────────────────────────────────
  useEffect(() => {
    if (!contract || !counterparty) return;
    if (!editable) return;
    const hasSub = ctx.sublicensee && (ctx.sublicensee.name || ctx.sublicensee.legal_form);
    if (hasSub) return;
    const next = {
      ...ctx,
      sublicensee: {
        full_legal_form: counterparty.full_legal_form ?? "",
        legal_form: counterparty.legal_form ?? "",
        gender_ending_ое: counterparty.gender_ending_oe ?? "ое",
        name: counterparty.name ?? "",
        director_position: counterparty.director_position ?? "Директор",
        director_genitive: counterparty.director_genitive ?? "",
        director_short: counterparty.director_short ?? "",
        acts_basis: counterparty.acts_basis ?? "Устава",
        tax_id_label: counterparty.tax_id_label ?? "",
        tax_id: counterparty.tax_id ?? "",
        address: counterparty.address ?? "",
        bank: counterparty.bank ?? "",
        bank_code_label: counterparty.bank_code_label ?? "",
        bank_code: counterparty.bank_code ?? "",
        account: counterparty.account ?? "",
        phone: counterparty.phone ?? "",
        email: counterparty.email ?? "",
        website: counterparty.website ?? "",
      },
    };
    mutate({ ...contract, context: next as Record<string, unknown> }, false);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [counterparty?.id, contract?.id]);

  // ── Auto-save ────────────────────────────────────────────────────────────────
  const autoSave = useAutoSave(
    contract?.context,
    async (newCtx) => {
      await api(`/contracts/${id}`, { method: "PATCH", body: { context: newCtx } });
    },
    { debounceMs: 1500, enabled: !!contract && editable },
  );

  // All hooks BEFORE early return (Rules of Hooks)
  const licensorOpts = useMemo(() => {
    if (!licensors || !contract) return [];
    return licensors
      .filter((l) => l.country_code === contract.country_code)
      .map((l) => ({ value: String(l.id), label: `${l.legal_form} «${l.name}»` }));
  }, [licensors, contract?.country_code, contract]);

  if (!contract) {
    return <ContractSkeleton />;
  }

  // ── Context helpers ──────────────────────────────────────────────────────────
  function setCtx(updater: (prev: ContractCtx) => ContractCtx) {
    if (!contract) return;
    const next = updater(JSON.parse(JSON.stringify(ctx || {})));
    mutate({ ...contract, context: next as Record<string, unknown> }, false);
  }

  function setSubField<K extends keyof Sublicensee>(field: K, value: Sublicensee[K]) {
    setCtx((prev) => ({ ...prev, sublicensee: { ...(prev.sublicensee || {}), [field]: value } }));
  }
  function setLicField<K extends keyof ContextLicense>(field: K, value: ContextLicense[K]) {
    setCtx((prev) => ({ ...prev, license: { ...(prev.license || {}), [field]: value } }));
  }
  function setCustomField(key: string, value: unknown) {
    setCtx((prev) => ({ ...prev, custom: { ...(prev.custom || {}), [key]: value } }));
  }

  function setStartDate(v: string) {
    const months = Number(ctx.license?.duration_months) || 0;
    const end = months ? addMonths(v, months) : ctx.license?.end_date;
    setCtx((prev) => ({
      ...prev,
      license: { ...(prev.license || {}), start_date: v, end_date: end },
    }));
  }
  const contractDateRaw = ctx.contract?.date_raw ?? "";
  function setContractDate(v: string) {
    const parsed = parseRuDate(v);
    setCtx((prev) => ({
      ...prev,
      contract: {
        ...(prev.contract || {}),
        date_raw: v,
        date_day: parsed?.day,
        date_month: parsed?.month,
        date_year: parsed?.year,
      },
    }));
  }

  async function recomputeWords(amountOverride?: string) {
    const amount = amountOverride ?? ctx.license?.price_amount_text;
    if (!amount) return;
    try {
      const res = await api<{ text: string }>("/utils/num-to-words", {
        query: { amount, currency: currency ?? undefined },
      });
      setLicField("price_amount_words", res.text);
    } catch {
      /* silent */
    }
  }

  // ── Переток данных «Продукты и стоимость» → «Лицензия» ───────────────────────
  // Источник истины для срока/суммы/прописью — раздел продуктов. Здесь записываем
  // производные значения в ctx.license, чтобы генерация DOCX получала их как раньше
  // (шаблон читает license.duration_months / price_amount_text / price_amount_words).
  function handleProductsDerived(d: ProductsDerived) {
    if (!editable) return;
    const lic = ctx.license ?? {};
    const totalStr = d.total > 0 ? String(d.total) : "";
    const months = d.months || 0;
    const start = lic.start_date;
    const end = start && months ? addMonths(start, months) : lic.end_date;

    const monthsChanged = String(lic.duration_months ?? "") !== String(months || "");
    const totalChanged = (lic.price_amount_text ?? "") !== totalStr;
    const endChanged = (lic.end_date ?? "") !== (end ?? "");
    if (!monthsChanged && !totalChanged && !endChanged) return;

    setCtx((prev) => ({
      ...prev,
      license: {
        ...(prev.license || {}),
        duration_months: months || undefined,
        price_amount_text: totalStr,
        end_date: end,
      },
    }));

    // Сумма прописью пересчитывается от итоговой суммы продуктов.
    if (totalChanged && totalStr) {
      void recomputeWords(totalStr);
    }
  }

  // ── Actions ──────────────────────────────────────────────────────────────────

  function extractDetail(err: unknown): string {
    if (err instanceof ApiError) {
      return String((err.detail as { detail?: string })?.detail ?? err.message);
    }
    return "Неизвестная ошибка";
  }

  async function generate() {
    if (!contract) return;
    if (!isContextValid()) {
      setInlineError("Заполните обязательные поля Сублицензиата и Лицензии");
      return;
    }
    setActionLoading(true);
    setInlineError(null);
    try {
      await api(`/contracts/${id}`, { method: "PATCH", body: { context: contract.context } });
      await api(`/contracts/${id}/generate`, { method: "POST" });
      await mutate();
      toast.success("Договор сгенерирован", "DOCX и PDF готовы к скачиванию");
    } catch (err) {
      toast.error("Ошибка генерации", extractDetail(err));
    } finally {
      setActionLoading(false);
    }
  }

  async function submit() {
    setActionLoading(true);
    setInlineError(null);
    try {
      await api(`/contracts/${id}/submit`, { method: "POST" });
      await mutate();
      toast.success("Отправлено на согласование");
    } catch (err) {
      toast.error("Не удалось отправить", extractDetail(err));
    } finally {
      setActionLoading(false);
    }
  }

  async function fillFromCounterparty() {
    setActionLoading(true);
    setInlineError(null);
    try {
      const updated = await api<Contract>(`/contracts/${id}/fill-from-counterparty`, {
        method: "POST",
      });
      mutate(updated, false);
      toast.success("Реквизиты подтянуты из контрагента");
    } catch (err) {
      toast.error("Не удалось заполнить", extractDetail(err));
    } finally {
      setActionLoading(false);
    }
  }

  async function uploadToDrive() {
    if (!driveFolderUrl) {
      setInlineError("Укажите ссылку на папку Google Drive");
      return;
    }
    setActionLoading(true);
    setInlineError(null);
    try {
      await api(`/contracts/${id}/drive-upload`, {
        method: "POST",
        body: { folder_url: driveFolderUrl },
      });
      await mutate();
      toast.success("Выгружено в Google Drive");
    } catch (err) {
      toast.error("Ошибка выгрузки", extractDetail(err));
    } finally {
      setActionLoading(false);
    }
  }

  async function duplicate() {
    setActionLoading(true);
    setInlineError(null);
    try {
      const created = await api<Contract>(`/contracts/${id}/duplicate`, { method: "POST" });
      toast.success("Договор дублирован");
      router.push(`/contracts/${created.id}`);
    } catch (err) {
      toast.error("Не удалось дублировать", extractDetail(err));
      setActionLoading(false);
    }
  }

  async function toggleArchive() {
    if (!contract) return;
    setActionLoading(true);
    setInlineError(null);
    try {
      await api(
        `/contracts/${id}/${contract.archived_at ? "unarchive" : "archive"}`,
        { method: "POST" },
      );
      await mutate();
      toast.info(contract.archived_at ? "Возвращён из архива" : "Перемещён в архив");
    } catch (err) {
      toast.error("Не удалось изменить архив", extractDetail(err));
    } finally {
      setActionLoading(false);
    }
  }

  async function uploadScan(files: File[]) {
    const file = files[0];
    if (!file) return;
    setUploadingScan(true);
    setInlineError(null);
    try {
      const fd = new FormData();
      fd.append("file", file);
      const res = await fetch(`/api/contracts/${id}/attachments?kind=signed_scan`, {
        method: "POST",
        body: fd,
        credentials: "same-origin",
      });
      if (!res.ok) {
        let d: unknown = await res.text();
        try {
          d = JSON.parse(d as string);
        } catch {
          /* keep text */
        }
        throw new ApiError(res.status, d);
      }
      await mutateAtt();
      toast.success("Скан загружен");
    } catch (err) {
      toast.error("Не удалось загрузить файл", extractDetail(err));
    } finally {
      setUploadingScan(false);
    }
  }

  async function sign() {
    setActionLoading(true);
    setInlineError(null);
    try {
      await api(`/contracts/${id}/sign`, { method: "POST" });
      await mutate();
      toast.success("Сделка проведена");
    } catch (err) {
      toast.error("Не удалось провести сделку", extractDetail(err));
    } finally {
      setActionLoading(false);
    }
  }

  async function unsign() {
    setActionLoading(true);
    setInlineError(null);
    try {
      await api(`/contracts/${id}/unsign`, { method: "POST" });
      await mutate();
      toast.info("Проведение отменено");
    } catch (err) {
      toast.error("Не удалось отменить проведение", extractDetail(err));
    } finally {
      setActionLoading(false);
    }
  }

  async function deleteAttachment(aid: number) {
    try {
      await api(`/contracts/${id}/attachments/${aid}`, { method: "DELETE" });
      await mutateAtt();
      toast.success("Вложение удалено");
    } catch (err) {
      toast.error("Не удалось удалить вложение", extractDetail(err));
    }
  }

  function isContextValid(): boolean {
    const sub = ctx.sublicensee ?? {};
    const lic = ctx.license ?? {};
    const customOk = (customVars ?? []).every((v) => {
      if (!v.required || v.var_type === "checkbox") return true;
      const val = (ctx.custom ?? {})[v.key];
      return val !== undefined && val !== null && String(val).trim() !== "";
    });
    return !!(
      sub.legal_form &&
      sub.name &&
      sub.director_genitive &&
      sub.director_short &&
      lic.type &&
      lic.start_date &&
      lic.end_date &&
      lic.price_amount_text &&
      contractDateRaw &&
      parseRuDate(contractDateRaw) &&
      customOk
    );
  }

  // ── Schedule helpers ─────────────────────────────────────────────────────────
  const payments = ctx.license?.payment_schedule ?? [];
  const acts = ctx.license?.act_schedule ?? [];

  function addPayment() {
    const newRow: PaymentRow = {
      number: payments.length + 1,
      amount: ctx.license?.price_amount_text,
      vat: "без НДС",
      due_date: "",
      period: "",
      included: "",
    };
    setLicField("payment_schedule", [...payments, newRow]);
  }
  function removePayment(i: number) {
    setLicField(
      "payment_schedule",
      payments.filter((_, idx) => idx !== i).map((p, idx) => ({ ...p, number: idx + 1 })),
    );
  }
  function updatePayment(i: number, field: keyof PaymentRow, value: string | number) {
    setLicField(
      "payment_schedule",
      payments.map((p, idx) => (idx === i ? { ...p, [field]: value } : p)),
    );
  }
  function addAct() {
    const newRow: ActRow = {
      number: acts.length + 1,
      amount: ctx.license?.price_amount_text,
      vat: "без НДС",
      sign_by_date: "",
      period: "",
    };
    setLicField("act_schedule", [...acts, newRow]);
  }
  function removeAct(i: number) {
    setLicField(
      "act_schedule",
      acts.filter((_, idx) => idx !== i).map((a, idx) => ({ ...a, number: idx + 1 })),
    );
  }
  function updateAct(i: number, field: keyof ActRow, value: string | number) {
    setLicField(
      "act_schedule",
      acts.map((a, idx) => (idx === i ? { ...a, [field]: value } : a)),
    );
  }

  // ── Auto-save status indicator ───────────────────────────────────────────────
  const saveStatusText =
    autoSave.status === "saving"
      ? "Сохранение…"
      : autoSave.status === "saved"
        ? "✓ Сохранено"
        : autoSave.status === "error"
          ? "Ошибка автосохранения"
          : "";

  const isArchived = !!contract.archived_at;

  // Тип документа: в будущем зависит от выбранного типа документа (модуль «Документы»).
  // Сейчас берём из контекста, если задан, иначе «Договор».
  const documentTypeLabel =
    (typeof ctx.contract?.document_type_label === "string" && ctx.contract.document_type_label) ||
    "Договор";
  const companyName =
    counterparty?.name ?? ctx.sublicensee?.name ?? contract.number ?? `#${contract.id}`;
  const headerTitle = `${documentTypeLabel} «${companyName}»`;

  // Мета-строка заголовка: сумма + период действия лицензии.
  const heroAmount = ctx.license?.price_amount_text
    ? `${ctx.license.price_amount_text}${currency ? ` ${currency}` : ""}`
    : null;
  const heroPeriod = ctx.license?.start_date
    ? `${ctx.license.start_date}${ctx.license?.end_date ? `–${ctx.license.end_date}` : ""}`
    : null;

  return (
    <>
      {/* ── PageHeader (NOT sticky — action-bar below is sticky) ──────────────
          Заголовок: «<тип документа> «<Название компании>»».
          Тип документа пока «Договор», в будущем — из выбранного типа документа.
      ─────────────────────────────────────────────────────────────────────── */}
      <PageHeader
        title={headerTitle}
        actions={
          <div className="flex items-center gap-3 flex-wrap">
            {saveStatusText && (
              <span
                className={clsx(
                  "text-xs",
                  autoSave.status === "error" ? "text-danger" : "text-gray-500 dark:text-gray-400",
                )}
              >
                {saveStatusText}
              </span>
            )}
            {counterparty?.category_code && (
              <CategoryBadge code={counterparty.category_code} />
            )}
            <StatusBadge status={contract.status} />
          </div>
        }
      />

      {/* ── Hero meta strip ──────────────────────────────────────────────────
          Мета-строка под заголовком: сумма + период действия лицензии (+ дата
          договора / архив). Это «верхняя строка-заголовок» по ТЗ. Не sticky.
      ─────────────────────────────────────────────────────────────────────── */}
      {(heroAmount || heroPeriod || contractDateRaw || isArchived) && (
        <BlurFade delay={0.05}>
          <div className="px-8 py-3 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700">
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-gray-600 dark:text-gray-400">
              {heroAmount && (
                <span className="flex items-center gap-1.5">
                  <i className="bi bi-cash-stack text-gray-400 dark:text-gray-500" aria-hidden="true" />
                  <span className="font-semibold text-gray-900 dark:text-gray-100">{heroAmount}</span>
                </span>
              )}
              {heroPeriod && (
                <span className="flex items-center gap-1.5">
                  <i className="bi bi-calendar-event text-gray-400 dark:text-gray-500" aria-hidden="true" />
                  {heroPeriod}
                </span>
              )}
              {contractDateRaw && (
                <span className="flex items-center gap-1.5">
                  <i className="bi bi-pen text-gray-400 dark:text-gray-500" aria-hidden="true" />
                  {contractDateRaw}
                </span>
              )}
              {isArchived && (
                <span className="inline-flex items-center gap-1 rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-400">
                  <i className="bi bi-archive" aria-hidden="true" /> В архиве
                </span>
              )}
            </div>
          </div>
        </BlurFade>
      )}

      {/* ── Sticky action-bar ─────────────────────────────────────────────────
          z-20: выше контента страницы (z-0), ниже Sidebar tooltip (z-50),
          ниже Modal overlay (z-40). PageHeader не sticky — нет конфликта.
      ─────────────────────────────────────────────────────────────────────── */}
      <div
        className={clsx(
          "sticky top-0 z-20",
          "px-8 pt-3 pb-3",
          "border-b border-gray-200 dark:border-gray-700",
          "bg-white/95 dark:bg-gray-900/95 backdrop-blur-sm",
          // Subtle shadow to visually separate from scrolled content
          "shadow-[0_1px_0_0_rgba(0,0,0,0.06)] dark:shadow-[0_1px_0_0_rgba(0,0,0,0.3)]",
        )}
      >
        <div className="flex items-center gap-2 flex-wrap">
          {/* Primary: generate */}
          {editable && (
            <button
              onClick={generate}
              disabled={actionLoading || !isContextValid()}
              className="btn-primary"
              aria-busy={actionLoading}
            >
              {actionLoading ? (
                <>
                  <i className="bi bi-arrow-clockwise animate-spin" aria-hidden="true" />{" "}
                  Генерация…
                </>
              ) : (
                <>
                  <i className="bi bi-file-earmark-arrow-down" aria-hidden="true" /> Сгенерировать
                </>
              )}
            </button>
          )}

          {/* Primary: submit */}
          {editable && contract.docx_path && contract.status === "draft" && (
            <button
              onClick={submit}
              disabled={actionLoading}
              className="btn-primary"
              aria-busy={actionLoading}
            >
              <i className="bi bi-send" aria-hidden="true" /> На согласование
            </button>
          )}

          {/* Secondary: download */}
          {contract.docx_path && (
            <a
              className="btn-secondary"
              href={`/api/contracts/${id}/docx`}
              target="_blank"
              rel="noreferrer"
            >
              <i className="bi bi-file-earmark-word" aria-hidden="true" /> .docx
            </a>
          )}
          {contract.pdf_path && (
            <a
              className="btn-secondary"
              href={`/api/contracts/${id}/pdf`}
              target="_blank"
              rel="noreferrer"
            >
              <i className="bi bi-file-earmark-pdf" aria-hidden="true" /> .pdf
            </a>
          )}
          {contract.pdf_path && (
            <button
              onClick={() => setShowPdf((v) => !v)}
              className="btn-secondary"
              aria-pressed={showPdf}
            >
              <i className={showPdf ? "bi bi-eye-slash" : "bi bi-eye"} aria-hidden="true" />{" "}
              {showPdf ? "Скрыть превью" : "Превью PDF"}
            </button>
          )}

          {/* AI analysis */}
          <button
            onClick={() => setAiAnalysisOpen(true)}
            className="btn-secondary"
            title="AI-анализ договора (Claude)"
          >
            <i className="bi bi-stars" aria-hidden="true" /> AI: анализ
          </button>

          {/* Ghost: utility */}
          <button
            onClick={duplicate}
            disabled={actionLoading}
            className="btn-ghost"
          >
            <i className="bi bi-files" aria-hidden="true" /> Дублировать
          </button>
          <button
            onClick={toggleArchive}
            disabled={actionLoading}
            className="btn-ghost"
          >
            <i
              className={
                contract.archived_at ? "bi bi-box-arrow-up" : "bi bi-archive"
              }
              aria-hidden="true"
            />{" "}
            {contract.archived_at ? "Из архива" : "В архив"}
          </button>

          {/* Validation hint */}
          {!isContextValid() && editable && (
            <span className="text-xs text-gray-500 dark:text-gray-400 ml-2">
              <i className="bi bi-info-circle" aria-hidden="true" /> Заполните обязательные поля для генерации
            </span>
          )}
        </div>

        {/* Inline error (validation / drive URL) */}
        {inlineError && (
          <div
            role="alert"
            className="text-danger text-sm bg-danger/8 dark:bg-danger-500/10 px-3 py-2 rounded-lg mt-2"
          >
            <i className="bi bi-exclamation-circle mr-1" aria-hidden="true" />
            {inlineError}
          </div>
        )}
      </div>

      {/* ── PDF preview ──────────────────────────────────────────────────────── */}
      {showPdf && contract.pdf_path && (
        <div className="px-8 pt-4">
          <iframe
            src={`/api/contracts/${id}/pdf?inline=1`}
            className="w-full h-[80vh] border border-gray-200 dark:border-gray-700 rounded-2xl bg-white dark:bg-gray-800"
            title="Превью PDF документа"
          />
        </div>
      )}

      {/* ── Signing section ───────────────────────────────────────────────────── */}
      {["approved", "uploaded", "signed"].includes(contract.status) && (
        <BlurFade delay={0.08}>
          <div className="px-8 pt-5">
            <SectionCard
              icon="bi-pen"
              title="Подписание и проведение сделки"
              headerRight={
                contract.status === "signed" ? (
                  <span className="flex items-center gap-1.5 text-sm text-success-700 dark:text-success-500 font-medium">
                    <i className="bi bi-check-circle-fill" aria-hidden="true" /> Проведено
                    {contract.signed_at
                      ? ` · ${new Date(contract.signed_at).toLocaleDateString("ru-RU")}`
                      : ""}
                  </span>
                ) : null
              }
            >
              {/* Attachments */}
              {(attachments ?? []).length > 0 ? (
                <ul className="space-y-1.5 mb-4" aria-label="Загруженные сканы">
                  {(attachments ?? []).map((a) => (
                    <li key={a.id} className="flex items-center gap-2 text-sm">
                      <i
                        className="bi bi-file-earmark-pdf text-danger"
                        aria-hidden="true"
                      />
                      <a
                        href={`/api/contracts/${id}/attachments/${a.id}/file?inline=1`}
                        target="_blank"
                        rel="noreferrer"
                        className="text-primary dark:text-primary-light hover:underline flex-1 truncate"
                      >
                        {a.original_name || `Файл #${a.id}`}
                      </a>
                      <span className="text-xs text-gray-400 dark:text-gray-500 shrink-0">
                        {new Date(a.created_at).toLocaleDateString("ru-RU")}
                      </span>
                      {contract.status !== "signed" && (
                        <button
                          onClick={() => deleteAttachment(a.id)}
                          className="btn-ghost text-danger text-xs shrink-0 py-0.5 px-1"
                          aria-label={`Удалить ${a.original_name ?? "файл"}`}
                        >
                          <i className="bi bi-trash" aria-hidden="true" />
                        </button>
                      )}
                    </li>
                  ))}
                </ul>
              ) : (
                contract.status !== "signed" && (
                  <EmptyState
                    icon="bi-file-earmark-break"
                    title="Скан с подписью не загружен"
                    description="Загрузите подписанный PDF или изображение"
                  />
                )
              )}

              {/* Dropzone for scan upload (replaces plain file input) */}
              {contract.status !== "signed" && (
                <div className="mt-3 space-y-3">
                  <Dropzone
                    onFiles={uploadScan}
                    accept="application/pdf,image/*"
                    disabled={uploadingScan}
                    label={uploadingScan ? "Загрузка…" : "Загрузить скан подписи"}
                    description="PDF или изображение"
                    className="max-w-md"
                  />
                  <button
                    onClick={sign}
                    disabled={actionLoading || (attachments ?? []).length === 0}
                    className="btn-primary disabled:opacity-50"
                    title={
                      (attachments ?? []).length === 0
                        ? "Сначала загрузите скан подписи"
                        : undefined
                    }
                    aria-busy={actionLoading}
                  >
                    <i className="bi bi-check2-circle" aria-hidden="true" /> Отметить сделку
                    проведённой
                  </button>
                </div>
              )}

              {contract.status === "signed" &&
                (me?.role === "admin" || me?.role === "director") && (
                  <button
                    onClick={unsign}
                    disabled={actionLoading}
                    className="btn-ghost text-sm mt-2"
                    aria-busy={actionLoading}
                  >
                    <i className="bi bi-arrow-counterclockwise" aria-hidden="true" /> Отменить
                    проведение
                  </button>
                )}
            </SectionCard>
          </div>
        </BlurFade>
      )}

      {/* ── Main grid ─────────────────────────────────────────────────────────── */}
      <div className="p-8 grid grid-cols-1 xl:grid-cols-3 gap-6">
        {/* ── Left: основные секции ───────────────────────────────────────────
            Порядок: 1. Сублицензиат → 2. Лицензия → 3. Продукты →
                     4. График платежей → 5. График актирования.
        ──────────────────────────────────────────────────────────────────── */}
        <div className="xl:col-span-2 space-y-5">
          <BlurFade delay={0.14}>
            <Section
              title="Реквизиты Сублицензиата"
              disabled={!editable}
              actions={
                contract.counterparty_id && editable ? (
                  <button
                    onClick={fillFromCounterparty}
                    className="btn-ghost text-sm"
                    disabled={actionLoading}
                    aria-busy={actionLoading}
                  >
                    <i className="bi bi-arrow-clockwise" aria-hidden="true" /> Подтянуть из
                    контрагента
                  </button>
                ) : undefined
              }
            >
              <Field
                label="Краткая форма"
                required
                value={ctx.sublicensee?.legal_form ?? ""}
                onChange={(v) => setSubField("legal_form", v)}
                placeholder="ООО / ТОО"
              />
              <Field
                label="Название компании"
                required
                value={ctx.sublicensee?.name ?? ""}
                onChange={(v) => setSubField("name", v)}
              />
              <Field
                label="Полная форма"
                value={ctx.sublicensee?.full_legal_form ?? ""}
                onChange={(v) => setSubField("full_legal_form", v)}
              />
              <Field
                label="Окончание (ое/ый)"
                value={ctx.sublicensee?.gender_ending_ое ?? "ое"}
                onChange={(v) => setSubField("gender_ending_ое", v)}
              />
              <Field
                label="Должность подписанта"
                value={ctx.sublicensee?.director_position ?? "Директор"}
                onChange={(v) => setSubField("director_position", v)}
              />
              <Field
                label="ФИО подписанта (род. падеж)"
                required
                value={ctx.sublicensee?.director_genitive ?? ""}
                onChange={(v) => setSubField("director_genitive", v)}
              />
              <Field
                label="Подписант кратко"
                required
                value={ctx.sublicensee?.director_short ?? ""}
                onChange={(v) => setSubField("director_short", v)}
              />
              <Field
                label="На основании"
                value={ctx.sublicensee?.acts_basis ?? "Устава"}
                onChange={(v) => setSubField("acts_basis", v)}
              />
              <Field
                label="Налог. номер"
                value={ctx.sublicensee?.tax_id ?? ""}
                onChange={(v) => setSubField("tax_id", v)}
                hint={ctx.sublicensee?.tax_id_label || "БИН/ИНН"}
              />
              <Field
                label="Адрес"
                value={ctx.sublicensee?.address ?? ""}
                onChange={(v) => setSubField("address", v)}
              />
              <Field
                label="Банк"
                value={ctx.sublicensee?.bank ?? ""}
                onChange={(v) => setSubField("bank", v)}
              />
              <Field
                label="Код банка (БИК)"
                value={ctx.sublicensee?.bank_code ?? ""}
                onChange={(v) => setSubField("bank_code", v)}
              />
              <Field
                label="Счёт"
                value={ctx.sublicensee?.account ?? ""}
                onChange={(v) => setSubField("account", v)}
              />
              <Field
                label="Email"
                value={ctx.sublicensee?.email ?? ""}
                onChange={(v) => setSubField("email", v)}
                type="email"
              />
              <Field
                label="Сайт"
                value={ctx.sublicensee?.website ?? ""}
                onChange={(v) => setSubField("website", v)}
              />
            </Section>
          </BlurFade>

          {/* ── Лицензия ──────────────────────────────────────────────────────
              Срок / Сумма / Сумма прописью — производные из раздела «Продукты и
              стоимость» (read-only). Источник истины — позиции; здесь только
              показываем итог, который уходит в генерацию (license.duration_months /
              price_amount_text / price_amount_words). Дата договора перенесена сюда.
          ──────────────────────────────────────────────────────────────────── */}
          <BlurFade delay={0.18}>
            <Section title="Лицензия" disabled={!editable}>
              <SelectField
                label="Тип лицензии"
                required
                value={ctx.license?.type ?? ""}
                onChange={(v) => setLicField("type", v)}
                options={LICENSE_TYPES}
                placeholder="— выберите —"
              />
              <DatePicker
                label="Дата договора"
                required
                value={ruToIso(contractDateRaw) || null}
                onChange={(iso) => setContractDate(iso ? isoToRu(iso) : "")}
                hint={
                  parseRuDate(contractDateRaw)
                    ? `В документе: «${parseRuDate(contractDateRaw)!.day}» ${parseRuDate(contractDateRaw)!.month} ${parseRuDate(contractDateRaw)!.year} г.`
                    : "Дата заключения договора"
                }
              />
              <DatePicker
                label="Дата начала действия"
                required
                value={ruToIso(ctx.license?.start_date ?? "") || null}
                onChange={(iso) => setStartDate(iso ? isoToRu(iso) : "")}
              />
              <DatePicker
                label="Дата окончания"
                value={ruToIso(ctx.license?.end_date ?? "") || null}
                onChange={(iso) => setLicField("end_date", iso ? isoToRu(iso) : "")}
                hint="Считается автоматически из даты начала + срока."
              />
              <DatePicker
                label="Дата начала внедрения"
                value={ruToIso(ctx.license?.implementation_start_date ?? "") || null}
                onChange={(iso) =>
                  setLicField("implementation_start_date", iso ? isoToRu(iso) : "")
                }
              />

              {/* Производные значения из раздела «Продукты и стоимость» (read-only) */}
              <div className="md:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-3 rounded-xl bg-gray-50 dark:bg-gray-700/40 p-3">
                <div>
                  <label className="label">Срок (месяцев)</label>
                  <div className="input bg-transparent dark:bg-transparent flex items-center text-gray-900 dark:text-gray-100">
                    {ctx.license?.duration_months ? `${ctx.license.duration_months}` : "—"}
                  </div>
                </div>
                <div>
                  <label className="label">Сумма</label>
                  <div className="input bg-transparent dark:bg-transparent flex items-center tabular-nums text-gray-900 dark:text-gray-100">
                    {ctx.license?.price_amount_text
                      ? `${ctx.license.price_amount_text}${currency ? ` ${currency}` : ""}`
                      : "—"}
                  </div>
                </div>
                <div className="sm:col-span-2">
                  <div className="flex items-end gap-2">
                    <div className="flex-1">
                      <label className="label">Сумма прописью</label>
                      <div className="input bg-transparent dark:bg-transparent flex items-center min-h-[40px] text-gray-900 dark:text-gray-100">
                        {ctx.license?.price_amount_words || "—"}
                      </div>
                    </div>
                    <button
                      type="button"
                      onClick={() => recomputeWords()}
                      className="btn-ghost mb-[2px]"
                      disabled={!editable || !ctx.license?.price_amount_text}
                      aria-label="Пересчитать сумму прописью"
                    >
                      <i className="bi bi-arrow-repeat" aria-hidden="true" /> Пересчитать
                    </button>
                  </div>
                  <div className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    Срок и сумма берутся из раздела «Продукты и стоимость».
                  </div>
                </div>
              </div>
            </Section>
          </BlurFade>

          {/* ── Продукты и стоимость ──────────────────────────────────────────── */}
          <BlurFade delay={0.2}>
            <Section title="Продукты и стоимость" disabled={false}>
              <div className="md:col-span-2">
                <ProductsSection
                  contractId={id}
                  editable={!!editable}
                  defaultCurrency={currency}
                  onDerived={handleProductsDerived}
                />
              </div>
            </Section>
          </BlurFade>

          {customVars && customVars.length > 0 && (
            <BlurFade delay={0.2}>
              <CustomFieldsSection
                variables={customVars}
                values={ctx.custom ?? {}}
                onChange={setCustomField}
                disabled={!editable}
              />
            </BlurFade>
          )}

          {/* Payment schedule */}
          <BlurFade delay={0.22}>
            <Section
              title="График платежей"
              disabled={!editable}
              actions={
                editable ? (
                  <button onClick={addPayment} className="btn-ghost text-sm">
                    <i className="bi bi-plus-lg" aria-hidden="true" /> Добавить строку
                  </button>
                ) : undefined
              }
            >
              <div className="md:col-span-2 -mx-1">
                {payments.length === 0 ? (
                  <EmptyState
                    icon="bi-calendar2-week"
                    title="График платежей не добавлен"
                    description={editable ? "Нажмите «Добавить строку» для начала" : ""}
                  />
                ) : (
                  <div className="space-y-2">
                    {payments.map((p, i) => (
                      <div
                        key={i}
                        className="grid grid-cols-12 gap-2 items-end bg-gray-50 dark:bg-gray-700/40 p-3 rounded-xl"
                      >
                        <div className="col-span-1 text-sm text-gray-500 dark:text-gray-400 pb-2">
                          № {p.number}
                        </div>
                        <div className="col-span-3">
                          <Field
                            label="Сумма"
                            value={String(p.amount ?? "")}
                            onChange={(v) => updatePayment(i, "amount", v)}
                          />
                        </div>
                        <div className="col-span-2">
                          <Field
                            label="НДС"
                            value={p.vat ?? ""}
                            onChange={(v) => updatePayment(i, "vat", v)}
                            placeholder="без НДС"
                          />
                        </div>
                        <div className="col-span-2">
                          <Field
                            label="Срок оплаты"
                            value={p.due_date ?? ""}
                            onChange={(v) => updatePayment(i, "due_date", v)}
                            placeholder="25.05.2026"
                          />
                        </div>
                        <div className="col-span-3">
                          <Field
                            label="Период"
                            value={p.period ?? ""}
                            onChange={(v) => updatePayment(i, "period", v)}
                            placeholder="01.06–01.06 (12 мес)"
                          />
                        </div>
                        <div className="col-span-1 pb-1">
                          <button
                            onClick={() => removePayment(i)}
                            className="btn-ghost text-danger text-sm p-1"
                            aria-label={`Удалить строку ${p.number}`}
                          >
                            <i className="bi bi-trash" aria-hidden="true" />
                          </button>
                        </div>
                        <div className="col-span-12">
                          <Field
                            label="Включено в стоимость"
                            value={p.included ?? ""}
                            onChange={(v) => updatePayment(i, "included", v)}
                            placeholder="Настройка ПО, 10 учётных записей"
                          />
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </Section>
          </BlurFade>

          {/* Act schedule */}
          <BlurFade delay={0.24}>
            <Section
              title="График актирования"
              disabled={!editable}
              actions={
                editable ? (
                  <button onClick={addAct} className="btn-ghost text-sm">
                    <i className="bi bi-plus-lg" aria-hidden="true" /> Добавить строку
                  </button>
                ) : undefined
              }
            >
              <div className="md:col-span-2 -mx-1">
                {acts.length === 0 ? (
                  <EmptyState
                    icon="bi-file-earmark-check"
                    title="График актирования не добавлен"
                    description={editable ? "Нажмите «Добавить строку» для начала" : ""}
                  />
                ) : (
                  <div className="space-y-2">
                    {acts.map((a, i) => (
                      <div
                        key={i}
                        className="grid grid-cols-12 gap-2 items-end bg-gray-50 dark:bg-gray-700/40 p-3 rounded-xl"
                      >
                        <div className="col-span-1 text-sm text-gray-500 dark:text-gray-400 pb-2">
                          № {a.number}
                        </div>
                        <div className="col-span-3">
                          <Field
                            label="Сумма акта"
                            value={String(a.amount ?? "")}
                            onChange={(v) => updateAct(i, "amount", v)}
                          />
                        </div>
                        <div className="col-span-2">
                          <Field
                            label="НДС"
                            value={a.vat ?? ""}
                            onChange={(v) => updateAct(i, "vat", v)}
                            placeholder="без НДС"
                          />
                        </div>
                        <div className="col-span-3">
                          <Field
                            label="Сроки подписания"
                            value={a.sign_by_date ?? ""}
                            onChange={(v) => updateAct(i, "sign_by_date", v)}
                            placeholder="30.06.2026"
                          />
                        </div>
                        <div className="col-span-2">
                          <Field
                            label="Период"
                            value={a.period ?? ""}
                            onChange={(v) => updateAct(i, "period", v)}
                            placeholder="01.06–01.06"
                          />
                        </div>
                        <div className="col-span-1 pb-1">
                          <button
                            onClick={() => removeAct(i)}
                            className="btn-ghost text-danger text-sm p-1"
                            aria-label={`Удалить строку ${a.number}`}
                          >
                            <i className="bi bi-trash" aria-hidden="true" />
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </Section>
          </BlurFade>
        </div>

        {/* ── Right: sidebar panels ────────────────────────────────────────── */}
        <div className="space-y-4">
          {/* Approval panel */}
          {contract.status !== "draft" && (
            <BlurFade delay={0.13}>
              <ApprovalPanel
                contractId={contract.id}
                contractStatus={contract.status}
                isAuthor={me?.id === contract.author_user_id}
                onChanged={() => void mutate()}
              />
            </BlurFade>
          )}

          {/* Remarks */}
          {contract.status !== "draft" && (
            <BlurFade delay={0.15}>
              <RemarksChecklist
                contractId={contract.id}
                isAuthor={me?.id === contract.author_user_id}
                onChanged={() => void mutate()}
              />
            </BlurFade>
          )}

          {/* Revisions */}
          {contract.status !== "draft" && (
            <BlurFade delay={0.17}>
              <RevisionsHistory contractId={contract.id} />
            </BlurFade>
          )}

          {/* Google Drive */}
          {(contract.status === "approved" || contract.status === "uploaded") && (
            <BlurFade delay={0.19}>
              <div className="rounded-2xl shadow-elev-1 bg-white dark:bg-gray-800 p-5 space-y-3">
                <h3 className="text-h5">Выгрузка в Google Drive</h3>
                <label className="label" htmlFor="drive-folder">
                  Ссылка на папку Drive
                </label>
                <input
                  id="drive-folder"
                  className="input"
                  placeholder="https://drive.google.com/drive/folders/…"
                  value={driveFolderUrl}
                  onChange={(e) => setDriveFolderUrl(e.target.value)}
                />
                <button
                  onClick={uploadToDrive}
                  disabled={actionLoading}
                  className="btn-primary w-full justify-center"
                  aria-busy={actionLoading}
                >
                  <i className="bi bi-cloud-arrow-up" aria-hidden="true" /> Выгрузить
                </button>
                {contract.drive_docx_url && (
                  <a
                    href={contract.drive_docx_url}
                    target="_blank"
                    rel="noreferrer"
                    className="text-sm text-primary dark:text-primary-light underline block"
                  >
                    .docx в Drive
                  </a>
                )}
                {contract.drive_pdf_url && (
                  <a
                    href={contract.drive_pdf_url}
                    target="_blank"
                    rel="noreferrer"
                    className="text-sm text-primary dark:text-primary-light underline block"
                  >
                    .pdf в Drive
                  </a>
                )}
              </div>
            </BlurFade>
          )}

          {/* Counterparty info */}
          {counterparty && (
            <BlurFade delay={0.21}>
              <div className="rounded-2xl shadow-elev-1 bg-white dark:bg-gray-800 p-5">
                <h3 className="text-xs uppercase tracking-wider font-semibold text-gray-500 dark:text-gray-400 mb-2">
                  Контрагент
                </h3>
                <div className="font-medium text-gray-900 dark:text-gray-100">
                  {counterparty.legal_form ? `${counterparty.legal_form} ` : ""}«
                  {counterparty.name}»
                </div>
                {counterparty.tax_id && (
                  <div className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    {counterparty.tax_id_label} {counterparty.tax_id}
                  </div>
                )}
              </div>
            </BlurFade>
          )}

          {/* Лицензиар (сжато) — перенесён из левой колонки */}
          <BlurFade delay={0.22}>
            <div
              className={clsx(
                "rounded-2xl shadow-elev-1 bg-white dark:bg-gray-800 p-5",
                !editable && "opacity-70",
              )}
            >
              <h3 className="text-xs uppercase tracking-wider font-semibold text-gray-500 dark:text-gray-400 mb-2">
                Реквизиты Лицензиара
              </h3>
              {licensorOpts.length > 1 ? (
                <fieldset disabled={!editable}>
                  <SelectField
                    label="Юр.лицо лицензиара"
                    value={String(ctx.licensor_override_id ?? "")}
                    onChange={(v) =>
                      setCtx((prev) => ({
                        ...prev,
                        licensor_override_id: v ? Number(v) : undefined,
                      }))
                    }
                    options={[{ value: "", label: "По умолчанию для страны" }, ...licensorOpts]}
                    hint="Если в стране несколько наших юр.лиц — можно перевыбрать"
                  />
                </fieldset>
              ) : licensorOpts[0] ? (
                <div className="text-sm font-medium text-gray-900 dark:text-gray-100">
                  {licensorOpts[0].label}
                </div>
              ) : (
                <div className="text-sm text-gray-400 dark:text-gray-500">—</div>
              )}
            </div>
          </BlurFade>

          {/* Custom fields */}
          <BlurFade delay={0.25}>
            <CustomFieldsBlock
              entityScope="contract"
              entityId={contract.id}
              extraFields={contract.extra_fields ?? {}}
              onSaved={() => void mutate()}
            />
          </BlurFade>

          {/* Audit log */}
          <BlurFade delay={0.27}>
            <div className="rounded-2xl shadow-elev-1 bg-white dark:bg-gray-800 p-5">
              <div className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 font-semibold mb-3">
                <i className="bi bi-journal-text mr-1" aria-hidden="true" />
                История изменений
              </div>
              <AuditLogTimeline entityType="contract" entityId={contract.id} />
            </div>
          </BlurFade>
        </div>
      </div>

      {/* ── AI analysis modal ────────────────────────────────────────────────── */}
      <ContractAnalysisModal
        open={aiAnalysisOpen}
        onClose={() => setAiAnalysisOpen(false)}
        contractId={contract.id}
        contractTitle={contract.number ?? `Договор #${contract.id}`}
      />
    </>
  );
}

// ─── Section (form card) ───────────────────────────────────────────────────────

function Section({
  title,
  children,
  disabled,
  actions,
}: {
  title: string;
  children: React.ReactNode;
  disabled?: boolean;
  actions?: React.ReactNode;
}) {
  return (
    <div
      className={clsx(
        "rounded-2xl shadow-elev-1 bg-white dark:bg-gray-800 p-5",
        disabled && "opacity-70",
      )}
    >
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-h5">{title}</h3>
        {actions}
      </div>
      <fieldset disabled={disabled} className="grid grid-cols-1 md:grid-cols-2 gap-3">
        {children}
      </fieldset>
    </div>
  );
}

// ─── SectionCard (non-form card with icon) ────────────────────────────────────

function SectionCard({
  icon,
  title,
  headerRight,
  children,
}: {
  icon: string;
  title: string;
  headerRight?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <div className="rounded-2xl shadow-elev-1 bg-white dark:bg-gray-800 p-5">
      <div className="flex items-center justify-between mb-4">
        <h3 className="font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
          <i className={clsx("bi", icon, "text-primary dark:text-primary-light")} aria-hidden="true" />
          {title}
        </h3>
        {headerRight}
      </div>
      {children}
    </div>
  );
}
