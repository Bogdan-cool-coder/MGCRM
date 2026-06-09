"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { Field, SelectField } from "@/components/Field";
import { SearchableSelect, type Option } from "@/components/SearchableSelect";
import { CategoryBadge } from "@/components/Templates/CategoryBadge";
import { api, ApiError, fetcher } from "@/lib/api";
import { isTemplateApplicable } from "@/lib/templateMatching";
import type { Company, CountryInfo, ProductInfo, TemplateInfo } from "@/lib/types";

export default function NewContractPage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { data: products } = useSWR<ProductInfo[]>("/templates/products", fetcher);
  const { data: countries } = useSWR<CountryInfo[]>("/templates/countries", fetcher);
  // CONTACTS 2.0 Ф3-A: сторона договора — Company (раздел «Контакты»).
  const { data: companies } = useSWR<Company[]>("/companies?limit=1000", fetcher);
  // Шаблоны (для индикации сколько подходит к выбранной конфигурации).
  // Эндпоинт возвращает 403 для неприоритетных ролей — там SWR молча отдаст error,
  // блок применимых шаблонов просто не отрендерится.
  const { data: allTemplates } = useSWR<TemplateInfo[]>("/templates", fetcher);

  const [productCode, setProductCode] = useState("");
  const [countryCode, setCountryCode] = useState("");
  const [city, setCity] = useState("");
  // Хранит id выбранной Company (передаётся как company_id).
  const [companyId, setCompanyId] = useState<string>("");
  const [title, setTitle] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Wave 5: страна/город подставлены из карточки компании — показываем подсказку.
  const [geoPrefilled, setGeoPrefilled] = useState(false);

  // CONTACTS 2.0 Ф3-A: предвыбор стороны из URL. Поддерживаем company_id (новый)
  // и counterparty/counterparty_id (legacy, резолвим в company через зеркало).
  useEffect(() => {
    if (!companies || companyId) return;
    const directCompany = searchParams.get("company_id");
    if (directCompany && companies.some((c) => c.id === Number(directCompany))) {
      setCompanyId(directCompany);
      return;
    }
    const legacyCp = searchParams.get("counterparty") ?? searchParams.get("counterparty_id");
    if (legacyCp) {
      const mirror = companies.find((c) => c.counterparty_id === Number(legacyCp));
      if (mirror) setCompanyId(String(mirror.id));
    }
  }, [companies, companyId, searchParams]);

  // Wave 5: предзаполнение страны/города из карточки выбранной компании.
  // Срабатывает один раз, когда company резолвится из ?company_id=.
  // Не перезаписывает уже введённые вручную значения.
  useEffect(() => {
    if (!companyId || !companies || !countries) return;
    const co = companies.find((c) => c.id === Number(companyId));
    if (!co) return;
    let touched = false;
    const cc = (co.country_code ?? co.country ?? "").toLowerCase();
    if (!countryCode && cc && countries.some((c) => c.code === cc)) {
      setCountryCode(cc);
      touched = true;
    }
    if (!city && co.city) {
      setCity(co.city);
      touched = true;
    }
    if (touched) setGeoPrefilled(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companyId, companies, countries]);

  const country = countries?.find((c) => c.code === countryCode);

  const productOpts = (products ?? []).map(p => ({ value: p.code, label: p.name }));
  const countryOpts = (countries ?? []).map(c => ({ value: c.code, label: `${c.name_short} (${c.code.toUpperCase()})` }));

  // Применимые DOCX-шаблоны для выбранной конфигурации (Эпик 3, isTemplateApplicable).
  // YAML-конфиги (product_*/country_*) — это не самостоятельные документы, отфильтровываем.
  const selectedCompany = companies?.find((c) => c.id === Number(companyId));
  const applicableTemplates = useMemo<TemplateInfo[]>(() => {
    if (!allTemplates) return [];
    const docx = allTemplates.filter(
      (t) => !t.code.startsWith("product_") && !t.code.startsWith("country_"),
    );
    if (!productCode && !countryCode && !selectedCompany) return docx;
    return docx.filter((t) =>
      isTemplateApplicable(t, {
        product_code: productCode || null,
        country_code: countryCode || null,
        client_category_code: selectedCompany?.category_code ?? null,
      }),
    );
  }, [allTemplates, productCode, countryCode, selectedCompany]);

  // Фильтр по стране: матчим country_code (Ф0-дубль) либо country (ISO), без регистра.
  const companyMatchesCountry = (c: Company) => {
    if (!countryCode) return true;
    const cc = (c.country_code ?? c.country ?? "").toLowerCase();
    return cc === countryCode.toLowerCase();
  };
  const filteredCompanies = companies?.filter(companyMatchesCountry) ?? [];
  const companyOptions: Option[] = filteredCompanies.map((c) => {
    const display = c.name || c.short_name || c.legal_name;
    const lf = c.legal_form ?? "";
    return {
      value: String(c.id),
      label: `${lf} «${display}»`.trim(),
      hint: c.tax_id ? `${c.tax_id_label ?? "ИНН"} ${c.tax_id}` : undefined,
    };
  });

  function isValid() {
    return !!(productCode && countryCode && (city || country?.default_city));
  }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      const co = companies?.find((c) => c.id === Number(companyId));
      // BUG-2: если пришли из сделки (?deal_id=Y), привязываем созданный договор
      // к сделке на бэке (Deal.contract_id) — тогда win-gate увидит документ.
      const dealIdParam = searchParams.get("deal_id");
      const dealId = dealIdParam && /^\d+$/.test(dealIdParam) ? Number(dealIdParam) : null;
      const created = await api<{ id: number }>("/contracts", {
        method: "POST",
        body: {
          product_code: productCode,
          country_code: countryCode,
          city: city || country?.default_city || "",
          // CONTACTS 2.0 Ф3-A: передаём company_id (counterparty_id зеркалируется на бэке).
          company_id: companyId ? Number(companyId) : null,
          title: title || co?.name || co?.legal_name || null,
          deal_id: dealId,
          context: {},
        },
      });
      router.push(`/contracts/${created.id}`);
    } catch (err) {
      const detail = err instanceof ApiError ? (err.detail as { detail?: string })?.detail : null;
      setError(detail ?? "Не удалось создать договор");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <PageHeader title="Новый документ" description="Выберите продукт, страну и контрагента" />
      <div className="p-8 max-w-3xl">
        <form onSubmit={onSubmit} className="card p-6 space-y-4">
          <SelectField
            label="Продукт"
            value={productCode}
            onChange={setProductCode}
            options={productOpts}
            required
            placeholder="— выберите —"
          />
          <SelectField
            label="Страна"
            value={countryCode}
            onChange={(v) => {
              setCountryCode(v);
              const c = countries?.find((x) => x.code === v);
              if (c && !city) setCity(c.default_city);
            }}
            options={countryOpts}
            required
            placeholder="— выберите —"
            hint={country ? `Лицензиар: ${country.licensor?.legal_form} ${country.licensor?.name}. Валюта: ${country.currency_name}` : undefined}
          />
          <Field
            label="Город (для номера договора)"
            value={city}
            onChange={setCity}
            required
            placeholder={country?.default_city ?? "Город"}
            hint={`Номер сформируется как 3 буквы города + порядковый № + /${countryCode.toUpperCase() || "??"} (пример: ТШК-220/UZ)`}
          />
          {geoPrefilled && (
            <div className="text-xs text-info bg-info/10 px-3 py-2 rounded flex items-start gap-2">
              <i className="bi bi-info-circle shrink-0 mt-0.5" />
              Страна и город подставлены из карточки компании. Можно изменить.
            </div>
          )}
          <SearchableSelect
            label="Контрагент (Сублицензиат)"
            value={companyId}
            onChange={setCompanyId}
            options={companyOptions}
            placeholder="Начните вводить название…"
            hint="При выборе реквизиты автоматически подставятся в карточку договора. Если контрагента ещё нет — создайте в разделе «Контакты»."
          />
          <Field
            label="Заголовок (опционально)"
            value={title}
            onChange={setTitle}
            placeholder="Например: ЗастройщикPlus, MacroCRM"
            hint="Виден в реестре. Если пусто — возьмём название компании."
          />
          {error && <div className="text-danger text-sm bg-danger/10 px-3 py-2 rounded">{error}</div>}
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" className="btn-secondary" onClick={() => router.back()}>Отмена</button>
            <button type="submit" disabled={submitting || !isValid()} className="btn-primary">
              {submitting ? "Создание…" : "Создать"}
            </button>
          </div>
        </form>

        {/* Применимые шаблоны (Эпик 3): показываем, какие документы подойдут после создания. */}
        {allTemplates && (productCode || countryCode || companyId) && (
          <div className="mt-6 card p-5">
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-sm font-semibold text-primary">
                Применимые шаблоны{" "}
                <span className="text-gray-400 font-normal">({applicableTemplates.length})</span>
              </h3>
              {applicableTemplates.length === 0 && (
                <div className="text-xs text-warning font-medium">
                  <i className="bi bi-exclamation-triangle" /> Нет подходящих шаблонов
                </div>
              )}
            </div>
            {applicableTemplates.length > 0 ? (
              <ul className="space-y-2 text-sm">
                {applicableTemplates.map((t) => (
                  <li key={t.id} className="flex items-center gap-3">
                    <CategoryBadge category={t.category} />
                    <span className="text-gray-800">{t.title}</span>
                    <span className="text-xs text-gray-400 font-mono ml-auto">{t.code}</span>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-gray-600">
                Под выбранную конфигурацию (продукт / страна / категория клиента) не настроено ни одного
                шаблона. Документ всё равно создастся, но генерация будет недоступна — проверьте
                привязки шаблонов в разделе «Шаблоны документов».
              </p>
            )}
          </div>
        )}
      </div>
    </>
  );
}
