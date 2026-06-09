"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { ApiError } from "@/lib/api";
import type { FormField, PublicForm } from "@/lib/types";

type FieldValues = Record<string, string>;

// Простая проверка email
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

// Локальный fetch — без credentials (публичный endpoint, чужой пользователь).
async function publicFetch<T>(path: string, init?: RequestInit): Promise<T> {
  const url = path.startsWith("/api") ? path : `/api${path}`;
  const res = await fetch(url, {
    ...init,
    headers: {
      ...(init?.body ? { "Content-Type": "application/json" } : {}),
      ...(init?.headers ?? {}),
    },
  });
  if (!res.ok) {
    let detail: unknown = await res.text();
    try {
      detail = JSON.parse(detail as string);
    } catch {
      /* keep raw */
    }
    throw new ApiError(res.status, detail);
  }
  if (res.status === 204) return undefined as T;
  const ct = res.headers.get("content-type") ?? "";
  if (ct.includes("application/json")) return (await res.json()) as T;
  return (await res.text()) as T;
}

export default function PublicFormPage() {
  const params = useParams();
  const slugRaw = params?.slug;
  const slug = Array.isArray(slugRaw) ? slugRaw[0] : (slugRaw ?? "");

  const [form, setForm] = useState<PublicForm | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const [values, setValues] = useState<FieldValues>({});
  const [touched, setTouched] = useState<Record<string, boolean>>({});
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [submitted, setSubmitted] = useState<{ thank_you_text: string | null } | null>(null);

  useEffect(() => {
    if (!slug) return;
    let cancelled = false;
    setLoading(true);
    setLoadError(null);
    publicFetch<PublicForm>(`/forms/public/${slug}`)
      .then((data) => {
        if (cancelled) return;
        setForm(data);
        // Инициализация значений пустыми строками
        const init: FieldValues = {};
        for (const f of data.fields) init[f.name] = "";
        setValues(init);
      })
      .catch((e) => {
        if (cancelled) return;
        if (e instanceof ApiError && e.status === 404) {
          setLoadError("Форма не найдена");
        } else {
          setLoadError("Не удалось загрузить форму");
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [slug]);

  function setField(name: string, value: string) {
    setValues((prev) => ({ ...prev, [name]: value }));
  }

  function fieldError(f: FormField): string | null {
    const v = (values[f.name] ?? "").trim();
    if (f.required && !v) return "Обязательное поле";
    if (v && f.type === "email" && !EMAIL_RE.test(v)) return "Введите корректный email";
    return null;
  }

  function validate(): string | null {
    if (!form) return null;
    const allTouched: Record<string, boolean> = {};
    for (const f of form.fields) allTouched[f.name] = true;
    setTouched(allTouched);
    for (const f of form.fields) {
      const err = fieldError(f);
      if (err) return `«${f.label}»: ${err}`;
    }
    return null;
  }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!form) return;
    const err = validate();
    if (err) {
      setSubmitError(err);
      return;
    }
    setSubmitting(true);
    setSubmitError(null);
    try {
      // Отправляем только non-empty значения, чтобы для optional пустых полей
      // не было лишнего шума в raw_payload.
      const payload: Record<string, string> = {};
      for (const f of form.fields) {
        const v = (values[f.name] ?? "").trim();
        if (v) payload[f.name] = v;
      }
      const result = await publicFetch<{
        ok: boolean;
        thank_you_text: string | null;
        lead_created: boolean;
      }>(`/forms/public/${slug}/submit`, {
        method: "POST",
        body: JSON.stringify(payload),
      });
      setSubmitted({ thank_you_text: result.thank_you_text });
    } catch (e) {
      if (e instanceof ApiError) {
        const detail = (e.detail as { detail?: string })?.detail;
        setSubmitError(detail ?? "Не удалось отправить заявку");
      } else {
        setSubmitError("Ошибка соединения");
      }
    } finally {
      setSubmitting(false);
    }
  }

  function reset() {
    if (!form) return;
    const init: FieldValues = {};
    for (const f of form.fields) init[f.name] = "";
    setValues(init);
    setTouched({});
    setSubmitError(null);
    setSubmitted(null);
  }

  // ===== Render =====
  if (loading) {
    return (
      <div className="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 dark:from-[#0B1220] dark:to-[#0d1a30] flex items-center justify-center px-4">
        <div className="w-full max-w-lg">
          <div className="rounded-2xl bg-white dark:bg-gray-800/80 border border-gray-200 dark:border-white/10 shadow-elev-2 p-8">
            <div className="animate-pulse space-y-4">
              <div className="h-6 bg-gray-200 dark:bg-gray-700 rounded-lg w-3/4" />
              <div className="h-10 bg-gray-100 dark:bg-gray-700/60 rounded-xl" />
              <div className="h-10 bg-gray-100 dark:bg-gray-700/60 rounded-xl" />
              <div className="h-10 bg-gray-100 dark:bg-gray-700/60 rounded-xl w-1/3 ml-auto" />
            </div>
          </div>
        </div>
      </div>
    );
  }

  if (loadError) {
    return (
      <div className="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 dark:from-[#0B1220] dark:to-[#0d1a30] flex items-center justify-center px-4">
        <div className="w-full max-w-lg">
          <div className="rounded-2xl bg-white dark:bg-gray-800/80 border border-gray-200 dark:border-white/10 shadow-elev-2 p-10 text-center">
            <div className="inline-flex items-center justify-center w-14 h-14 rounded-full bg-danger/10 mb-4">
              <i className="bi bi-exclamation-circle text-2xl text-danger" aria-hidden="true" />
            </div>
            <p className="text-base font-semibold text-gray-800 dark:text-gray-100 mb-1">Форма недоступна</p>
            <p className="text-sm text-gray-500 dark:text-gray-400">{loadError}</p>
          </div>
        </div>
      </div>
    );
  }

  if (!form) return null;

  if (submitted) {
    return (
      <div className="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 dark:from-[#0B1220] dark:to-[#0d1a30] flex items-center justify-center px-4">
        <div className="w-full max-w-lg">
          <div className="blur-fade rounded-2xl bg-white dark:bg-gray-800/80 border border-gray-200 dark:border-white/10 shadow-elev-2 p-10 text-center space-y-4">
            <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-success/10 mb-2">
              <i className="bi bi-check-circle-fill text-3xl text-success" aria-hidden="true" />
            </div>
            <div className="text-xl font-semibold text-gray-900 dark:text-gray-100">Заявка отправлена</div>
            {submitted.thank_you_text && (
              <p className="text-sm text-gray-600 dark:text-gray-400 whitespace-pre-wrap max-w-sm mx-auto leading-relaxed">
                {submitted.thank_you_text}
              </p>
            )}
            <button
              className="btn-secondary text-sm mt-2"
              onClick={reset}
            >
              <i className="bi bi-arrow-counterclockwise mr-1" aria-hidden="true" />
              Отправить ещё
            </button>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100 dark:from-[#0B1220] dark:to-[#0d1a30] flex items-start justify-center px-4 py-12">
      <div className="w-full max-w-lg">
        {/* Брендовая шапка */}
        <div className="flex items-center justify-center gap-2 mb-8">
          <div className="h-8 w-8 rounded-lg bg-gradient-to-br from-primary-light to-primary grid place-items-center font-extrabold text-white text-sm shrink-0">M</div>
          <span className="text-sm font-semibold text-gray-500 dark:text-gray-400">MACRO CRM</span>
        </div>

        {/* Карточка формы */}
        <div className="blur-fade rounded-2xl bg-white dark:bg-gray-800/80 border border-gray-200 dark:border-white/10 shadow-elev-2 p-6 md:p-8">
          <h1 className="text-xl font-bold text-gray-900 dark:text-gray-100 mb-6">{form.name}</h1>
          <form onSubmit={onSubmit} className="space-y-5">
            {form.fields.map((f) => {
              const err = touched[f.name] ? fieldError(f) : null;
              return (
                <div key={f.name}>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                    {f.label}{" "}
                    {f.required && <span className="text-danger" aria-hidden="true">*</span>}
                  </label>
                  <FieldInput
                    field={f}
                    value={values[f.name] ?? ""}
                    onChange={(v) => setField(f.name, v)}
                    onBlur={() => setTouched((prev) => ({ ...prev, [f.name]: true }))}
                    invalid={!!err}
                  />
                  {err && (
                    <p className="text-xs text-danger mt-1.5 flex items-center gap-1">
                      <i className="bi bi-exclamation-circle shrink-0" aria-hidden="true" />
                      {err}
                    </p>
                  )}
                </div>
              );
            })}

            {submitError && (
              <div className="flex items-start gap-2 text-sm text-danger bg-danger/8 dark:bg-danger/10 border border-danger/20 px-3.5 py-3 rounded-xl">
                <i className="bi bi-exclamation-triangle shrink-0 mt-0.5" aria-hidden="true" />
                <span>{submitError}</span>
              </div>
            )}

            <button type="submit" className="btn-primary w-full justify-center mt-2" disabled={submitting}>
              {submitting ? (
                <>
                  <i className="bi bi-arrow-clockwise animate-spin mr-1.5" aria-hidden="true" />
                  Отправка…
                </>
              ) : (
                "Отправить"
              )}
            </button>
          </form>
        </div>

        <p className="text-center text-xs text-gray-400 dark:text-gray-500 mt-5">
          Форма создана в MACRO CRM · MACRO Global Technologies
        </p>
      </div>
    </div>
  );
}

function FieldInput({
  field,
  value,
  onChange,
  onBlur,
  invalid,
}: {
  field: FormField;
  value: string;
  onChange: (v: string) => void;
  onBlur: () => void;
  invalid: boolean;
}) {
  const baseCls = [
    "w-full rounded-xl border bg-white dark:bg-gray-900/50 outline-none",
    "px-3.5 py-2.5 text-[15px]",
    "transition-[border-color,box-shadow] duration-150",
    "text-gray-900 dark:text-gray-100",
    "placeholder-gray-400 dark:placeholder-gray-500",
    invalid
      ? "border-danger focus:border-danger focus:ring-4 focus:ring-danger/15"
      : "border-gray-300 dark:border-white/10 focus:border-primary-light focus:ring-4 focus:ring-primary-light/15",
  ].join(" ");

  if (field.type === "textarea") {
    return (
      <textarea
        className={baseCls + " min-h-[100px] resize-y"}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onBlur={onBlur}
      />
    );
  }
  if (field.type === "select") {
    return (
      <select
        className={baseCls}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onBlur={onBlur}
      >
        <option value="">— выберите —</option>
        {(field.options ?? []).map((opt) => (
          <option key={opt} value={opt}>{opt}</option>
        ))}
      </select>
    );
  }
  // text / email / phone
  const htmlType: "text" | "email" | "tel" =
    field.type === "email" ? "email" : field.type === "phone" ? "tel" : "text";
  return (
    <input
      type={htmlType}
      className={baseCls}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      onBlur={onBlur}
      autoComplete={
        field.type === "email" ? "email" : field.type === "phone" ? "tel" : "off"
      }
    />
  );
}
