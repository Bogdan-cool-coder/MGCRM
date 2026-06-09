"use client";

import { useMemo } from "react";
import Link from "next/link";
import useSWR from "swr";
import { InlineField, type SelectOption } from "./InlineField";
import { StagePill } from "./StagePill";
import { fetcher } from "@/lib/api";
import { formatCurrency } from "@/lib/format";
import type {
  Company,
  CompanyType,
  DealCardConfig,
  DealOut,
  Pipeline,
  PipelineStage,
} from "@/lib/types";

interface DealMainInfoProps {
  deal: DealOut;
  stages: PipelineStage[];
  pipelines: Pipeline[];
  config: DealCardConfig | undefined;
  /** Поля, обязательные для ожидающего перехода, но пустые (подсветка красным). */
  missingFields: Set<string>;
  /** Есть ли позиции-продукты — тогда «Сумма» read-only (авто-сумма). */
  hasProducts: boolean;
  onMove: (stageId: number) => void;
  /** PATCH /deals/{id} — один ключ. Бросает при ошибке. */
  patchDeal: (body: Record<string, unknown>) => Promise<void>;
  /** PATCH /companies/{company_id}. Бросает при ошибке. */
  patchCompany: (body: Record<string, unknown>) => Promise<void>;
}

const CURRENCY_OPTIONS: SelectOption[] = [
  { value: "KZT", label: "KZT" },
  { value: "RUB", label: "RUB" },
  { value: "USD", label: "USD" },
  { value: "UZS", label: "UZS" },
  { value: "EUR", label: "EUR" },
];

function fmtDate(s: string | null): string {
  return s ? new Date(s).toLocaleDateString("ru-RU") : "";
}

// Порядок и видимость полей из конфига карточки. Если конфига нет — дефолт.
function visibleFieldOrder(config: DealCardConfig | undefined): string[] {
  if (!config || config.deal_card_fields.length === 0) {
    return [
      "amount", "currency", "owner_user_id", "expected_close_date",
      "expected_sign_date", "expected_payment_date", "product", "tags", "contacts",
    ];
  }
  return [...config.deal_card_fields]
    .filter((f) => f.visible)
    .sort((a, b) => a.order - b.order)
    .map((f) => f.field);
}

export function DealMainInfo({
  deal,
  stages,
  pipelines,
  config,
  missingFields,
  hasProducts,
  onMove,
  patchDeal,
  patchCompany,
}: DealMainInfoProps) {
  const { data: companyTypes } = useSWR<CompanyType[]>("/admin/company-types", fetcher);
  const { data: company } = useSWR<Company>(
    deal.company_id ? `/companies/${deal.company_id}` : null,
    fetcher
  );

  const pipelineOptions: SelectOption[] = useMemo(
    () => pipelines.filter((p) => p.kind !== "lifecycle").map((p) => ({ value: String(p.id), label: p.name })),
    [pipelines]
  );

  const companyTypeOptions: SelectOption[] = useMemo(
    () => [
      { value: "", label: "— не задан —" },
      ...(companyTypes ?? []).map((t) => ({ value: String(t.id), label: t.name })),
    ],
    [companyTypes]
  );

  const order = useMemo(() => visibleFieldOrder(config), [config]);
  // contacts рендерится отдельной секцией (DealContactsCard), не как inline-поле.
  const inlineOrder = order.filter((f) => f !== "contacts");

  function renderField(field: string) {
    switch (field) {
      case "amount":
        return (
          <InlineField
            key="amount"
            label="Сумма"
            kind="number"
            value={deal.amount != null ? String(deal.amount) : ""}
            display={<span className="font-semibold tabular-nums">{formatCurrency(deal.amount, deal.currency)}</span>}
            placeholder="0"
            readOnly={hasProducts}
            readOnlyHint="Сумма считается автоматически из позиций-продуктов"
            missing={missingFields.has("amount")}
            onSave={(v) => patchDeal({ amount: v ? Number(v) : null })}
          />
        );
      case "currency":
        return (
          <InlineField
            key="currency"
            label="Валюта"
            kind="select"
            options={CURRENCY_OPTIONS}
            value={deal.currency ?? "KZT"}
            display={<span>{deal.currency ?? "—"}</span>}
            missing={missingFields.has("currency")}
            onSave={(v) => patchDeal({ currency: v })}
          />
        );
      case "owner_user_id":
        return (
          <InlineField
            key="owner_user_id"
            label="Ответственный"
            kind="user"
            value={deal.owner_user_id != null ? String(deal.owner_user_id) : ""}
            display={<OwnerDisplay ownerId={deal.owner_user_id} />}
            missing={missingFields.has("owner_user_id")}
            onSave={(v) => patchDeal({ owner_user_id: v ? Number(v) : null })}
          />
        );
      case "expected_close_date":
        return (
          <InlineField
            key="expected_close_date"
            label="Ожидаемое закрытие"
            kind="date"
            value={deal.expected_close_date ?? ""}
            display={<span>{fmtDate(deal.expected_close_date) || <span className="text-gray-400 italic">—</span>}</span>}
            missing={missingFields.has("expected_close_date")}
            onSave={(v) => patchDeal({ expected_close_date: v || null })}
          />
        );
      case "expected_sign_date":
        return (
          <InlineField
            key="expected_sign_date"
            label="Ожидаемое подписание"
            kind="date"
            value={deal.expected_sign_date ?? ""}
            display={<span>{fmtDate(deal.expected_sign_date) || <span className="text-gray-400 italic">—</span>}</span>}
            missing={missingFields.has("expected_sign_date")}
            onSave={(v) => patchDeal({ expected_sign_date: v || null })}
          />
        );
      case "expected_payment_date":
        return (
          <InlineField
            key="expected_payment_date"
            label="Ожидаемая оплата"
            kind="date"
            value={deal.expected_payment_date ?? ""}
            display={<span>{fmtDate(deal.expected_payment_date) || <span className="text-gray-400 italic">—</span>}</span>}
            missing={missingFields.has("expected_payment_date")}
            onSave={(v) => patchDeal({ expected_payment_date: v || null })}
          />
        );
      case "product":
        return (
          <InlineField
            key="product"
            label="Продукт (текст)"
            kind="text"
            value={deal.product ?? ""}
            placeholder="—"
            display={
              deal.product
                ? <span className="text-xs px-1.5 py-0.5 rounded bg-info/10 text-primary dark:text-blue-300">{deal.product}</span>
                : <span className="text-gray-400 italic">—</span>
            }
            missing={missingFields.has("product")}
            onSave={(v) => patchDeal({ product: v.trim() || null })}
          />
        );
      case "tags":
        return (
          <InlineField
            key="tags"
            label="Теги"
            kind="tags"
            value=""
            tags={deal.tags}
            display={
              deal.tags.length > 0 ? (
                <span className="flex flex-wrap gap-1">
                  {deal.tags.map((t) => (
                    <span key={t} className="px-1.5 py-0.5 text-xs rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400">{t}</span>
                  ))}
                </span>
              ) : <span className="text-gray-400 italic">—</span>
            }
            missing={missingFields.has("tags")}
            onSave={(raw) => patchDeal({ tags: JSON.parse(raw) as string[] })}
          />
        );
      default:
        return null;
    }
  }

  const currentType = company?.company_type_id;

  return (
    <div className="p-5">
      <div className="flex items-center justify-between mb-3 gap-2 flex-wrap">
        <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">Основная информация</h3>
        <StagePill stages={stages} currentStageId={deal.stage_id} onSelect={onMove} />
      </div>

      <div>
        {/* Воронка */}
        <InlineField
          label="Воронка"
          kind="select"
          options={pipelineOptions}
          value={String(deal.pipeline_id)}
          display={<span>{pipelines.find((p) => p.id === deal.pipeline_id)?.name ?? `#${deal.pipeline_id}`}</span>}
          onSave={(v) => patchDeal({ pipeline_id: Number(v) })}
        />

        {/* Конфигурируемые поля по порядку */}
        {inlineOrder.map((f) => renderField(f))}

        {/* Тип компании (на связанной Company) */}
        {deal.company_id != null && (
          <InlineField
            label="Тип компании"
            kind="select"
            options={companyTypeOptions}
            value={currentType != null ? String(currentType) : ""}
            display={
              <span>
                {companyTypes?.find((t) => t.id === currentType)?.name ?? <span className="text-gray-400 italic">—</span>}
              </span>
            }
            onSave={(v) => patchCompany({ company_type_id: v ? Number(v) : null })}
          />
        )}

        {/* Компания (read-only ссылка) */}
        <div className="flex gap-2 py-1.5 border-b border-gray-100 dark:border-gray-800 last:border-0">
          <span className="w-44 shrink-0 text-xs text-gray-500 dark:text-gray-400 pt-1.5">Компания</span>
          <div className="flex-1 min-w-0 text-sm pt-1.5">
            {deal.company_id ? (
              <Link href={`/companies/${deal.company_id}`} className="text-primary hover:underline">
                {company?.name ?? company?.legal_name ?? "Открыть карточку компании"}
              </Link>
            ) : (
              <span className="text-gray-400 italic">—</span>
            )}
          </div>
        </div>

        {/* Причина отказа (если есть) */}
        {deal.lost_reason && (
          <div className="flex gap-2 py-1.5 border-b border-gray-100 dark:border-gray-800 last:border-0">
            <span className="w-44 shrink-0 text-xs text-gray-500 dark:text-gray-400 pt-1.5">Причина отказа</span>
            <span className="flex-1 text-sm text-danger pt-1.5">{deal.lost_reason}</span>
          </div>
        )}
      </div>
    </div>
  );
}

function OwnerDisplay({ ownerId }: { ownerId: number | null }) {
  const { data: users } = useSWR<{ id: number; full_name: string }[]>("/users", fetcher);
  if (ownerId == null) return <span className="text-gray-400 italic">—</span>;
  const u = users?.find((x) => x.id === ownerId);
  return <span>{u?.full_name ?? `Пользователь #${ownerId}`}</span>;
}
