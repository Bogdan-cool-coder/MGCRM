"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import useSWR, { mutate as globalMutate } from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import {
  JournalLineEditor,
  makeInitialLines,
  journalLinesToPayload,
  type JournalLineDraft,
} from "@/components/Finance/JournalLineEditor";
import { DatePicker } from "@/components/ui/DatePicker";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError, fetcher } from "@/lib/api";
import type { FinLegalEntity, UserRole } from "@/lib/types";

const ALLOWED_ROLES: UserRole[] = ["accountant", "cfo", "admin"];

function extractErrMsg(err: unknown): string {
  if (err instanceof ApiError) {
    const d = err.detail;
    if (typeof d === "object" && d !== null) {
      if ("detail" in d) return String((d as Record<string, unknown>)["detail"]);
    }
    if (typeof d === "string") return d;
  }
  return "Произошла ошибка";
}

function todayStr(): string {
  return new Date().toISOString().slice(0, 10);
}

export default function NewJournalPage() {
  const router = useRouter();
  const { toast } = useToast();

  const [entityId, setEntityId] = useState("");
  const [date, setDate] = useState(todayStr());
  const [memo, setMemo] = useState("");
  const [lines, setLines] = useState<JournalLineDraft[]>(makeInitialLines);
  const [saving, setSaving] = useState(false);
  const [posting, setPosting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);

  const funcCurrency = entities?.find((e) => String(e.id) === entityId)?.functional_currency ?? "RUB";

  const dtSum = lines.filter((l) => l.side === "dt").reduce((a, l) => a + (parseFloat(l.amount) || 0), 0);
  const ktSum = lines.filter((l) => l.side === "kt").reduce((a, l) => a + (parseFloat(l.amount) || 0), 0);
  const balanced = Math.abs(dtSum - ktSum) < 0.01 && dtSum > 0;

  const formValid = entityId && date && memo.trim() && lines.length >= 2 && lines.every((l) => l.account_gl_id && parseFloat(l.amount) > 0);

  function validate(): string | null {
    if (!entityId) return "Выберите юрлицо";
    if (!date) return "Укажите дату";
    if (!memo.trim()) return "Укажите обоснование";
    if (lines.length < 2) return "Нужно минимум 2 строки";
    for (const l of lines) {
      if (!l.account_gl_id) return "Выберите GL-счёт для всех строк";
      if (!(parseFloat(l.amount) > 0)) return "Сумма должна быть больше 0";
    }
    return null;
  }

  async function handleSave(andPost = false) {
    const validErr = validate();
    if (validErr) { setError(validErr); return; }

    setError(null);
    if (andPost) setPosting(true); else setSaving(true);

    try {
      const payload = {
        legal_entity_id: Number(entityId),
        date,
        memo: memo.trim(),
        lines: journalLinesToPayload(lines),
      };
      const journal = await api<{ id: number }>("/finance/journals", {
        method: "POST",
        body: payload,
        query: { auto_post: false },
      });

      if (andPost && journal.id) {
        await api(`/finance/journals/${journal.id}/post`, { method: "POST" });
      }

      await globalMutate("/api/finance/journals");
      toast.success(andPost ? "Проводка проведена" : "Черновик сохранён");
      router.push(`/finance/journals/${journal.id}`);
    } catch (err) {
      const msg = extractErrMsg(err);
      setError(msg);
      toast.error("Ошибка сохранения", msg);
    } finally {
      setSaving(false);
      setPosting(false);
    }
  }

  return (
    <RoleGate
      allowed={ALLOWED_ROLES}
      fallback={
        <div className="p-8 text-center">
          <p className="text-sm text-gray-500 dark:text-gray-400">Нет доступа</p>
        </div>
      }
    >
      <div className="flex flex-col h-full">
        <PageHeader
          title="Новая ручная проводка"
          actions={
            <Link href="/finance/journals" className="btn-ghost">
              <i className="bi bi-arrow-left mr-1" />
              Журналы
            </Link>
          }
        />

        <div className="p-6 flex flex-col gap-5 max-w-4xl">
          {/* Заголовок проводки — card v2 */}
          <div className="card rounded-2xl shadow-elev-1 p-5">
            <h2 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-4">
              Заголовок
            </h2>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="label">Юрлицо *</label>
                <select
                  className="input"
                  value={entityId}
                  onChange={(e) => setEntityId(e.target.value)}
                >
                  <option value="">Выберите юрлицо...</option>
                  {entities?.map((e) => (
                    <option key={e.id} value={e.id}>{e.name}</option>
                  ))}
                </select>
              </div>
              <div>
                <DatePicker
                  label="Дата *"
                  value={date}
                  onChange={(v) => setDate(v ?? "")}
                  required
                />
              </div>
            </div>
            <div className="mt-4">
              <label className="label">Обоснование *</label>
              <textarea
                className="input min-h-[72px] resize-y"
                placeholder="Укажи причину корректировки..."
                value={memo}
                onChange={(e) => setMemo(e.target.value)}
              />
            </div>
          </div>

          {/* Строки проводки — card v2 */}
          <div className="card rounded-2xl shadow-elev-1 p-5">
            <h2 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-4">
              Строки проводки
            </h2>
            <JournalLineEditor
              lines={lines}
              onChange={setLines}
              funcCurrency={funcCurrency}
            />

            {/* Индикатор баланса */}
            {lines.some((l) => parseFloat(l.amount) > 0) && (
              <div className={`mt-4 text-xs font-medium flex items-center gap-2 ${balanced ? "text-success" : "text-gray-400 dark:text-gray-500"}`}>
                <span className={`w-2 h-2 rounded-full ${balanced ? "bg-success" : "bg-gray-300 dark:bg-gray-600"}`} />
                {balanced
                  ? "Дт = Кт — проводка сбалансирована"
                  : `Дт ${dtSum.toLocaleString("ru-RU")} / Кт ${ktSum.toLocaleString("ru-RU")} — не сбалансировано`
                }
              </div>
            )}
          </div>

          {/* Ошибка */}
          {error && (
            <p className="text-sm text-danger">{error}</p>
          )}

          {/* Кнопки */}
          <div className="flex items-center gap-2">
            <Link href="/finance/journals" className="btn-ghost">Отмена</Link>
            <button
              type="button"
              className="btn-secondary"
              disabled={saving || posting || !formValid}
              onClick={() => handleSave(false)}
            >
              {saving ? "Сохранение..." : "Сохранить черновик"}
            </button>
            <button
              type="button"
              className="btn-primary"
              disabled={saving || posting || !formValid || !balanced}
              onClick={() => handleSave(true)}
              title={!balanced ? "Сначала сбалансируй Σ Дт = Σ Кт" : undefined}
            >
              {posting ? "Проведение..." : "Провести"}
            </button>
          </div>
        </div>
      </div>
    </RoleGate>
  );
}
