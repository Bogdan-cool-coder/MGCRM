"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { Modal } from "@/components/Modal";
import { CategoryBadge } from "@/components/Templates/CategoryBadge";
import { VariablesModal } from "@/components/Templates/VariablesModal";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { FloatingInput, FloatingTextarea } from "@/components/ui/FloatingInput";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError, fetcher } from "@/lib/api";
import {
  TEMPLATE_CATEGORY_ORDER,
  getCategoryDisplay,
  isMainCategory,
  type TemplateCategory,
} from "@/lib/templateCategories";
import type { TemplateDetail, TemplateInfo } from "@/lib/types";

interface MasterSkeletonInfo {
  version: string;
  size: number;
  is_custom: boolean;
  onlyoffice_ready: boolean;
}

type CategoryTab = "main" | "other" | "all";

const CATEGORY_TABS: { value: CategoryTab; label: string }[] = [
  { value: "main", label: "Основные" },
  { value: "other", label: "Прочие" },
  { value: "all", label: "Все" },
];

function categorySortKey(cat: string | null): number {
  if (!cat) return TEMPLATE_CATEGORY_ORDER.length;
  const idx = TEMPLATE_CATEGORY_ORDER.indexOf(cat as TemplateCategory);
  return idx === -1 ? TEMPLATE_CATEGORY_ORDER.length : idx;
}

// Soft-бейдж для kind (docx / yaml / md)
function KindBadge({ kind }: { kind: string }) {
  const map: Record<string, string> = {
    docx: "bg-primary-light/10 text-primary dark:bg-primary/20 dark:text-blue-300",
    yaml: "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400",
    md:   "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300",
  };
  const cls = map[kind.toLowerCase()] ?? map.md;
  return (
    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium uppercase ${cls}`}>
      {kind}
    </span>
  );
}

export default function TemplatesPage() {
  const { data, mutate } = useSWR<TemplateInfo[]>("/templates", fetcher);
  const { data: masterInfo, mutate: mutateMaster } = useSWR<MasterSkeletonInfo>(
    "/templates/master-skeleton/info",
    fetcher,
  );
  const { toast } = useToast();

  // YAML/MD редактор (для product/country)
  const [openCode, setOpenCode] = useState<string | null>(null);
  const [detail, setDetail] = useState<TemplateDetail | null>(null);
  const [content, setContent] = useState("");
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [category, setCategory] = useState<string | null>(null);
  const [productCodes, setProductCodes] = useState<string[]>([]);
  const [countryCodes, setCountryCodes] = useState<string[]>([]);
  const [clientCategoryCodes, setClientCategoryCodes] = useState<string[]>([]);

  const [tab, setTab] = useState<CategoryTab>("main");
  const [varsModalCode, setVarsModalCode] = useState<string | null>(null);

  const fileRef = useRef<HTMLInputElement | null>(null);
  const [uploading, setUploading] = useState(false);
  const [previewLoading, setPreviewLoading] = useState(false);

  useEffect(() => {
    if (!openCode) return;
    setLoading(true);
    setError(null);
    api<TemplateDetail>(`/templates/by-code/${openCode}`)
      .then((d) => {
        setDetail(d);
        setContent(d.content);
        setCategory(d.category);
        setProductCodes(d.product_codes ?? []);
        setCountryCodes(d.country_codes ?? []);
        setClientCategoryCodes(d.client_category_codes ?? []);
      })
      .catch((e) => setError(String(e)))
      .finally(() => setLoading(false));
  }, [openCode]);

  function arraysEqual(a: string[], b: string[]): boolean {
    if (a.length !== b.length) return false;
    return a.every((v, i) => v === b[i]);
  }

  function isDirty(): boolean {
    if (!detail) return false;
    if (content !== detail.content) return true;
    if ((category ?? null) !== (detail.category ?? null)) return true;
    if (!arraysEqual(productCodes, detail.product_codes ?? [])) return true;
    if (!arraysEqual(countryCodes, detail.country_codes ?? [])) return true;
    if (!arraysEqual(clientCategoryCodes, detail.client_category_codes ?? [])) return true;
    return false;
  }

  async function save(): Promise<boolean> {
    if (!detail) return false;
    setSaving(true);
    setError(null);
    try {
      const body: Record<string, unknown> = {};
      if (content !== detail.content) body.content = content;
      if ((category ?? null) !== (detail.category ?? null)) body.category = category;
      if (!arraysEqual(productCodes, detail.product_codes ?? [])) body.product_codes = productCodes;
      if (!arraysEqual(countryCodes, detail.country_codes ?? [])) body.country_codes = countryCodes;
      if (!arraysEqual(clientCategoryCodes, detail.client_category_codes ?? [])) {
        body.client_category_codes = clientCategoryCodes;
      }
      await api(`/templates/by-code/${detail.code}`, { method: "PATCH", body });
      await mutate();
      const fresh = await api<TemplateDetail>(`/templates/by-code/${detail.code}`);
      setDetail(fresh);
      setContent(fresh.content);
      setCategory(fresh.category);
      setProductCodes(fresh.product_codes ?? []);
      setCountryCodes(fresh.country_codes ?? []);
      setClientCategoryCodes(fresh.client_category_codes ?? []);
      toast.success("Шаблон сохранён");
      return true;
    } catch (err) {
      const msg =
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Ошибка";
      setError(msg);
      toast.error(msg);
      return false;
    } finally {
      setSaving(false);
    }
  }

  function close() {
    setOpenCode(null);
    setDetail(null);
    setContent("");
    setCategory(null);
    setProductCodes([]);
    setCountryCodes([]);
    setClientCategoryCodes([]);
  }

  async function uploadMaster(file: File) {
    if (!file.name.toLowerCase().endsWith(".docx")) {
      toast.error("Только .docx файлы");
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      toast.error("Файл слишком большой (максимум ~10 MB)");
      return;
    }
    setUploading(true);
    try {
      const form = new FormData();
      form.append("file", file);
      const res = await fetch("/api/templates/master-skeleton/upload", {
        method: "POST",
        body: form,
        credentials: "same-origin",
      });
      if (!res.ok) {
        const txt = await res.text();
        let errMsg = txt;
        try {
          const parsed: unknown = JSON.parse(txt);
          if (parsed && typeof parsed === "object" && "detail" in parsed) {
            errMsg = String((parsed as { detail: unknown }).detail);
          }
        } catch {
          /* keep txt */
        }
        if (res.status === 413) {
          errMsg = "Файл слишком большой. Уменьшите размер шаблона или обратитесь к администратору.";
        }
        toast.error(errMsg);
        return;
      }
      await mutateMaster();
      toast.success("Шаблон обновлён", "Новые договоры будут генерироваться по нему.");
    } catch {
      toast.error("Ошибка соединения. Проверьте сеть и попробуйте ещё раз.");
    } finally {
      setUploading(false);
    }
  }

  async function revertMaster() {
    if (!confirm("Сбросить кастомный шаблон → вернуться к версии из репозитория?")) return;
    setUploading(true);
    try {
      await api("/templates/master-skeleton/upload", { method: "DELETE" });
      await mutateMaster();
      toast.success("Возвращена версия из репозитория.");
    } catch (err) {
      const msg =
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Ошибка";
      toast.error(msg);
    } finally {
      setUploading(false);
    }
  }

  async function previewMaster() {
    setPreviewLoading(true);
    try {
      const res = await fetch("/api/templates/by-code/master_skeleton/preview", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({}),
        credentials: "same-origin",
      });
      if (!res.ok) {
        const txt = await res.text();
        let msg = txt;
        try {
          msg = String(JSON.parse(txt).detail ?? txt);
        } catch {
          /* keep text */
        }
        toast.error(`Не удалось сгенерировать preview: ${msg}`);
        return;
      }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      window.open(url, "_blank", "noopener,noreferrer");
    } finally {
      setPreviewLoading(false);
    }
  }

  // Фильтрация и сортировка шаблонов
  const docxTemplates = useMemo<TemplateInfo[]>(() => {
    if (!data) return [];
    return data.filter((t) => !t.code.startsWith("product_") && !t.code.startsWith("country_"));
  }, [data]);

  const filteredDocxTemplates = useMemo<TemplateInfo[]>(() => {
    if (tab === "all") return docxTemplates;
    if (tab === "main") return docxTemplates.filter((t) => isMainCategory(t.category));
    return docxTemplates.filter((t) => !isMainCategory(t.category));
  }, [docxTemplates, tab]);

  const sortedDocxTemplates = useMemo<TemplateInfo[]>(() => {
    return [...filteredDocxTemplates].sort((a, b) => {
      const byCat = categorySortKey(a.category) - categorySortKey(b.category);
      if (byCat !== 0) return byCat;
      return a.code.localeCompare(b.code);
    });
  }, [filteredDocxTemplates]);

  const docxCounts = useMemo(() => {
    const total = docxTemplates.length;
    const main = docxTemplates.filter((t) => isMainCategory(t.category)).length;
    return { total, main, other: total - main };
  }, [docxTemplates]);

  // Колонки для секции документов
  const docxColumns: DataTableColumn<TemplateInfo>[] = [
    {
      key: "code",
      header: "Код",
      width: "12rem",
      skeletonWidth: "80%",
      render: (t) => (
        <span className="font-mono text-xs text-gray-700 dark:text-gray-300">{t.code}</span>
      ),
    },
    {
      key: "title",
      header: "Название",
      skeletonWidth: "70%",
      render: (t) => (
        <span className="font-medium text-gray-900 dark:text-gray-100">{t.title}</span>
      ),
    },
    {
      key: "category",
      header: "Категория",
      width: "11rem",
      skeletonWidth: "60%",
      render: (t) => <CategoryBadge category={t.category} />,
    },
    {
      key: "bindings",
      header: "Привязки",
      skeletonWidth: "50%",
      render: (t) => <BindingsSummary t={t} />,
    },
    {
      key: "version",
      header: "Версия",
      width: "6rem",
      align: "center",
      skeletonWidth: "40%",
      render: (t) => (
        <span className="text-xs text-gray-500 dark:text-gray-400">v{t.version}</span>
      ),
    },
  ];

  // Колонки для YAML/MD секций
  const yamlColumns: DataTableColumn<TemplateInfo>[] = [
    {
      key: "code",
      header: "Код",
      width: "14rem",
      skeletonWidth: "75%",
      render: (t) => (
        <span className="font-mono text-xs text-gray-700 dark:text-gray-300">{t.code}</span>
      ),
    },
    {
      key: "title",
      header: "Название",
      skeletonWidth: "65%",
      render: (t) => (
        <span className="font-medium text-gray-900 dark:text-gray-100">{t.title}</span>
      ),
    },
    {
      key: "kind",
      header: "Тип",
      width: "6rem",
      skeletonWidth: "40%",
      render: (t) => <KindBadge kind={t.kind} />,
    },
    {
      key: "version",
      header: "Версия",
      width: "6rem",
      align: "center",
      skeletonWidth: "40%",
      render: (t) => (
        <span className="text-xs text-gray-500 dark:text-gray-400">v{t.version}</span>
      ),
    },
    {
      key: "updated_at",
      header: "Обновлён",
      width: "10rem",
      skeletonWidth: "60%",
      render: (t) => (
        <span className="text-xs text-gray-500 dark:text-gray-400">
          {new Date(t.updated_at).toLocaleString("ru-RU")}
        </span>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title="Шаблоны документов"
        description="Master Skeleton (Word-документ с jinja2-тегами) + продуктовые и страновые YAML-конфиги."
      />
      <div className="p-8 space-y-8">

        {/* === Master Skeleton (.docx) === */}
        <section>
          <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100 mb-1">
            Master Skeleton (.docx)
          </h2>
          <p className="text-sm text-gray-500 dark:text-gray-400 mb-4">
            Шаблон договора в формате Word с jinja2-разметкой. Редактируется в обычном Word.
            Изменения применяются сразу ко всем новым генерациям.
          </p>
          <div className="card rounded-2xl shadow-elev-1 p-5 space-y-4">
            {/* Мета-инфо */}
            {masterInfo ? (
              <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                <InfoTile
                  icon="bi-hash"
                  label="Версия (sha)"
                  value={masterInfo.version}
                />
                <InfoTile
                  icon="bi-file-earmark"
                  label="Размер"
                  value={`${(masterInfo.size / 1024).toFixed(1)} КБ`}
                />
                <InfoTile
                  icon={masterInfo.is_custom ? "bi-cloud-upload" : "bi-code-slash"}
                  label="Источник"
                  value={masterInfo.is_custom ? "Загружен через UI" : "Из репозитория"}
                  badge={
                    masterInfo.is_custom
                      ? "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400"
                      : "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300"
                  }
                />
              </div>
            ) : (
              <div className="grid grid-cols-3 gap-4">
                {[1, 2, 3].map((i) => (
                  <div key={i} className="animate-pulse h-12 rounded-lg bg-gray-100 dark:bg-gray-800" />
                ))}
              </div>
            )}

            {/* Действия */}
            <div className="flex flex-wrap gap-2 pt-1">
              {masterInfo?.onlyoffice_ready && (
                <a href="/admin/templates/master-skeleton/edit" className="btn-primary">
                  <i className="bi bi-pencil-square mr-1" /> Редактировать в браузере
                </a>
              )}
              <button onClick={previewMaster} disabled={previewLoading} className="btn-secondary">
                <i className="bi bi-eye mr-1" />
                {previewLoading ? "Генерация…" : "Preview"}
              </button>
              <button onClick={() => setVarsModalCode("master_skeleton")} className="btn-secondary">
                <i className="bi bi-braces mr-1" /> Переменные
              </button>
              <a
                href="/api/templates/master-skeleton/download"
                target="_blank"
                rel="noreferrer"
                className="btn-secondary"
              >
                <i className="bi bi-file-earmark-arrow-down mr-1" /> Скачать .docx
              </a>
              <button
                onClick={() => fileRef.current?.click()}
                disabled={uploading}
                className="btn-secondary"
              >
                <i className="bi bi-cloud-arrow-up mr-1" />
                {uploading ? "Загрузка…" : "Загрузить новый .docx"}
              </button>
              {masterInfo?.is_custom && (
                <button
                  onClick={revertMaster}
                  disabled={uploading}
                  className="btn-ghost text-danger text-sm"
                >
                  <i className="bi bi-arrow-counterclockwise mr-1" /> Сбросить к версии из репо
                </button>
              )}
              <input
                ref={fileRef}
                type="file"
                accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                className="hidden"
                onChange={(e) => {
                  const f = e.target.files?.[0];
                  if (f) uploadMaster(f);
                  e.currentTarget.value = "";
                }}
              />
            </div>

            {/* Инструкция */}
            <details className="text-xs text-gray-500 dark:text-gray-400 group">
              <summary className="cursor-pointer select-none hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
                <i className="bi bi-question-circle mr-1" /> Как редактировать шаблон
              </summary>
              <ol className="list-decimal pl-5 mt-2 space-y-1 text-gray-600 dark:text-gray-400">
                <li>Скачайте текущий .docx</li>
                <li>Откройте в Word или LibreOffice</li>
                <li>В местах подстановки оставляйте теги вида <code>{"{{ licensor.name }}"}</code></li>
                <li>Для таблицы платежей: открывающая <code>{"{%tr for payment in license.payment_schedule %}"}</code>, контентная, закрывающая <code>{"{%tr endfor %}"}</code></li>
                <li>Сохраните и загрузите обратно через эту страницу</li>
                <li>Сгенерируйте тестовый договор — проверьте что всё на месте</li>
              </ol>
            </details>
          </div>
        </section>

        {/* === Документы по категориям === */}
        <section>
          <div className="flex items-end justify-between mb-1 flex-wrap gap-2">
            <div>
              <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">
                Документы по категориям
              </h2>
              <p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                Договоры и сопутствующие документы с привязками к продуктам, странам и категориям клиентов.
              </p>
            </div>
          </div>

          {/* Tabs */}
          <div className="flex gap-1 border-b border-gray-200 dark:border-gray-700 mb-4">
            {CATEGORY_TABS.map((t) => {
              const active = tab === t.value;
              const count =
                t.value === "all" ? docxCounts.total :
                t.value === "main" ? docxCounts.main :
                docxCounts.other;
              return (
                <button
                  key={t.value}
                  type="button"
                  onClick={() => setTab(t.value)}
                  className={[
                    "px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors",
                    active
                      ? "border-primary text-primary"
                      : "border-transparent text-gray-500 hover:text-primary dark:text-gray-400 dark:hover:text-blue-300",
                  ].join(" ")}
                >
                  {t.label}{" "}
                  <span className="text-xs opacity-60">({count})</span>
                </button>
              );
            })}
          </div>

          <DataTable
            columns={docxColumns}
            rows={data ? sortedDocxTemplates : undefined}
            getRowKey={(t) => t.id}
            onRowClick={(t) => setOpenCode(t.code)}
            rowActions={(t) => (
              <button
                type="button"
                onClick={(e) => { e.stopPropagation(); setVarsModalCode(t.code); }}
                className="btn-ghost text-xs"
                title="Доступные переменные"
              >
                <i className="bi bi-braces" />
              </button>
            )}
            emptyIcon="bi-file-earmark-text"
            emptyTitle="Нет документов"
            emptyText="Шаблоны в этой вкладке не найдены"
            ariaLabel="Документы по категориям"
          />
        </section>

        {/* === Продукты (YAML) === */}
        <section>
          <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100 mb-1">
            Продукты
          </h2>
          <p className="text-sm text-gray-500 dark:text-gray-400 mb-4">
            YAML-конфиги с описанием модулей, брифа, тех. параметров, цепочки прав.
          </p>
          <DataTable
            columns={yamlColumns}
            rows={data ? (data.filter((t) => t.code.startsWith("product_"))) : undefined}
            getRowKey={(t) => t.id}
            onRowClick={(t) => setOpenCode(t.code)}
            emptyIcon="bi-box"
            emptyTitle="Нет продуктов"
            ariaLabel="Продуктовые конфиги"
          />
        </section>

        {/* === Страны (YAML) === */}
        <section>
          <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100 mb-1">
            Страны
          </h2>
          <p className="text-sm text-gray-500 dark:text-gray-400 mb-4">
            YAML с правовой базой, валютой, НДС, дефолтными значениями.
          </p>
          <DataTable
            columns={yamlColumns}
            rows={data ? (data.filter((t) => t.code.startsWith("country_"))) : undefined}
            getRowKey={(t) => t.id}
            onRowClick={(t) => setOpenCode(t.code)}
            emptyIcon="bi-globe"
            emptyTitle="Нет стран"
            ariaLabel="Страновые конфиги"
          />
        </section>
      </div>

      {/* === Модалка редактирования шаблона === */}
      <Modal
        open={!!openCode}
        onClose={close}
        onTrySave={save}
        isDirty={isDirty()}
        title={detail?.title ?? "Шаблон"}
        description={
          detail
            ? `${detail.code} • версия ${detail.version} • ${detail.kind.toUpperCase()}`
            : ""
        }
        width="xl"
        footer={
          <>
            <button className="btn-secondary" onClick={close}>Закрыть</button>
            <button
              onClick={save}
              disabled={saving || !isDirty()}
              className="btn-primary"
            >
              {saving ? "Сохранение…" : "Сохранить"}
            </button>
          </>
        }
      >
        {loading && (
          <div className="space-y-3">
            {[1, 2, 3].map((i) => (
              <div key={i} className="animate-pulse h-10 rounded-lg bg-gray-100 dark:bg-gray-800" />
            ))}
          </div>
        )}
        {error && (
          <div className="text-danger text-sm bg-danger/10 dark:bg-danger-500/10 px-3 py-2 rounded-lg mb-3">
            <i className="bi bi-exclamation-triangle mr-1" /> {error}
          </div>
        )}
        {detail && (
          <div className="space-y-6">
            {/* Категория и привязки */}
            <div className="space-y-4">
              <div className="flex items-center justify-between gap-2">
                <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  Категория и привязки
                </h3>
                <button
                  type="button"
                  className="btn-ghost text-xs"
                  onClick={() => setVarsModalCode(detail.code)}
                >
                  <i className="bi bi-braces mr-1" /> Доступные переменные
                </button>
              </div>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="label">Категория</label>
                  <select
                    className="input"
                    value={category ?? ""}
                    onChange={(e) => setCategory(e.target.value || null)}
                  >
                    <option value="">Без категории</option>
                    {TEMPLATE_CATEGORY_ORDER.map((code) => (
                      <option key={code} value={code}>
                        {getCategoryDisplay(code).label}
                      </option>
                    ))}
                  </select>
                  <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    Определяет тип документа и применимый ApprovalRoute.
                  </p>
                </div>
                <div className="flex items-center">
                  <CategoryBadge category={category} />
                </div>
              </div>
              <ChipsInput
                label="Продукты (коды)"
                values={productCodes}
                onChange={setProductCodes}
                placeholder="macrocrm, macrosales, macroerp"
                hint="Пусто = шаблон подходит для всех продуктов."
              />
              <ChipsInput
                label="Страны (коды)"
                values={countryCodes}
                onChange={setCountryCodes}
                placeholder="kz, uz, ru"
                hint="Пусто = все страны. Двухбуквенные ISO-коды (нижний регистр)."
              />
              <ChipsInput
                label="Категории клиентов"
                values={clientCategoryCodes}
                onChange={setClientCategoryCodes}
                placeholder="L, M, S1, S2"
                hint="Пусто = все категории."
              />
            </div>

            {/* Контент */}
            <div className="space-y-3 pt-4 border-t border-gray-200 dark:border-gray-700">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Содержимое
              </h3>
              <div className="text-xs text-gray-500 dark:text-gray-400 leading-relaxed bg-gray-50 dark:bg-gray-800/50 p-3 rounded-lg">
                {detail.kind === "yaml"
                  ? "YAML-конфиг. Соблюдайте отступы (2 пробела). Перед сохранением мы проверим, что YAML валиден."
                  : "Содержимое шаблона."}
              </div>
              <FloatingTextarea
                label="Содержимое шаблона"
                value={content}
                onChange={(e) => setContent(e.target.value)}
                rows={24}
                className="[&_textarea]:font-mono [&_textarea]:text-xs"
                spellCheck={false}
              />
            </div>
          </div>
        )}
      </Modal>

      <VariablesModal
        open={!!varsModalCode}
        onClose={() => setVarsModalCode(null)}
        templateCode={varsModalCode}
      />
    </>
  );
}

// ─── Вспомогательные компоненты ───────────────────────────────────────────────

function InfoTile({
  icon,
  label,
  value,
  badge,
}: {
  icon: string;
  label: string;
  value: string;
  badge?: string;
}) {
  return (
    <div className="flex items-start gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/50">
      <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white dark:bg-gray-700 shadow-sm">
        <i className={`bi ${icon} text-primary dark:text-blue-300`} />
      </div>
      <div className="min-w-0">
        <p className="text-xs text-gray-500 dark:text-gray-400">{label}</p>
        {badge ? (
          <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium mt-0.5 ${badge}`}>
            {value}
          </span>
        ) : (
          <p className="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{value}</p>
        )}
      </div>
    </div>
  );
}

function BindingsSummary({ t }: { t: TemplateInfo }) {
  const parts: string[] = [];
  if (t.product_codes.length) parts.push(`Продукты: ${t.product_codes.join(", ")}`);
  if (t.country_codes.length) parts.push(`Страны: ${t.country_codes.join(", ").toUpperCase()}`);
  if (t.client_category_codes.length) parts.push(`Клиенты: ${t.client_category_codes.join(", ")}`);
  if (parts.length === 0) {
    return <span className="text-xs text-gray-400 dark:text-gray-500 italic">Все</span>;
  }
  return <span className="text-xs text-gray-600 dark:text-gray-400">{parts.join(" • ")}</span>;
}

function ChipsInput({
  label,
  values,
  onChange,
  placeholder,
  hint,
}: {
  label: string;
  values: string[];
  onChange: (vs: string[]) => void;
  placeholder?: string;
  hint?: string;
}) {
  const [draft, setDraft] = useState("");

  function commit(raw: string) {
    const next = raw
      .split(/[,\n]/)
      .map((s) => s.trim())
      .filter(Boolean)
      .filter((s) => !values.includes(s));
    if (next.length === 0) return;
    onChange([...values, ...next]);
    setDraft("");
  }

  function remove(v: string) {
    onChange(values.filter((x) => x !== v));
  }

  return (
    <div>
      <label className="label">{label}</label>
      {values.length > 0 && (
        <div className="flex flex-wrap gap-1 mb-2">
          {values.map((v) => (
            <span
              key={v}
              className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-primary-light/10 text-primary dark:bg-primary/20 dark:text-blue-300 text-xs font-medium"
            >
              {v}
              <button
                type="button"
                onClick={() => remove(v)}
                className="text-primary/60 hover:text-danger dark:text-blue-300/60 dark:hover:text-danger transition-colors"
                aria-label={`Убрать ${v}`}
              >
                <i className="bi bi-x" />
              </button>
            </span>
          ))}
        </div>
      )}
      {values.length === 0 && (
        <p className="text-xs text-gray-400 dark:text-gray-500 italic mb-2">
          пусто = все
        </p>
      )}
      <FloatingInput
        label={placeholder ?? "Добавить значение…"}
        value={draft}
        onChange={(e) => setDraft(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === "Enter" || e.key === ",") {
            e.preventDefault();
            commit(draft);
          } else if (e.key === "Backspace" && !draft && values.length) {
            onChange(values.slice(0, -1));
          }
        }}
        onBlur={() => { if (draft.trim()) commit(draft); }}
      />
      {hint && (
        <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">{hint}</p>
      )}
    </div>
  );
}
