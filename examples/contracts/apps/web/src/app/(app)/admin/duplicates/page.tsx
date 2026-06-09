"use client";

import { useState } from "react";
import useSWR, { mutate as globalMutate } from "swr";
import { PageHeader } from "@/components/PageHeader";
import { EmptyState } from "@/components/EmptyState";
import { DuplicateGroupCard } from "@/components/Duplicates/DuplicateGroupCard";
import { useToast } from "@/components/ui/Toast";
import { fetcher } from "@/lib/api";
import {
  DUPLICATE_ENTITY_LABELS,
  type DuplicateEntityType,
  type DuplicateGroup,
  type DuplicateScanResponse,
} from "@/lib/types";
import { formatDateTime } from "@/lib/dates";

const ENTITY_TABS: DuplicateEntityType[] = ["counterparty", "contact", "company", "lead"];

function swrKey(entity: DuplicateEntityType) {
  return `/duplicates/scan?entity=${entity}`;
}

export default function DuplicatesPage() {
  const [activeEntity, setActiveEntity] = useState<DuplicateEntityType>("counterparty");
  const [scanning, setScanning] = useState(false);
  const { toast } = useToast();

  const key = swrKey(activeEntity);
  const { data, isLoading, error } = useSWR<DuplicateScanResponse>(key, fetcher);

  const groups = data?.groups ?? [];
  const scannedAt = data?.scanned_at;

  async function handleScan() {
    setScanning(true);
    try {
      await globalMutate(key);
    } catch {
      toast.error("Не удалось запустить сканирование");
    } finally {
      setScanning(false);
    }
  }

  function handleGroupUpdated() {
    globalMutate(key);
  }

  return (
    <div>
      <PageHeader
        title="Дубликаты"
        description="Найди и объедини повторяющиеся записи"
        actions={
          <button
            onClick={handleScan}
            className="btn-secondary text-sm"
            disabled={scanning || isLoading}
          >
            <i className={`bi bi-arrow-repeat mr-1 ${scanning ? "animate-spin" : ""}`} />
            {scanning ? "Сканируем…" : "Сканировать"}
          </button>
        }
      />

      <div className="p-8 space-y-4">
        {/* Дата сканирования */}
        <div className="text-xs text-gray-500 dark:text-gray-400 min-h-[1rem]">
          {scannedAt
            ? `Последнее сканирование: ${formatDateTime(scannedAt)}`
            : !isLoading ? "Сканирование ещё не запускалось" : ""}
        </div>

        {/* Карточка с вкладками */}
        <div className="card rounded-2xl shadow-elev-1 p-0 overflow-hidden">
          {/* Вкладки */}
          <div className="flex border-b border-gray-200 dark:border-gray-700 overflow-x-auto">
            {ENTITY_TABS.map((entity) => (
              <button
                key={entity}
                onClick={() => setActiveEntity(entity)}
                className={[
                  "px-4 py-3 text-sm font-medium whitespace-nowrap border-b-2 transition-colors",
                  activeEntity === entity
                    ? "border-primary text-primary"
                    : "border-transparent text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 hover:bg-gray-50 dark:hover:bg-gray-800/50",
                ].join(" ")}
              >
                {DUPLICATE_ENTITY_LABELS[entity]}
              </button>
            ))}
          </div>

          {/* Ошибка */}
          {error && (
            <div className="m-4 text-sm text-danger bg-danger/10 px-3 py-2 rounded">
              Не удалось загрузить группы дублей
            </div>
          )}

          {/* Skeleton */}
          {isLoading && (
            <div className="p-4 space-y-3 animate-pulse">
              {[1, 2, 3].map((i) => (
                <div key={i} className="h-[100px] bg-gray-100 dark:bg-gray-800 rounded-lg" />
              ))}
            </div>
          )}

          {/* Нет сканирования */}
          {!isLoading && !error && !scannedAt && (
            <EmptyState
              icon="bi-search"
              title="Сканирование ещё не запускалось"
              description="Запусти сканирование, чтобы найти возможные дубликаты"
              cta={
                <button onClick={handleScan} disabled={scanning} className="btn-secondary">
                  <i className={`bi bi-arrow-repeat mr-1 ${scanning ? "animate-spin" : ""}`} />
                  Сканировать
                </button>
              }
            />
          )}

          {/* Дублей нет */}
          {!isLoading && !error && scannedAt && groups.length === 0 && (
            <EmptyState
              icon="bi-check-circle"
              title="Дублей не обнаружено"
              description="Все записи уникальны. Запусти сканирование ещё раз, если добавлял новые данные"
              cta={
                <button onClick={handleScan} disabled={scanning} className="btn-secondary">
                  <i className={`bi bi-arrow-repeat mr-1 ${scanning ? "animate-spin" : ""}`} />
                  Сканировать повторно
                </button>
              }
            />
          )}

          {/* Список групп */}
          {!isLoading && groups.length > 0 && (
            <div className="p-4 space-y-3">
              {groups.map((group: DuplicateGroup, i) => (
                <DuplicateGroupCard
                  key={group.id}
                  group={group}
                  index={i + 1}
                  total={groups.length}
                  onMerged={handleGroupUpdated}
                  onNotDuplicate={handleGroupUpdated}
                />
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
